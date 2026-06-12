<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\AnaHeatmap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manuálne exporty heatmáp z Clarity UI (Heatmaps → Download PNG/CSV).
 * API heatmapy neposkytuje, preto sa nahrávajú sem a aplikácia ich
 * páruje B2C vs B2B podľa označenia stránky.
 */
class HeatmapController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('analytics/Heatmaps', [
            'heatmaps' => AnaHeatmap::orderBy('page_label')->orderBy('segment')->orderBy('type')->get()
                ->map(fn (AnaHeatmap $h) => [
                    'id' => $h->id,
                    'segment' => $h->segment,
                    'page_label' => $h->page_label,
                    'type' => $h->type,
                    'device' => $h->device,
                    'period_label' => $h->period_label,
                    'notes' => $h->notes,
                    'csv_data' => $h->csv_data,
                    'png_url' => $h->png_path ? '/storage/'.$h->png_path : null, // relatívna cesta — APP_URL nemusí sedieť s portom
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'segment' => 'required|in:b2c,b2b',
            'page_label' => 'required|string|max:100',
            'type' => 'required|in:click,scroll,area',
            'device' => 'nullable|string|max:20',
            'period_label' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:500',
            'csv' => 'nullable|file|mimes:csv,txt|max:5120',
            'png' => 'nullable|file|mimes:png,jpg,jpeg,webp|max:10240',
        ]);

        if (! $request->hasFile('csv') && ! $request->hasFile('png')) {
            return back()->withErrors(['csv' => 'Nahraj aspoň jeden súbor (CSV alebo PNG).']);
        }

        $pngPath = $request->hasFile('png')
            ? $request->file('png')->store('heatmaps', 'public')
            : null;

        AnaHeatmap::create([
            'segment' => $data['segment'],
            'page_label' => trim($data['page_label']),
            'type' => $data['type'],
            'device' => $data['device'] ?? null,
            'period_label' => $data['period_label'] ?? null,
            'notes' => $data['notes'] ?? null,
            'csv_data' => $request->hasFile('csv') ? $this->parseCsv($request->file('csv')->getRealPath()) : null,
            'png_path' => $pngPath,
        ]);

        return back();
    }

    public function destroy(AnaHeatmap $heatmap): RedirectResponse
    {
        if ($heatmap->png_path) {
            Storage::disk('public')->delete($heatmap->png_path);
        }
        $heatmap->delete();

        return back();
    }

    /** Tolerantný parser Clarity CSV exportu: preambulu uloží ako metadáta. */
    public function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $rows = [];
        while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            $row = array_map(fn ($v) => trim((string) $v, " \t\"\u{FEFF}"), $row);
            if (implode('', $row) === '') {
                continue;
            }
            $rows[] = $row;
            if (count($rows) >= 1001) {
                break;
            }
        }
        fclose($handle);

        if ($rows === []) {
            return ['headers' => [], 'rows' => [], 'meta' => []];
        }

        // za tabuľku považujeme súvislý blok riadkov s najčastejším počtom stĺpcov
        $counts = array_count_values(array_map('count', $rows));
        arsort($counts);
        $width = (int) array_key_first($counts);
        $table = array_values(array_filter($rows, fn ($r) => count($r) === $width));

        // preambula Clarity exportu: dvojstĺpcové riadky kľúč → hodnota
        // (Project name, Date range, Page views, Total clicks, Metric…)
        $meta = [];
        foreach ($rows as $row) {
            if (count($row) === 2 && $width !== 2 && $row[0] !== '' && mb_strlen($row[0]) < 60) {
                $meta[$row[0]] = $row[1];
            }
        }

        return [
            'headers' => $table[0] ?? [],
            'rows' => array_slice($table, 1, 500),
            'meta' => $meta,
        ];
    }
}
