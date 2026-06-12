<?php

namespace App\Services\Etl;

use App\Models\AnaCartItem;
use App\Models\AnaCustomer;
use App\Models\AnaOrder;
use App\Models\AnaOrderItem;
use App\Models\AnaProduct;
use Illuminate\Support\Facades\DB;

/**
 * ETL import maloobchodného segmentu (titi e-shop) do zjednotenej anonymizovanej schémy.
 *
 * Zdroj: pripojenie src_titi (produkčná DB cez SSH tunel, prípadne lokálna titi-dev).
 * Osobné údaje (mená, e-maily, telefóny, IP) sa NEimportujú — zákazník dostane anonymný kód.
 */
class B2cImporter
{
    protected string $segment = 'b2c';
    protected string $connection = 'src_titi';
    protected string $prefix = 'titi_';

    /** @var callable|null */
    protected $progress;

    /** @var int[] zdrojové ID testovacích zákazníkov, ktorí sa do analýzy neimportujú */
    protected array $excludedCustomerIds = [];

    public function run(?callable $progress = null): array
    {
        $this->progress = $progress;
        $this->excludedCustomerIds = config('analytics.segments.'.$this->segment.'.excluded_customer_source_ids', []);
        $since = now()->subMonths(config('analytics.period_months'))->startOfDay();

        $this->purge();

        $stats = [];
        $stats['customers'] = $this->importCustomers($since);
        $stats['products'] = $this->importProducts();
        $stats['orders'] = $this->importOrders($since);
        $stats['order_items'] = $this->importOrderItems();
        $stats['cart_items'] = $this->importCartItems();

        return $stats;
    }

    protected function src()
    {
        return DB::connection($this->connection);
    }

    protected function note(string $msg): void
    {
        if ($this->progress) {
            ($this->progress)($msg);
        }
    }

    /** Zmaže predchádzajúci import segmentu, aby bol výsledok plne reprodukovateľný. */
    protected function purge(): void
    {
        $this->note('Čistenie predchádzajúceho importu…');
        AnaOrder::where('segment', $this->segment)->delete(); // položky zmaže DB kaskáda
        AnaCartItem::where('segment', $this->segment)->delete();
        AnaCustomer::where('segment', $this->segment)->delete();
        AnaProduct::where('segment', $this->segment)->delete();
    }

