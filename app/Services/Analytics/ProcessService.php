<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * Nákupný proces: platby, doprava, zľavy, rozhodovací proces (B2B schvaľovanie),
 * kanál (web/app pri B2C) a opustené košíky.
 */
class ProcessService
{
    public function __construct(protected StatsService $stats)
    {
    }

    public function analyze(): array
    {
        $out = [
            'segments' => [],
            'approval' => $this->approvalStats(),
            'channel_b2c' => $this->channelB2c(),
            'decision_speed' => $this->decisionSpeed(),
            'payment_chi2' => $this->paymentChiSquare(),
        ];

        foreach (['b2c', 'b2b'] as $segment) {
            $out['segments'][$segment] = [
                'label' => config("analytics.segments.$segment.label"),
                'payment_methods' => $this->methodDistribution($segment, 'payment_method'),
                'shipping_methods' => $this->methodDistribution($segment, 'shipping_method'),
                'discount_usage' => $this->discountUsage($segment),
                'status_distribution' => $this->statusDistribution($segment),
                'cart_snapshot' => $this->cartSnapshot($segment),
            ];
        }

        return $out;
    }

    protected function methodDistribution(string $segment, string $column, int $limit = 8): array
    {
        $rows = DB::table('ana_orders')
            ->where('segment', $segment)->where('is_cancelled', false)
            ->whereNotNull($column)->where($column, '!=', '')
            ->selectRaw("$column method, COUNT(*) c")
            ->groupBy('method')
            ->orderByDesc('c')
            ->get();

        $total = max(1, $rows->sum('c'));
        $top = $rows->take($limit);
        $rest = $rows->slice($limit)->sum('c');

        $result = $top->map(fn ($r) => [
            'method' => $r->method,
            'count' => (int) $r->c,
            'share' => round(100 * $r->c / $total, 1),
        ])->values()->all();

        if ($rest > 0) {
            $result[] = ['method' => 'Ostatné', 'count' => (int) $rest, 'share' => round(100 * $rest / $total, 1)];
        }

        return $result;
    }

    protected function discountUsage(string $segment): array
    {
        $base = DB::table('ana_orders')->where('segment', $segment)->where('is_cancelled', false);
        $total = max(1, (clone $base)->count());
        $withDiscount = (clone $base)->where('discount', '>', 0)->count();
        $avgDiscount = (clone $base)->where('discount', '>', 0)->avg('discount');

        return [
            'share' => round(100 * $withDiscount / $total, 1),
            'avg_discount' => $avgDiscount ? round((float) $avgDiscount, 2) : null,
        ];
    }

    protected function statusDistribution(string $segment): array
    {
        return DB::table('ana_orders')
            ->where('segment', $segment)
            ->selectRaw('status_name, COUNT(*) c')
            ->groupBy('status_name')
            ->orderByDesc('c')
            ->get()
            ->map(fn ($r) => ['status' => $r->status_name, 'count' => (int) $r->c])
            ->all();
    }

    protected function cartSnapshot(string $segment): array
    {
        return [
            'items' => DB::table('ana_cart_items')->where('segment', $segment)->count(),
            'customers' => DB::table('ana_cart_items')->where('segment', $segment)
                ->whereNotNull('customer_source_id')->distinct()->count('customer_source_id'),
        ];
    }

