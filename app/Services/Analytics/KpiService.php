<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;

class KpiService
{
    public function __construct(protected StatsService $stats)
    {
    }

    public function overview(): array
    {
        $out = [
            'segments' => [],
            'monthly' => $this->monthlyTrend(),
            'monthly_margin' => $this->monthlyMargin(),
            'aov_test' => null,
            'aov_histogram' => $this->aovHistogram(),
        ];

        foreach (['b2c', 'b2b'] as $segment) {
            $base = DB::table('ana_orders')->where('segment', $segment);

            $row = (clone $base)->where('is_cancelled', false)->selectRaw('
                COUNT(*) orders,
                COALESCE(SUM(total_net),0) revenue,
                COALESCE(AVG(total_net),0) aov,
                COALESCE(AVG(items_count),0) avg_items,
                COALESCE(AVG(units_count),0) avg_units,
                COUNT(DISTINCT customer_id) customers
            ')->first();

            // marža len relatívne (% z tržieb) — absolútny zisk sa do frontendu neposiela
            $margin = (clone $base)->where('is_cancelled', false)->whereNotNull('profit_net')
                ->selectRaw('100 * SUM(profit_net) / NULLIF(SUM(total_net), 0) pct')->value('pct');

            $cancelled = (clone $base)->where('is_cancelled', true)->count();
            $total = $row->orders + $cancelled;

            $repeatCustomers = DB::table('ana_orders')
                ->where('segment', $segment)->where('is_cancelled', false)
                ->whereNotNull('customer_id')
                ->select('customer_id')
                ->groupBy('customer_id')
                ->havingRaw('COUNT(*) > 1')
                ->get()
                ->count();

            $medianAov = $this->stats->median(
                DB::table('ana_orders')->where('segment', $segment)->where('is_cancelled', false)
                    ->where('total_net', '>', 0)->pluck('total_net')->map(fn ($v) => (float) $v)->all()
            );

            $out['segments'][$segment] = [
                'label' => config("analytics.segments.$segment.label"),
                'orders' => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
                'aov' => round((float) $row->aov, 2),
                'median_aov' => round($medianAov, 2),
                'avg_items' => round((float) $row->avg_items, 2),
                'avg_units' => round((float) $row->avg_units, 2),
                'margin_pct' => $margin !== null ? round((float) $margin, 1) : null,
                'customers' => (int) $row->customers,
                'orders_per_customer' => $row->customers ? round($row->orders / $row->customers, 2) : 0,
                'repeat_rate' => $row->customers ? round(100 * $repeatCustomers / $row->customers, 1) : 0,
                'cancel_rate' => $total ? round(100 * $cancelled / $total, 1) : 0,
            ];
        }

        $out['aov_test'] = $this->aovTest();

        return $out;
    }

    /** Mann-Whitneyho U test rozdielu hodnôt objednávok medzi segmentmi. */
    protected function aovTest(): ?array
    {
        $values = fn (string $segment) => DB::table('ana_orders')
            ->where('segment', $segment)->where('is_cancelled', false)->where('total_net', '>', 0)
            ->pluck('total_net')->map(fn ($v) => (float) $v)->all();

        $result = $this->stats->mannWhitney($values('b2c'), $values('b2b'));
        if (! $result) {
            return null;
        }

        return [
            ...$result,
            'p_formatted' => $this->stats->formatP($result['p']),
            'significant' => $result['p'] < 0.05,
        ];
    }

    protected function monthlyTrend(): array
    {
        $rows = DB::table('ana_orders')
            ->where('is_cancelled', false)
            ->selectRaw("segment, DATE_FORMAT(ordered_at, '%Y-%m') ym, COUNT(*) orders, SUM(total_net) revenue")
            ->groupBy('segment', 'ym')->orderBy('ym')
            ->get();

        $months = $rows->pluck('ym')->unique()->sort()->values()->all();
        $series = [];
        foreach (['b2c', 'b2b'] as $segment) {
            $bySegment = $rows->where('segment', $segment)->keyBy('ym');
            $series[$segment] = [
                'orders' => array_map(fn ($m) => (int) ($bySegment[$m]->orders ?? 0), $months),
                'revenue' => array_map(fn ($m) => round((float) ($bySegment[$m]->revenue ?? 0), 2), $months),
            ];
        }

        return ['months' => $months, 'series' => $series];
    }

    /** Mesačný vývoj marže (podiel zisku na tržbách v %) — len relatívne hodnoty. */
    protected function monthlyMargin(): array
    {
        $rows = DB::table('ana_orders')
            ->where('is_cancelled', false)->whereNotNull('profit_net')
            ->selectRaw("segment, DATE_FORMAT(ordered_at, '%Y-%m') ym, 100 * SUM(profit_net) / NULLIF(SUM(total_net), 0) pct")
            ->groupBy('segment', 'ym')->orderBy('ym')
            ->get();

        $months = $rows->pluck('ym')->unique()->sort()->values()->all();
        $series = [];
        foreach (['b2c', 'b2b'] as $segment) {
            $bySegment = $rows->where('segment', $segment)->keyBy('ym');
            $series[$segment] = array_map(
                fn ($m) => isset($bySegment[$m]) ? round((float) $bySegment[$m]->pct, 1) : null,
                $months
            );
        }

        return ['months' => $months, 'series' => $series];
    }

    /** Rozdelenie hodnôt objednávok do pásiem (porovnanie tvaru distribúcie). */
    protected function aovHistogram(): array
    {
        $buckets = [0, 10, 20, 50, 100, 200, 500, 1000, 2000];
        $labels = [];
        foreach ($buckets as $i => $from) {
            $labels[] = isset($buckets[$i + 1]) ? "{$from}–{$buckets[$i + 1]} €" : "{$from}+ €";
        }

        $series = [];
        foreach (['b2c', 'b2b'] as $segment) {
            $values = DB::table('ana_orders')
                ->where('segment', $segment)->where('is_cancelled', false)->where('total_net', '>', 0)
                ->pluck('total_net');
            $counts = array_fill(0, count($buckets), 0);
            foreach ($values as $v) {
                $idx = 0;
                foreach ($buckets as $i => $from) {
                    if ($v >= $from) {
                        $idx = $i;
                    }
                }
                $counts[$idx]++;
            }
            $totalCount = max(1, $values->count());
            $series[$segment] = array_map(fn ($c) => round(100 * $c / $totalCount, 1), $counts);
        }

        return ['labels' => $labels, 'series' => $series];
    }
}