    protected function importCustomers(\DateTimeInterface $since): int
    {
        $this->note('Import zákazníkov…');
        $count = 0;

        // importujeme len zákazníkov, ktorí majú objednávku v sledovanom období
        $this->src()->table($this->prefix.'customer as c')
            ->join($this->prefix.'order as o', 'o.customer_id', '=', 'c.customer_id')
            ->where('o.date_added', '>=', $since)
            ->whereNotIn('c.customer_id', $this->excludedCustomerIds)
            ->select('c.customer_id', 'c.date_added', 'c.points', 'c.guest')
            ->distinct()
            ->orderBy('c.customer_id')
            ->chunk(500, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    AnaCustomer::updateOrCreate(
                        ['segment' => $this->segment, 'source_id' => $row->customer_id],
                        [
                            'code' => sprintf('B2C-%06d', $row->customer_id),
                            'registered_at' => $this->safeDate($row->date_added),
                            'loyalty_points' => (int) $row->points,
                        ]
                    );
                    $count++;
                }
            });

        return $count;
    }

    protected function importProducts(): int
    {
        $this->note('Import produktov a kategórií…');

        $rootNames = $this->rootCategoryNames();
        $productRoot = $this->productRootCategoryMap($rootNames);
        $count = 0;

        // len produkty, ktoré sa vyskytujú v objednávkach
        $this->src()->table($this->prefix.'order_product as op')
            ->leftJoin($this->prefix.'product_description as pd', function ($join) {
                $join->on('pd.product_id', '=', 'op.product_id')->where('pd.language_id', '=', 2);
            })
            ->select('op.product_id', DB::raw('MAX(op.name) as name'), DB::raw('MAX(op.model) as model'), DB::raw('MAX(pd.name) as pd_name'))
            ->where('op.product_id', '>', 0)
            ->groupBy('op.product_id')
            ->orderBy('op.product_id')
            ->chunk(1000, function ($rows) use (&$count, $productRoot) {
                foreach ($rows as $row) {
                    AnaProduct::updateOrCreate(
                        ['segment' => $this->segment, 'source_id' => $row->product_id],
                        [
                            'name' => mb_substr($row->pd_name ?: $row->name, 0, 300),
                            'model' => mb_substr((string) $row->model, 0, 100),
                            'category_name' => $productRoot[$row->product_id] ?? null,
                        ]
                    );
                    $count++;
                }
            });

        return $count;
    }

    /** Mapa category_id => názov koreňovej kategórie. */
    protected function rootCategoryNames(): array
    {
        // koreňové kategórie (parent_id = 0) s názvom v slovenčine
        $roots = $this->src()->table($this->prefix.'category as c')
            ->join($this->prefix.'category_description as cd', 'cd.category_id', '=', 'c.category_id')
            ->where('c.parent_id', 0)
            ->whereIn('cd.language_id', [1, 2])
            ->orderBy('cd.language_id', 'desc')
            ->pluck('cd.name', 'c.category_id')
            ->all();

        // každú kategóriu namapujeme na jej koreň prechodom cez parent_id
        $parents = $this->src()->table($this->prefix.'category')->pluck('parent_id', 'category_id')->all();
        $map = [];
        foreach (array_keys($parents) as $catId) {
            $cur = $catId;
            $guard = 0;
            while (($parents[$cur] ?? 0) != 0 && $guard++ < 10) {
                $cur = $parents[$cur];
            }
            if (isset($roots[$cur])) {
                $map[$catId] = $roots[$cur];
            }
        }

        return $map;
    }

    /** Mapa product_id => názov koreňovej kategórie. */
    protected function productRootCategoryMap(array $categoryRootNames): array
    {
        $map = [];
        $this->src()->table($this->prefix.'product_to_category')
            ->select('product_id', 'category_id')
            ->orderBy('product_id')
            ->chunk(5000, function ($rows) use (&$map, $categoryRootNames) {
                foreach ($rows as $row) {
                    if (! isset($map[$row->product_id]) && isset($categoryRootNames[$row->category_id])) {
                        $map[$row->product_id] = mb_substr($categoryRootNames[$row->category_id], 0, 200);
                    }
                }
            });

        return $map;
    }

    protected function importOrders(\DateTimeInterface $since): int
    {
        $this->note('Import objednávok…');

        $statusNames = $this->src()->table($this->prefix.'order_status')
            ->whereIn('language_id', [1, 2])
            ->orderBy('language_id')
            ->pluck('name', 'order_status_id')
            ->all();
        $cancelledIds = config('analytics.segments.'.$this->segment.'.cancelled_status_ids');

        $customerIds = AnaCustomer::where('segment', $this->segment)->pluck('id', 'source_id')->all();
        $count = 0;

        $this->src()->table($this->prefix.'order')
            ->where('date_added', '>=', $since)
            ->whereNotIn('customer_id', $this->excludedCustomerIds)
            ->select(
                'order_id', 'customer_id', 'order_status_id', 'date_added',
                'total', 'total_sdph', 'cena_dopravy', 'discount', 'loyalty_discount',
                'zisk_bdph', 'payment_method', 'shipping_method', 'is_app_order',
                'points', 'payment_city'
            )
            ->orderBy('order_id')
            ->chunk(500, function ($rows) use (&$count, $statusNames, $cancelledIds, $customerIds) {
                foreach ($rows as $row) {
                    AnaOrder::updateOrCreate(
                        ['segment' => $this->segment, 'source_id' => $row->order_id],
                        [
                            'customer_id' => $customerIds[$row->customer_id] ?? null,
                            'ordered_at' => $this->safeDate($row->date_added),
                            'status_id' => $row->order_status_id,
                            'status_name' => $statusNames[$row->order_status_id] ?? (string) $row->order_status_id,
                            'is_cancelled' => in_array($row->order_status_id, $cancelledIds),
                            'total_net' => (float) $row->total,
                            'total_gross' => (float) $row->total_sdph,
                            'shipping_price' => (float) $row->cena_dopravy,
                            'discount' => (float) $row->discount + (float) $row->loyalty_discount,
                            // zisk sa v titi počíta až od ~09/2025; staršie objednávky majú 0/NULL —
                            // nula sa preto považuje za nezaznamenaný údaj, nie za reálnu nulovú maržu
                            'profit_net' => ($row->zisk_bdph !== null && (float) $row->zisk_bdph != 0.0)
                                ? (float) $row->zisk_bdph : null,
                            'payment_method' => mb_substr((string) $row->payment_method, 0, 128) ?: null,
                            'shipping_method' => mb_substr((string) $row->shipping_method, 0, 128) ?: null,
                            'channel' => $row->is_app_order ? 'app' : 'web',
                            'points_earned' => (int) $row->points,
                            'city' => mb_substr((string) $row->payment_city, 0, 128) ?: null,
                        ]
                    );
                    $count++;
                }
            });

        return $count;
    }

    protected function importOrderItems(): int
    {
        $this->note('Import položiek objednávok…');

        $orderIds = AnaOrder::where('segment', $this->segment)->pluck('id', 'source_id')->all();
        $productIds = AnaProduct::where('segment', $this->segment)->pluck('id', 'source_id')->all();
        $count = 0;

        // položky nahrávame nanovo (idempotentne: zmaž + vlož)
        AnaOrderItem::where('segment', $this->segment)->delete();

        $this->src()->table($this->prefix.'order_product')
            ->whereIn('order_id', array_keys($orderIds))
            ->select('order_id', 'product_id', 'quantity', 'price', 'jprice_sdph_discounted', 'jprice_sdph', 'total', 'sadzba_dph', 'darcek')
            ->orderBy('order_product_id')
            ->chunk(1000, function ($rows) use (&$count, $orderIds, $productIds) {
                $batch = [];
                foreach ($rows as $row) {
                    $batch[] = [
                        'segment' => $this->segment,
                        'order_id' => $orderIds[$row->order_id],
                        'product_id' => $productIds[$row->product_id] ?? null,
                        'quantity' => max(1, (int) $row->quantity),
                        'unit_price_net' => (float) $row->price,
                        'unit_price_gross' => (float) ($row->jprice_sdph_discounted ?? $row->jprice_sdph),
                        'total_net' => (float) $row->total,
                        'vat_rate' => (int) $row->sadzba_dph,
                        'is_gift' => (bool) $row->darcek,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $count++;
                }
                AnaOrderItem::insert($batch);
            });

        $this->refreshOrderItemCounts();

        return $count;
    }

    protected function refreshOrderItemCounts(): void
    {
        DB::statement("
            UPDATE ana_orders o
            JOIN (
                SELECT order_id, COUNT(*) items_count, SUM(quantity) units_count
                FROM ana_order_items WHERE segment = ? GROUP BY order_id
            ) i ON i.order_id = o.id
            SET o.items_count = i.items_count, o.units_count = i.units_count
            WHERE o.segment = ?
        ", [$this->segment, $this->segment]);
    }

    protected function importCartItems(): int
    {
        $this->note('Import položiek košíkov…');

        AnaCartItem::where('segment', $this->segment)->delete();
        $count = 0;

        $this->src()->table($this->prefix.'cart')
            ->whereNotIn('customer_id', $this->excludedCustomerIds)
            ->select('customer_id', 'product_id', 'quantity', 'date_added')
            ->orderBy('cart_id')
            ->chunk(1000, function ($rows) use (&$count) {
                $batch = [];
                foreach ($rows as $row) {
                    $batch[] = [
                        'segment' => $this->segment,
                        'customer_source_id' => $row->customer_id ?: null,
                        'product_source_id' => $row->product_id,
                        'quantity' => max(1, (int) $row->quantity),
                        'added_at' => $this->safeDate($row->date_added),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $count++;
                }
                AnaCartItem::insert($batch);
            });

        return $count;
    }

    protected function safeDate(?string $value): ?string
    {
        if (! $value || str_starts_with($value, '0000')) {
            return null;
        }

        return $value;
    }
}