    /** B2B rozhodovací proces: dĺžka schvaľovania a výsledky workflow. */
    protected function approvalStats(): array
    {
        $hours = DB::table('ana_orders')
            ->where('segment', 'b2b')->whereNotNull('approval_hours')
            ->where('approval_hours', '>', 0)
            ->pluck('approval_hours')
            ->map(fn ($v) => (float) $v)
            ->all();

        $buckets = ['do 1 h' => 0, '1–4 h' => 0, '4–24 h' => 0, '1–3 dni' => 0, '3–7 dní' => 0, 'nad 7 dní' => 0];
        foreach ($hours as $h) {
            $key = match (true) {
                $h <= 1 => 'do 1 h',
                $h <= 4 => '1–4 h',
                $h <= 24 => '4–24 h',
                $h <= 72 => '1–3 dni',
                $h <= 168 => '3–7 dní',
                default => 'nad 7 dní',
            };
            $buckets[$key]++;
        }

        $rejected = DB::table('ana_orders')
            ->where('segment', 'b2b')
            ->where('status_name', 'like', '%amietnut%')
            ->count();
        $pendingApproval = DB::table('ana_orders')
            ->where('segment', 'b2b')
            ->where('status_name', 'like', '%chválenie%')
            ->count();
        $totalB2b = max(1, DB::table('ana_orders')->where('segment', 'b2b')->count());

        // roly v nákupnom centre
        $roles = DB::table('ana_customers')->where('segment', 'b2b')->selectRaw('
            SUM(is_approver) approvers,
            SUM(is_decision_maker) decision_makers,
            SUM(is_influencer) influencers,
            COUNT(*) total
        ')->first();

        return [
            'orders_with_approval' => count($hours),
            'avg_hours' => $hours ? round(array_sum($hours) / count($hours), 1) : null,
            'median_hours' => $hours ? round($this->stats->median($hours), 1) : null,
            'distribution' => ['labels' => array_keys($buckets), 'counts' => array_values($buckets)],
            'rejected_count' => $rejected,
            'rejected_share' => round(100 * $rejected / $totalB2b, 2),
            'pending_count' => $pendingApproval,
            'roles' => [
                'approvers' => (int) ($roles->approvers ?? 0),
                'decision_makers' => (int) ($roles->decision_makers ?? 0),
                'influencers' => (int) ($roles->influencers ?? 0),
                'total' => (int) ($roles->total ?? 0),
            ],
        ];
    }

    /**
     * Rýchlosť rozhodovania: dni od registrácie po prvý nákup (len zákazníci
     * registrovaní v sledovanom období) a dni medzi prvou a druhou objednávkou.
     */
    protected function decisionSpeed(): array
    {
        $out = [];
        $since = now()->subMonths(config('analytics.period_months'));

        foreach (['b2c', 'b2b'] as $segment) {
            $regToFirst = DB::table('ana_orders as o')
                ->join('ana_customers as c', 'c.id', '=', 'o.customer_id')
                ->where('o.segment', $segment)->where('o.is_cancelled', false)
                ->whereNotNull('c.registered_at')->where('c.registered_at', '>=', $since)
                ->selectRaw('c.id, DATEDIFF(MIN(o.ordered_at), c.registered_at) days')
                ->groupBy('c.id', 'c.registered_at')
                ->havingRaw('days >= 0')
                ->pluck('days')->map(fn ($v) => (float) $v)->all();

            $firstToSecond = collect(DB::select('
                SELECT DATEDIFF(second_at, first_at) days FROM (
                    SELECT ordered_at first_at,
                        LEAD(ordered_at) OVER (PARTITION BY customer_id ORDER BY ordered_at) second_at,
                        ROW_NUMBER() OVER (PARTITION BY customer_id ORDER BY ordered_at) rn
                    FROM ana_orders
                    WHERE segment = ? AND is_cancelled = 0 AND customer_id IS NOT NULL
                ) t WHERE rn = 1 AND second_at IS NOT NULL
            ', [$segment]))->pluck('days')->map(fn ($v) => (float) $v)->all();

            $out[$segment] = [
                'reg_to_first_median' => $regToFirst ? round($this->stats->median($regToFirst), 1) : null,
                'reg_to_first_avg' => $regToFirst ? round(array_sum($regToFirst) / count($regToFirst), 1) : null,
                'reg_to_first_n' => count($regToFirst),
                'first_to_second_median' => $firstToSecond ? round($this->stats->median($firstToSecond), 1) : null,
                'first_to_second_n' => count($firstToSecond),
            ];
        }

        return $out;
    }

    /** Chi-kvadrát test: závisí rozloženie platobných metód od segmentu? */
    protected function paymentChiSquare(): ?array
    {
        $rows = DB::table('ana_orders')
            ->where('is_cancelled', false)
            ->whereNotNull('payment_method')->where('payment_method', '!=', '')
            ->selectRaw('segment, payment_method, COUNT(*) c')
            ->groupBy('segment', 'payment_method')
            ->get();

        $methods = $rows->pluck('payment_method')->unique()->values();
        $table = [];
        foreach (['b2c', 'b2b'] as $segment) {
            $bySegment = $rows->where('segment', $segment)->keyBy('payment_method');
            $table[] = $methods->map(fn ($m) => (int) ($bySegment[$m]->c ?? 0))->all();
        }

        return $this->stats->chiSquare($table);
    }

    /** B2C kanál nákupu: web vs mobilná aplikácia. */
    protected function channelB2c(): array
    {
        $rows = DB::table('ana_orders')
            ->where('segment', 'b2c')->where('is_cancelled', false)
            ->selectRaw('channel, COUNT(*) c, AVG(total_net) aov')
            ->groupBy('channel')
            ->get();

        $total = max(1, $rows->sum('c'));

        return $rows->map(fn ($r) => [
            'channel' => $r->channel === 'app' ? 'Mobilná aplikácia' : 'Web',
            'count' => (int) $r->c,
            'share' => round(100 * $r->c / $total, 1),
            'aov' => round((float) $r->aov, 2),
        ])->values()->all();
    }
}
