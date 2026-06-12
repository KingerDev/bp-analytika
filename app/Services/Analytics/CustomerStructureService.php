<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * Štruktúra zákazníkov a koncentrácia tržieb.
 *
 * B2B sa analyzuje na úrovni organizácií (jedna firma má často viac
 * používateľských účtov), B2C na úrovni zákazníkov.
 */
class CustomerStructureService
{
    public function __construct(protected StatsService $stats)
    {
    }

    public function analyze(): array
    {
        return [
            'public_sector' => $this->publicSectorComparison(),
            'public_sector_seasonality' => $this->publicSectorSeasonality(),
            'organizations' => $this->organizations(),
            'concentration' => [
                'b2c' => $this->concentration('b2c'),
                'b2b' => $this->concentration('b2b'),
            ],
        ];
    }

    /** Porovnanie verejnej správy a súkromných firiem v B2B. */
    protected function publicSectorComparison(): array
    {
        $rows = DB::table('ana_orders as o')
            ->join('ana_customers as c', 'c.id', '=', 'o.customer_id')
            ->where('o.segment', 'b2b')->where('o.is_cancelled', false)
            ->selectRaw('
                c.is_public_sector,
                COUNT(DISTINCT c.org_source_id) orgs,
                COUNT(DISTINCT c.id) customers,
                COUNT(*) orders,
                SUM(o.total_net) revenue,
                AVG(o.total_net) aov,
                AVG(o.approval_hours) avg_approval_hours,
                AVG(o.items_count) avg_items
            ')
            ->groupBy('c.is_public_sector')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $key = $row->is_public_sector ? 'public' : 'private';
            $medianApproval = DB::table('ana_orders as o')
                ->join('ana_customers as c', 'c.id', '=', 'o.customer_id')
                ->where('o.segment', 'b2b')->where('c.is_public_sector', $row->is_public_sector)
                ->whereNotNull('o.approval_hours')->where('o.approval_hours', '>', 0)
                ->pluck('o.approval_hours')->map(fn ($v) => (float) $v)->all();

            $out[$key] = [
                'label' => $row->is_public_sector ? 'Verejná správa' : 'Súkromné firmy',
                'orgs' => (int) $row->orgs,
                'customers' => (int) $row->customers,
                'orders' => (int) $row->orders,
                'revenue' => round((float) $row->revenue, 2),
                'aov' => round((float) $row->aov, 2),
                'avg_items' => round((float) $row->avg_items, 1),
                'median_approval_hours' => $medianApproval ? round($this->stats->median($medianApproval), 1) : null,
            ];
        }

        // Mann-Whitney na rozdiel hodnôt objednávok verejná správa vs firmy
        $values = fn (int $isPublic) => DB::table('ana_orders as o')
            ->join('ana_customers as c', 'c.id', '=', 'o.customer_id')
            ->where('o.segment', 'b2b')->where('o.is_cancelled', false)
            ->where('c.is_public_sector', $isPublic)->where('o.total_net', '>', 0)
            ->pluck('o.total_net')->map(fn ($v) => (float) $v)->all();

        $test = $this->stats->mannWhitney($values(1), $values(0));
        $out['aov_test'] = $test ? [...$test, 'p_formatted' => $this->stats->formatP($test['p']), 'significant' => $test['p'] < 0.05] : null;

        return $out;
    }

    /** Sezónnosť objednávok verejnej správy vs firiem (rozpočtové cykly). */
    protected function publicSectorSeasonality(): array
    {
        $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'Máj', 'Jún', 'Júl', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'];
        $series = [];
        foreach ([1 => 'public', 0 => 'private'] as $flag => $key) {
            $rows = DB::table('ana_orders as o')
                ->join('ana_customers as c', 'c.id', '=', 'o.customer_id')
                ->where('o.segment', 'b2b')->where('o.is_cancelled', false)
                ->where('c.is_public_sector', $flag)
                ->selectRaw('MONTH(o.ordered_at) m, COUNT(*) c')
                ->groupBy('m')->pluck('c', 'm');
            $total = max(1, $rows->sum());
            $series[$key] = array_map(fn ($m) => round(100 * ($rows[$m] ?? 0) / $total, 2), range(1, 12));
        }

        return ['labels' => $labels, 'series' => $series];
    }

