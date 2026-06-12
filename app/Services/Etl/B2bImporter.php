<?php

namespace App\Services\Etl;

use App\Models\AnaCartItem;
use App\Models\AnaCustomer;
use App\Models\AnaOrder;
use App\Models\AnaOrderItem;
use App\Models\AnaProduct;
use Illuminate\Support\Facades\DB;

/**
 * ETL import veľkoobchodného segmentu (tsv e-shop) do zjednotenej anonymizovanej schémy.
 *
 * Zdroj: pripojenie src_tsv (produkčná DB cez SSH tunel).
 * Špecifiká B2B: organizácie (veľkosť, verejná správa, cenová hladina) a schvaľovací
 * workflow objednávok (date_added → date_approved = dĺžka rozhodovacieho procesu).
 */
class B2bImporter extends B2cImporter
{
    protected string $segment = 'b2b';
    protected string $connection = 'src_tsv';
    protected string $prefix = 'tsv_';

    protected function importCustomers(\DateTimeInterface $since): int
    {
        $this->note('Import zákazníkov a organizácií…');

        // organizácie: jeden záznam na idorg (preferujeme fakturačný)
        $orgs = [];
        $this->src()->table($this->prefix.'organizacie_all')
            ->select('idorg', 'velkost', 'statna_sprava', 'cu_predaj', 'mesto', 'fakturacni')
            ->orderBy('fakturacni', 'desc')
            ->chunk(2000, function ($rows) use (&$orgs) {
                foreach ($rows as $row) {
                    $orgs[$row->idorg] ??= $row;
                }
            });

        // zákazníci, ktorí sú schvaľovateľmi (iný zákazník na nich odkazuje v stĺpci approver)
        $approverIds = $this->src()->table($this->prefix.'customer')
            ->where('approver', '>', 0)
            ->distinct()
            ->pluck('approver')
            ->flip()
            ->all();

        $count = 0;
        $this->src()->table($this->prefix.'customer as c')
            ->join($this->prefix.'order as o', 'o.customer_id', '=', 'c.customer_id')
            ->where('o.date_added', '>=', $since)
            ->whereNotIn('c.customer_id', $this->excludedCustomerIds)
            ->select('c.customer_id', 'c.idorg', 'c.date_added', 'c.rozhodovac', 'c.ovplyvnovac', 'c.bonus')
            ->distinct()
            ->orderBy('c.customer_id')
            ->chunk(500, function ($rows) use (&$count, $orgs, $approverIds) {
                foreach ($rows as $row) {
                    $org = $orgs[$row->idorg] ?? null;
                    AnaCustomer::updateOrCreate(
                        ['segment' => $this->segment, 'source_id' => $row->customer_id],
                        [
                            'code' => sprintf('B2B-%06d', $row->customer_id),
                            'registered_at' => $this->safeDate($row->date_added),
                            'org_source_id' => $row->idorg ?: null,
                            'org_size' => $org && $org->velkost !== '' ? mb_substr((string) $org->velkost, 0, 30) : null,
                            'is_public_sector' => (bool) ($org->statna_sprava ?? false),
                            'pricing_tier' => $org && $org->cu_predaj !== null ? (string) $org->cu_predaj : null,
                            'city' => $org && $org->mesto !== '' ? mb_substr((string) $org->mesto, 0, 128) : null,
                            'is_approver' => isset($approverIds[$row->customer_id]),
                            'is_decision_maker' => (bool) $row->rozhodovac,
                            'is_influencer' => (bool) $row->ovplyvnovac,
                            'loyalty_points' => (int) $row->bonus,
                        ]
                    );
                    $count++;
                }
            });

        return $count;
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
                'order_id', 'customer_id', 'order_status_id', 'date_added', 'date_approved', 'approved',
                'total', 'total_bez_zlavy', 'total_sdph', 'shipping_price',
                'zisk', 'payment_method', 'shipping_method', 'points', 'payment_city'
            )
            ->orderBy('order_id')
            ->chunk(500, function ($rows) use (&$count, $statusNames, $cancelledIds, $customerIds) {
                foreach ($rows as $row) {
                    $orderedAt = $this->safeDate($row->date_added);
                    $approvedAt = $this->safeDate($row->date_approved);
                    $approvalHours = null;
                    if ($orderedAt && $approvedAt && $approvedAt >= $orderedAt) {
                        $approvalHours = round((strtotime($approvedAt) - strtotime($orderedAt)) / 3600, 2);
                    }

                    $discount = max(0, (float) $row->total_bez_zlavy - (float) $row->total);

                    AnaOrder::updateOrCreate(
                        ['segment' => $this->segment, 'source_id' => $row->order_id],
                        [
                            'customer_id' => $customerIds[$row->customer_id] ?? null,
                            'ordered_at' => $orderedAt,
                            'approved_at' => $approvedAt,
                            'approval_hours' => $approvalHours,
                            'status_id' => $row->order_status_id,
                            'status_name' => $statusNames[$row->order_status_id] ?? (string) $row->order_status_id,
                            'is_cancelled' => in_array($row->order_status_id, $cancelledIds),
                            'total_net' => (float) $row->total,
                            'total_gross' => (float) $row->total_sdph,
                            'shipping_price' => (float) $row->shipping_price,
                            'discount' => $discount,
                            'profit_net' => $row->zisk !== null ? (float) $row->zisk : null,
                            'payment_method' => mb_substr((string) $row->payment_method, 0, 128) ?: null,
                            'shipping_method' => mb_substr((string) $row->shipping_method, 0, 128) ?: null,
                            'channel' => 'web',
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

        AnaOrderItem::where('segment', $this->segment)->delete();

        // pri ~38k objednávkach ideme po dávkach order_id, nie cez whereIn s celým zoznamom
        foreach (array_chunk(array_keys($orderIds), 1000) as $chunkOrderIds) {
            $rows = $this->src()->table($this->prefix.'order_product')
                ->whereIn('order_id', $chunkOrderIds)
                ->select('order_id', 'product_id', 'quantity', 'price', 'jprice_sdph', 'total', 'sadzba_dph', 'darcek')
                ->get();

            $batch = [];
            foreach ($rows as $row) {
                $batch[] = [
                    'segment' => $this->segment,
                    'order_id' => $orderIds[$row->order_id],
                    'product_id' => $productIds[$row->product_id] ?? null,
                    'quantity' => max(1, (int) $row->quantity),
                    'unit_price_net' => (float) $row->price,
                    'unit_price_gross' => (float) $row->jprice_sdph,
                    'total_net' => (float) $row->total,
                    'vat_rate' => (int) $row->sadzba_dph,
                    'is_gift' => (bool) $row->darcek,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $count++;
            }
            foreach (array_chunk($batch, 1000) as $insert) {
                AnaOrderItem::insert($insert);
            }
        }

        $this->refreshOrderItemCounts();

        return $count;
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
}
