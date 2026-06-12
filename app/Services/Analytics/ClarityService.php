<?php

namespace App\Services\Analytics;

use App\Models\AnaClaritySnapshot;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klient pre Microsoft Clarity Data Export API.
 *
 * API vracia agregované metriky len za posledné 1–3 dni a má limit
 * 10 requestov na projekt a deň. Jeden snapshot = 2 requesty
 * (rozpad podľa zariadení + podľa URL); výsledok sa archivuje do
 * ana_clarity_snapshots a dashboard číta výhradne z DB.
 */
class ClarityService
{
    protected const ENDPOINT = 'https://www.clarity.ms/export-data/api/v1/project-live-insights';

    public function captureSnapshot(string $segment, int $numOfDays = 3): AnaClaritySnapshot
    {
        try {
            $snapshot = $this->doCapture($segment, $numOfDays);
        } catch (\Throwable $e) {
            // log behu aj pri zlyhaní — kontrola automatizácie na /clarity
            \App\Models\AnaSnapshotRun::create([
                'segment' => $segment,
                'status' => 'failed',
                'message' => mb_substr($e->getMessage(), 0, 1000),
                'ran_at' => now(),
            ]);

            throw $e;
        }

        \App\Models\AnaSnapshotRun::create([
            'segment' => $segment,
            'status' => 'success',
            'sessions' => $snapshot->sessions,
            'ran_at' => now(),
        ]);

        return $snapshot;
    }

    protected function doCapture(string $segment, int $numOfDays): AnaClaritySnapshot
    {
        $token = config("analytics.clarity.{$segment}_token");
        if (! $token) {
            throw new RuntimeException('Chýba CLARITY_'.strtoupper($segment).'_TOKEN v .env');
        }

        $byDevice = $this->request($token, ['numOfDays' => $numOfDays, 'dimension1' => 'Device']);
        $byUrl = $this->request($token, ['numOfDays' => $numOfDays, 'dimension1' => 'URL']);

        $metrics = $this->parse($byDevice, $byUrl);

        return AnaClaritySnapshot::updateOrCreate(
            ['segment' => $segment, 'captured_on' => now()->toDateString(), 'num_days' => $numOfDays],
            [...$metrics, 'raw' => ['by_device' => $byDevice, 'by_url' => $byUrl]],
        );
    }

    protected function request(string $token, array $query): array
    {
        $response = Http::withToken($token)->acceptJson()->timeout(30)->get(self::ENDPOINT, $query);

        if ($response->status() === 429) {
            throw new RuntimeException('Clarity API: vyčerpaný denný limit requestov (10/projekt).');
        }
        if (! $response->successful()) {
            throw new RuntimeException("Clarity API HTTP {$response->status()}: ".mb_substr($response->body(), 0, 200));
        }

        return $response->json() ?? [];
    }

    protected function parse(array $byDevice, array $byUrl): array
    {
        $out = [
            'sessions' => 0, 'bot_sessions' => 0, 'users' => 0,
            'pages_per_session' => null, 'engagement_avg_seconds' => null,
            'active_avg_seconds' => null, 'scroll_depth' => null,
            'dead_clicks' => 0, 'rage_clicks' => 0, 'quick_backs' => 0,
            'excessive_scrolls' => 0, 'script_errors' => 0, 'error_clicks' => 0,
            'devices' => [], 'top_pages' => [],
        ];

        $metricsByName = collect($byDevice)->keyBy('metricName');

        // Traffic: počty relácií/používateľov per zariadenie + váhy pre priemery
        $deviceSessions = [];
        $weightedPages = 0.0;
        foreach ($metricsByName->get('Traffic')['information'] ?? [] as $row) {
            $sessions = (int) ($row['totalSessionCount'] ?? 0);
            $device = $row['Device'] ?? 'Iné';
            $deviceSessions[$device] = $sessions;
            $out['sessions'] += $sessions;
            $out['bot_sessions'] += (int) ($row['totalBotSessionCount'] ?? 0);
            $out['users'] += (int) ($row['distinctUserCount'] ?? 0);
            $weightedPages += $sessions * (float) ($row['pagesPerSessionPercentage'] ?? 0);
            $out['devices'][] = ['device' => $device, 'sessions' => $sessions];
        }
        usort($out['devices'], fn ($a, $b) => $b['sessions'] <=> $a['sessions']);

        $weightedAvg = function (string $metricName, string $field) use ($metricsByName, $deviceSessions): ?float {
            $sum = 0.0;
            $weight = 0;
            foreach ($metricsByName->get($metricName)['information'] ?? [] as $row) {
                $sessions = $deviceSessions[$row['Device'] ?? ''] ?? 0;
                $sum += $sessions * (float) ($row[$field] ?? 0);
                $weight += $sessions;
            }

            return $weight > 0 ? round($sum / $weight, 2) : null;
        };

        if ($out['sessions'] > 0) {
            $out['pages_per_session'] = round($weightedPages / $out['sessions'], 2);
        }
        // totalTime/activeTime sú priemerné sekundy na reláciu per zariadenie
        $out['engagement_avg_seconds'] = $weightedAvg('EngagementTime', 'totalTime');
        $out['active_avg_seconds'] = $weightedAvg('EngagementTime', 'activeTime');
        $out['scroll_depth'] = $weightedAvg('ScrollDepth', 'averageScrollDepth');

        foreach ([
            'DeadClickCount' => 'dead_clicks',
            'RageClickCount' => 'rage_clicks',
            'QuickbackClick' => 'quick_backs',
            'ExcessiveScroll' => 'excessive_scrolls',
            'ScriptErrorCount' => 'script_errors',
            'ErrorClickCount' => 'error_clicks',
        ] as $metricName => $key) {
            foreach ($metricsByName->get($metricName)['information'] ?? [] as $row) {
                $out[$key] += (int) ($row['subTotal'] ?? 0);
            }
        }

        // Top stránky z rozpadu Traffic podľa URL
        $pages = [];
        foreach (collect($byUrl)->keyBy('metricName')->get('Traffic')['information'] ?? [] as $row) {
            $url = $row['URL'] ?? $row['Url'] ?? $row['url'] ?? null;
            if ($url !== null) {
                $pages[$url] = ($pages[$url] ?? 0) + (int) ($row['totalSessionCount'] ?? 0);
            }
        }
        arsort($pages);
        $out['top_pages'] = collect($pages)->take(10)
            ->map(fn ($visits, $url) => ['url' => $url, 'visits' => $visits])
            ->values()->all();

        return $out;
    }
}
