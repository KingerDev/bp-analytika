<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;

class ProductService
{
    public function analyze(): array
    {
        $out = ['segments' => []];

        foreach (['b2c', 'b2b'] as $segment) {
            $out['segments'][$segment] = [
                'label' => config("analytics.segments.$segment.label"),
                'top_categories' => $this->topCategories($segment),
                'top_products' => $this->topProducts($segment),
                'assortment_breadth' => $this->assortmentBreadth($segment),
                'avg_line_quantity' => $this->avgLineQuantity($segment),
                'gift_share' => $this->giftShare($segment),
            ];
        }

        return $out;
    }

    protected function topCategories(string $segment, int $limit = 10): array
    {
        $rows = DB::table('ana_order_items as i')
            ->join('ana_orders as o', 'o.id', '=', 'i.order_id')
            ->leftJoin('ana_products as p', 'p.id', '=', 'i.product_id')
            ->where('i.segment', $segment)->where('o.is_cancelled', false)
            ->selectRaw("COALESCE(p.category_name, 'Nezaradené') category, SUM(i.total_net) revenue, SUM(i.quantity) units, COUNT(DISTINCT o.id) orders")
            ->groupBy('category')
            ->orderByDesc('revenue')
            ->get();

        $totalRevenue = max(0.01, $rows->sum('revenue'));

        return $rows->take($limit)->map(fn ($r) => [
            'category' => $r->category,
            'revenue' => round((float) $r->revenue, 2),
            'share' => round(100 * $r->revenue / $totalRevenue, 1),
            'units' => (int) $r->units,
            'orders' => (int) $r->orders,
        ])->values()->all();
    }

    protected function topProducts(string $segment, int $limit = 15): array
    {
        return DB::table('ana_order_items as i')
            ->join('ana_orders as o', 'o.id', '=', 'i.order_id')
            ->join('ana_products as p', 'p.id', '=', 'i.product_id')
            ->where('i.segment', $segment)->where('o.is_cancelled', false)
            ->selectRaw('p.name, p.category_name, SUM(i.quantity) units, SUM(i.total_net) revenue, COUNT(DISTINCT o.id) orders')
            ->groupBy('p.id', 'p.name', 'p.category_name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'name' => $r->name,
                'category' => $r->category_name,
                'units' => (int) $r->units,
                'revenue' => round((float) $r->revenue, 2),
                'orders' => (int) $r->orders,
            ])->all();
    }

    /** Šírka sortimentu: priemerný počet rôznych koreňových kategórií na zákazníka. */
    protected function assortmentBreadth(string $segment): ?float
    {
        $value = DB::table('ana_order_items as i')
            ->join('ana_orders as o', 'o.id', '=', 'i.order_id')
            ->join('ana_products as p', 'p.id', '=', 'i.product_id')
            ->where('i.segment', $segment)->where('o.is_cancelled', false)
            ->whereNotNull('o.customer_id')->whereNotNull('p.category_name')
            ->selectRaw('o.customer_id, COUNT(DISTINCT p.category_name) breadth')
            ->groupBy('o.customer_id')
            ->get()
            ->avg('breadth');

        return $value ? round($value, 2) : null;
    }

    /** Priemerný počet kusov na riadok objednávky (množstevné nákupy B2B). */
    protected function avgLineQuantity(string $segment): float
    {
        return round((float) DB::table('ana_order_items as i')
            ->join('ana_orders as o', 'o.id', '=', 'i.order_id')
            ->where('i.segment', $segment)->where('o.is_cancelled', false)
            ->avg('i.quantity'), 2);
    }

    /** Podiel darčekových/bonusových položiek na riadkoch objednávok. */
    protected function giftShare(string $segment): float
    {
        $base = DB::table('ana_order_items')->where('segment', $segment);
        $total = max(1, (clone $base)->count());

        return round(100 * (clone $base)->where('is_gift', true)->count() / $total, 2);
    }
}
