<?php

namespace App\Services\Analytics;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RfmService
{
    public function analyze(): array
    {
        $out = ['segments' => [], 'cohorts' => []];

        foreach (['b2c', 'b2b'] as $segment) {
            $rfm = $this->rfmPerCustomer($segment);
            $out['segments'][$segment] = [
                'label' => config("analytics.segments.$segment.label"),
                'rfm_distribution' => $this->rfmDistribution($rfm),
                'frequency_distribution' => $this->frequencyDistribution($segment),
                'interpurchase_days' => $this->interpurchaseDays($segment),
                'customers' => $rfm->count(),
            ];
            $out['cohorts'][$segment] = $this->cohortRetention($segment);
        }

        return $out;
    }

    /** RFM hodnoty na zákazníka: recency (dni), frequency (objednávky), monetary (tržby). */
    protected function rfmPerCustomer(string $segment): Collection
    {
        return DB::table('ana_orders')
            ->where('segment', $segment)->where('is_cancelled', false)
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, DATEDIFF(NOW(), MAX(ordered_at)) recency, COUNT(*) frequency, SUM(total_net) monetary')
            ->groupBy('customer_id')
            ->get();
    }

    /** Kvintilové RFM skóre a rozdelenie zákazníkov do segmentov. */
    protected function rfmDistribution(Collection $rfm): array
    {
        if ($rfm->isEmpty()) {
            return [];
        }

        $rScore = $this->quintileScores($rfm->pluck('recency')->all(), descending: true); // nižšia recency = lepšie
        $fScore = $this->quintileScores($rfm->pluck('frequency')->all());
        $mScore = $this->quintileScores($rfm->pluck('monetary')->all());

        $map = config('analytics.rfm.segments_map');
        $counts = array_fill_keys(array_keys($map), 0);
        $monetary = array_fill_keys(array_keys($map), 0.0);

        foreach ($rfm->values() as $i => $row) {
            $r = $rScore[$i];
            $f = $fScore[$i];
            foreach ($map as $key => $def) {
                if ($r >= $def['r'][0] && $r <= $def['r'][1] && $f >= $def['f'][0] && $f <= $def['f'][1]) {
                    $counts[$key]++;
                    $monetary[$key] += (float) $row->monetary;
                    break;
                }
            }
        }

        $result = [];
        foreach ($map as $key => $def) {
            $result[] = [
                'key' => $key,
                'label' => $def['label'],
                'customers' => $counts[$key],
                'share' => round(100 * $counts[$key] / max(1, $rfm->count()), 1),
                'revenue' => round($monetary[$key], 2),
            ];
        }

        return $result;
    }

    /** @return int[] kvintilové skóre 1–5 v poradí vstupných hodnôt */
    protected function quintileScores(array $values, bool $descending = false): array
    {
        $n = count($values);
        asort($values);
        $scores = [];
        $rank = 0;
        foreach (array_keys($values) as $origIndex) {
            $quintile = min(5, intdiv($rank * 5, $n) + 1);
            $scores[$origIndex] = $descending ? 6 - $quintile : $quintile;
            $rank++;
        }
        ksort($scores);

        return array_values($scores);
    }

    protected function frequencyDistribution(string $segment): array
    {
        $rows = DB::table('ana_orders')
            ->where('segment', $segment)->where('is_cancelled', false)
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, COUNT(*) c')
            ->groupBy('customer_id')
            ->pluck('c');

        $buckets = ['1' => 0, '2' => 0, '3–5' => 0, '6–10' => 0, '11–25' => 0, '26+' => 0];
        foreach ($rows as $c) {
            $key = match (true) {
                $c == 1 => '1',
                $c == 2 => '2',
                $c <= 5 => '3–5',
                $c <= 10 => '6–10',
                $c <= 25 => '11–25',
                default => '26+',
            };
            $buckets[$key]++;
        }

        $total = max(1, $rows->count());

        return [
            'labels' => array_keys($buckets),
            'counts' => array_values($buckets),
            'shares' => array_map(fn ($c) => round(100 * $c / $total, 1), array_values($buckets)),
        ];
    }

    /** Priemerný a mediánový počet dní medzi nákupmi zákazníka. */
    protected function interpurchaseDays(string $segment): array
    {
        $row = DB::table('ana_orders')
            ->where('segment', $segment)->where('is_cancelled', false)
            ->whereNotNull('customer_id')
            ->selectRaw('customer_id, DATEDIFF(MAX(ordered_at), MIN(ordered_at)) span, COUNT(*) c')
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $intervals = $row->map(fn ($r) => $r->span / ($r->c - 1))->filter(fn ($v) => $v > 0)->values();
        if ($intervals->isEmpty()) {
            return ['avg' => null, 'median' => null];
        }

        $sorted = $intervals->sort()->values();
        $mid = intdiv($sorted->count(), 2);

        return [
            'avg' => round($intervals->avg(), 1),
            'median' => round($sorted->count() % 2 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2, 1),
        ];
    }

    /** Kvartálne kohorty: podiel zákazníkov kohorty aktívnych v nasledujúcich kvartáloch. */
    protected function cohortRetention(string $segment): array
    {
        $orders = DB::table('ana_orders')
            ->where('segment', $segment)->where('is_cancelled', false)
            ->whereNotNull('customer_id')
            ->selectRaw("customer_id, CONCAT(YEAR(ordered_at), '-Q', QUARTER(ordered_at)) yq, MIN(ordered_at) first_in_q")
            ->groupBy('customer_id', 'yq')
            ->get();

        $firstQuarter = [];
        foreach ($orders->sortBy('yq') as $row) {
            $firstQuarter[$row->customer_id] ??= $row->yq;
        }

        $quarters = $orders->pluck('yq')->unique()->sort()->values()->all();
        $quarterIndex = array_flip($quarters);

        $matrix = [];
        foreach ($quarters as $cohort) {
            $matrix[$cohort] = ['cohort' => $cohort, 'size' => 0, 'retention' => array_fill(0, count($quarters), null)];
        }
        foreach ($firstQuarter as $customerId => $cohort) {
            $matrix[$cohort]['size']++;
        }

        $activeByQuarter = [];
        foreach ($orders as $row) {
            $activeByQuarter[$row->yq][$row->customer_id] = true;
        }

        foreach ($matrix as $cohort => &$data) {
            if ($data['size'] === 0) {
                continue;
            }
            $cohortCustomers = array_keys(array_filter($firstQuarter, fn ($q) => $q === $cohort));
            $startIdx = $quarterIndex[$cohort];
            foreach ($quarters as $idx => $quarter) {
                if ($idx < $startIdx) {
                    continue;
                }
                $active = 0;
                foreach ($cohortCustomers as $cid) {
                    if (isset($activeByQuarter[$quarter][$cid])) {
                        $active++;
                    }
                }
                $data['retention'][$idx - $startIdx] = round(100 * $active / $data['size'], 1);
            }
        }

        return ['quarters' => $quarters, 'rows' => array_values($matrix)];
    }
}
