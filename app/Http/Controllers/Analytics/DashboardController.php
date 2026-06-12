<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Models\AnaClaritySnapshot;
use App\Services\Analytics\CustomerStructureService;
use App\Services\Analytics\KpiService;
use App\Services\Analytics\SummaryService;
use App\Services\Analytics\ProcessService;
use App\Services\Analytics\ProductService;
use App\Services\Analytics\RfmService;
use App\Services\Analytics\TimePatternService;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function overview(KpiService $kpi): Response
    {
        return Inertia::render('analytics/Overview', [
            'data' => $kpi->overview(),
            'meta' => $this->meta(),
        ]);
    }

    public function rfm(RfmService $rfm): Response
    {
        return Inertia::render('analytics/Rfm', [
            'data' => $rfm->analyze(),
            'meta' => $this->meta(),
        ]);
    }

    public function time(TimePatternService $time): Response
    {
        return Inertia::render('analytics/Time', [
            'data' => $time->analyze(),
            'meta' => $this->meta(),
        ]);
    }

    public function products(ProductService $products): Response
    {
        return Inertia::render('analytics/Products', [
            'data' => $products->analyze(),
            'meta' => $this->meta(),
        ]);
    }

    public function process(ProcessService $process): Response
    {
        return Inertia::render('analytics/Process', [
            'data' => $process->analyze(),
            'meta' => $this->meta(),
        ]);
    }

    public function customers(CustomerStructureService $structure): Response
    {
        return Inertia::render('analytics/Customers', [
            'data' => $structure->analyze(),
            'meta' => $this->meta(),
        ]);
    }

    public function summary(SummaryService $summary): Response
    {
        return Inertia::render('analytics/Summary', [
            'sections' => $summary->build(),
            'meta' => $this->meta(),
        ]);
    }

    public function clarity(): Response
    {
        // raw stĺpec sa nenačítava — JSON payloady sú veľké (MySQL sort buffer)
        $columns = [
            'id', 'segment', 'captured_on', 'num_days', 'sessions', 'bot_sessions', 'users',
            'pages_per_session', 'engagement_avg_seconds', 'active_avg_seconds', 'scroll_depth',
            'dead_clicks', 'rage_clicks', 'quick_backs', 'excessive_scrolls', 'script_errors',
            'error_clicks', 'devices', 'top_pages',
        ];

        $latest = [];
        foreach (['b2c', 'b2b'] as $segment) {
            $latest[$segment] = AnaClaritySnapshot::select($columns)
                ->where('segment', $segment)
                ->orderByDesc('captured_on')
                ->first();
        }

        $history = AnaClaritySnapshot::select([
            'segment', 'captured_on', 'sessions', 'users', 'pages_per_session',
            'active_avg_seconds', 'rage_clicks', 'dead_clicks', 'script_errors',
        ])->orderBy('captured_on')->get();

        // monitoring automatizácie: posledné behy + kontrola čerstvosti
        $runs = \App\Models\AnaSnapshotRun::orderByDesc('ran_at')->limit(30)->get()
            ->map(fn ($r) => [
                'segment' => $r->segment,
                'status' => $r->status,
                'sessions' => $r->sessions,
                'message' => $r->message,
                'ran_at' => $r->ran_at->format('d.m.Y H:i'),
            ]);

        $health = [];
        foreach (['b2c', 'b2b'] as $segment) {
            $lastSuccess = \App\Models\AnaSnapshotRun::where('segment', $segment)
                ->where('status', 'success')->max('ran_at');
            $daysAgo = $lastSuccess ? now()->diffInDays($lastSuccess, true) : null;
            $health[$segment] = [
                'last_success' => $lastSuccess ? substr($lastSuccess, 0, 16) : null,
                // API vidí len 3 dni dozadu — po 2 dňoch bez snapshotu hrozí diera v dátach
                'stale' => $lastSuccess === null || $daysAgo > 2,
            ];
        }

        return Inertia::render('analytics/Clarity', [
            'meta' => $this->meta(),
            'latest' => $latest,
            'history' => $history,
            'runs' => $runs,
            'health' => $health,
            'configured' => [
                'b2c' => (bool) config('analytics.clarity.b2c_token'),
                'b2b' => (bool) config('analytics.clarity.b2b_token'),
            ],
        ]);
    }

    /** Metadáta o rozsahu dát pre pätičku každej stránky (metodika BP). */
    protected function meta(): array
    {
        $range = DB::table('ana_orders')
            ->selectRaw('MIN(ordered_at) min_d, MAX(ordered_at) max_d, MAX(updated_at) imported_at')
            ->first();

        return [
            'period_months' => config('analytics.period_months'),
            'date_from' => $range->min_d ? substr($range->min_d, 0, 10) : null,
            'date_to' => $range->max_d ? substr($range->max_d, 0, 10) : null,
            'imported_at' => $range->imported_at ? substr($range->imported_at, 0, 16) : null,
        ];
    }
}
