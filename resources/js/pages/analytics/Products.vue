<script setup lang="ts">
import VueApexCharts from 'vue3-apexcharts';
import AnalyticsLayout from '@/layouts/analytics/AnalyticsLayout.vue';
import ChartCard from '@/components/analytics/ChartCard.vue';
import CompareStat from '@/components/analytics/CompareStat.vue';
import DataTable from '@/components/analytics/DataTable.vue';
import { SEGMENT_COLORS, baseChartOptions, formatEur, formatNumber, formatPct } from '@/lib/analytics';

interface CategoryRow {
    category: string;
    revenue: number;
    share: number;
    units: number;
    orders: number;
}

interface ProductRow {
    name: string;
    category: string | null;
    units: number;
    revenue: number;
    orders: number;
}

interface SegmentProducts {
    label: string;
    top_categories: CategoryRow[];
    top_products: ProductRow[];
    assortment_breadth: number | null;
    avg_line_quantity: number;
    gift_share: number;
}

const props = defineProps<{
    data: { segments: Record<'b2c' | 'b2b', SegmentProducts> };
    meta: Record<string, unknown>;
}>();

function categoryOptions(segment: 'b2c' | 'b2b') {
    return baseChartOptions({
        chart: { type: 'bar', height: 340 },
        colors: [SEGMENT_COLORS[segment]],
        plotOptions: { bar: { horizontal: true, barHeight: '60%' } },
        xaxis: {
            categories: props.data.segments[segment].top_categories.map((c) => c.category),
            title: { text: 'Podiel na tržbách (%)' },
        },
    });
}
function categorySeries(segment: 'b2c' | 'b2b') {
    return [{ name: 'Podiel na tržbách (%)', data: props.data.segments[segment].top_categories.map((c) => c.share) }];
}

const categoryColumns = [
    { key: 'category', label: 'Kategória' },
    { key: 'share_fmt', label: 'Podiel', align: 'right' as const },
    { key: 'revenue_fmt', label: 'Tržby', align: 'right' as const },
    { key: 'units', label: 'Kusy', align: 'right' as const },
    { key: 'orders', label: 'Objednávky', align: 'right' as const },
];
const productColumns = [
    { key: 'name', label: 'Produkt' },
    { key: 'category', label: 'Kategória' },
    { key: 'units', label: 'Kusy', align: 'right' as const },
    { key: 'revenue_fmt', label: 'Tržby', align: 'right' as const },
    { key: 'orders', label: 'Objednávky', align: 'right' as const },
];

function categoryRows(segment: 'b2c' | 'b2b') {
    return props.data.segments[segment].top_categories.map((c) => ({
        ...c,
        share_fmt: formatPct(c.share),
        revenue_fmt: formatEur(c.revenue),
    }));
}
function productRows(segment: 'b2c' | 'b2b') {
    return props.data.segments[segment].top_products.map((p) => ({ ...p, revenue_fmt: formatEur(p.revenue) }));
}
</script>

<template>
    <AnalyticsLayout
        title="Produktové preferencie"
        subtitle="Čo segmenty nakupujú: štruktúra tržieb podľa kategórií, najpredávanejšie produkty a šírka sortimentu"
    >
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <CompareStat
                label="Šírka sortimentu"
                :b2c="formatNumber(data.segments.b2c.assortment_breadth, 2)"
                :b2b="formatNumber(data.segments.b2b.assortment_breadth, 2)"
                hint="Ø počet kategórií na zákazníka"
            />
            <CompareStat
                label="Kusy na riadok objednávky"
                :b2c="formatNumber(data.segments.b2c.avg_line_quantity, 2)"
                :b2b="formatNumber(data.segments.b2b.avg_line_quantity, 2)"
                hint="množstevné nakupovanie"
            />
            <CompareStat
                label="Darčekové položky"
                :b2c="formatPct(data.segments.b2c.gift_share)"
                :b2b="formatPct(data.segments.b2b.gift_share)"
                hint="podiel riadkov objednávok"
            />
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <ChartCard
                v-for="segment in (['b2c', 'b2b'] as const)"
                :key="segment"
                :title="`Top kategórie – ${data.segments[segment].label}`"
                subtitle="Podiel koreňových kategórií na tržbách segmentu"
            >
                <VueApexCharts type="bar" height="340" :options="categoryOptions(segment)" :series="categorySeries(segment)" />
                <DataTable :columns="categoryColumns" :rows="categoryRows(segment)" :export-name="`kategorie-${segment}`" />
            </ChartCard>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-2">
            <ChartCard v-for="segment in (['b2c', 'b2b'] as const)" :key="segment" :title="`Top produkty – ${data.segments[segment].label}`">
                <DataTable :columns="productColumns" :rows="productRows(segment)" :export-name="`produkty-${segment}`" />
            </ChartCard>
        </div>
    </AnalyticsLayout>
</template>