    /** Organizácie v B2B: počty účtov na organizáciu (nákupné centrum), top organizácie. */
    protected function organizations(): array
    {
        $orgUsers = DB::table('ana_customers')
            ->where('segment', 'b2b')->whereNotNull('org_source_id')
            ->selectRaw('org_source_id, COUNT(*) users')
            ->groupBy('org_source_id')
            ->pluck('users');

        $buckets = ['1 účet' => 0, '2–3 účty' => 0, '4–10 účtov' => 0, '11+ účtov' => 0];
        foreach ($orgUsers as $users) {
            $key = match (true) {
                $users == 1 => '1 účet',
                $users <= 3 => '2–3 účty',
                $users <= 10 => '4–10 účtov',
                default => '11+ účtov',
            };
            $buckets[$key]++;
        }

        $top = DB::table('ana_orders as o')
            ->join('ana_customers as c', 'c.id', '=', 'o.customer_id')
            ->where('o.segment', 'b2b')->where('o.is_cancelled', false)
            ->whereNotNull('c.org_source_id')
            ->selectRaw('c.org_source_id, MAX(c.is_public_sector) is_public, COUNT(DISTINCT c.id) users, COUNT(*) orders, SUM(o.total_net) revenue')
            ->groupBy('c.org_source_id')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->map(fn ($r, $i) => [
                'code' => sprintf('ORG-%03d', $i + 1), // anonymizované poradové označenie
                'type' => $r->is_public ? 'verejná správa' : 'firma',
                'users' => (int) $r->users,
                'orders' => (int) $r->orders,
                'revenue' => round((float) $r->revenue, 2),
            ])->values()->all();

        return [
            'total_orgs' => $orgUsers->count(),
            'avg_users_per_org' => round($orgUsers->avg() ?? 0, 2),
            'multi_user_share' => $orgUsers->count() ? round(100 * $orgUsers->filter(fn ($u) => $u > 1)->count() / $orgUsers->count(), 1) : 0,
            'buckets' => ['labels' => array_keys($buckets), 'counts' => array_values($buckets)],
            'top' => $top,
        ];
    }

    /**
     * Koncentrácia tržieb: Lorenzova krivka, Gini, ABC analýza.
     * Jednotka: B2C zákazník, B2B organizácia.
     */
    protected function concentration(string $segment): array
    {
        $unitColumn = $segment === 'b2b' ? 'c.org_source_id' : 'o.customer_id';

        $revenues = DB::table('ana_orders as o')
            ->leftJoin('ana_customers as c', 'c.id', '=', 'o.customer_id')
            ->where('o.segment', $segment)->where('o.is_cancelled', false)
            ->whereNotNull(DB::raw($unitColumn))
            ->selectRaw("$unitColumn unit, SUM(o.total_net) revenue")
            ->groupBy('unit')
            ->orderByDesc('revenue')
            ->pluck('revenue')
            ->map(fn ($v) => (float) $v)
            ->all();

        $n = count($revenues);
        $total = array_sum($revenues);
        if ($n === 0 || $total <= 0) {
            return ['unit_label' => '', 'units' => 0];
        }

        // Lorenzova krivka (zostupne): top x % jednotiek → y % tržieb, 5 % kroky
        $lorenz = [['x' => 0, 'y' => 0]];
        $cum = 0.0;
        $step = max(1, (int) ceil($n / 20));
        foreach ($revenues as $i => $revenue) {
            $cum += $revenue;
            if (($i + 1) % $step === 0 || $i === $n - 1) {
                $lorenz[] = ['x' => round(100 * ($i + 1) / $n, 1), 'y' => round(100 * $cum / $total, 1)];
            }
        }

        // ABC: A = jednotky tvoriace 80 % tržieb, B do 95 %, C zvyšok
        $abc = ['A' => 0, 'B' => 0, 'C' => 0];
        $cum = 0.0;
        foreach ($revenues as $revenue) {
            $cum += $revenue;
            $share = $cum / $total;
            $abc[$share <= 0.80 ? 'A' : ($share <= 0.95 ? 'B' : 'C')]++;
        }

        // podiel top 20 % jednotiek na tržbách
        $top20count = max(1, (int) round(0.2 * $n));
        $top20share = round(100 * array_sum(array_slice($revenues, 0, $top20count)) / $total, 1);

        return [
            'unit_label' => $segment === 'b2b' ? 'organizácie' : 'zákazníci',
            'units' => $n,
            'gini' => $this->stats->gini($revenues),
            'top20_share' => $top20share,
            'lorenz' => $lorenz,
            'abc' => [
                'counts' => $abc,
                'shares' => array_map(fn ($c) => round(100 * $c / $n, 1), $abc),
            ],
        ];
    }
}
