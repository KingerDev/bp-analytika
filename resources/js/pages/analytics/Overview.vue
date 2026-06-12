<script setup lang="ts">
import VueApexCharts from 'vue3-apexcharts';
import AnalyticsLayout from '@/layouts/analytics/AnalyticsLayout.vue';
import ChartCard from '@/components/analytics/ChartCard.vue';
import CompareStat from '@/components/analytics/CompareStat.vue';
import { SEGMENT_COLORS, SEGMENT_LABELS, baseChartOptions, formatEur, formatNumber, formatPct } from '@/lib/analytics';
// marža sa zobrazuje výhradne relatívne (% z tržieb) — absolútny zisk aplikácia neposiela
import { computed } from 'vue';

interface SegmentKpi {
    label: string;
    orders: number;
    revenue: number;
    aov: number;
    median_aov: number;
    avg_items: number;
    avg_units: number;
    margin_pct: number | null;
    customers: number;
    orders_per_customer: number;
    repeat_rate: number;
    cancel_rate: number;
}

const props = defineProps<{
    data: {
        segments: Record<'b2c' | 'b2b', SegmentKpi>;
        monthly: { months: string[]; series: Record<string, { orders: number[]; revenue: number[] }> };
        monthly_margin: { months: string[]; series: Record<string, (number | null)[]> };
        aov_test: { p_formatted: string; significant: boolean; z: number; n1: number; n2: number; median1: number; median2: number } | null;
        aov_histogram: { labels: string[]; series: Record<string, number[]> };
    };
    meta: Record<string, unknown>;
}>();

const b2c = props.data.segments.b2c;
const b2b = props.data.segments.b2b;

const monthlyOrdersOptions = baseChartOptions({
    chart: { type: 'line', height: 320 },
    stroke: { curve: 'smooth', width: 3 },
    colors: [SEGMENT_COLORS.b2c, SEGMENT_COLORS.b2b],
    xaxis: { categories: props.data.monthly.months, labels: { rotate: -45 } },
    yaxis: [
        { title: { text: 'B2C objednávky' }, seriesName: SEGMENT_LABELS.b2c },
        { opposite: true, title: { text: 'B2B objednávky' }, seriesName: SEGMENT_LABELS.b2b },
    ],
    legend: { position: 'top' },
});
const monthlyOrdersSeries = computed(() => [
    { name: SEGMENT_LABELS.b2c, data: props.data.monthly.series.b2c.orders },
    { name: SEGMENT_LABELS.b2b, data: props.data.monthly.series.b2b.orders },
]);

const histogramOptions = baseChartOptions({
    chart: { type: 'bar', height: 320 },
    colors: [SEGMENT_COLORS.b2c, SEGMENT_COLORS.b2b],
    plotOptions: { bar: { columnWidth: '60%' } },
    xaxis: { categories: props.data.aov_histogram.labels },
    yaxis: { title: { text: 'Podiel objednávok (%)' } },
    legend: { position: 'top' },
});
const histogramSeries = computed(() => [
    { name: SEGMENT_LABELS.b2c, data: props.data.aov_histogram.series.b2c },
    { name: SEGMENT_LABELS.b2b, data: props.data.aov_histogram.series.b2b },
]);

const marginOptions = baseChartOptions({
    chart: { type: 'line', height: 300 },
    colors: [SEGMENT_COLORS.b2c, SEGMENT_COLORS.b2b],
    stroke: { curve: 'smooth', width: 3 },
    xaxis: { categories: props.data.monthly_margin.months, labels: { rotate: -45 } },
    yaxis: { title: { text: 'Marža (% z tržieb)' }, min: 0 },
    legend: { position: 'top' },
});
const marginSeries = computed(() => [
    { name: SEGMENT_LABELS.b2c, data: props.data.monthly_margin.series.b2c },
    { name: SEGMENT_LABELS.b2b, data: props.data.monthly_margin.series.b2b },
]);
</script>

<template>
    <AnalyticsLayout
        title="Prehľad KPI"
        subtitle="Základné porovnanie nákupného správania maloobchodného (B2C) a veľkoobchodného (B2B) segmentu"
    >
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <CompareStat label="Objednávky" :b2c="formatNumber(b2c.orders)" :b2b="formatNumber(b2b.orders)" hint="bez stornovaných" />
            <CompareStat label="Tržby (bez DPH)" :b2c="formatEur(b2c.revenue)" :b2b="formatEur(b2b.revenue)" />
            <CompareStat label="Priemerná objednávka" :b2c="formatEur(b2c.aov)" :b2b="formatEur(b2b.aov)" hint="aritmetický priemer" />
            <CompareStat label="Mediánová objednávka" :b2c="formatEur(b2c.median_aov)" :b2b="formatEur(b2b.median_aov)" />
            <CompareStat label="Zákazníci" :b2c="formatNumber(b2c.customers)" :b2b="formatNumber(b2b.customers)" hint="s objednávkou v období" />
            <CompareStat
                label="Objednávky / zákazník"
                :b2c="formatNumber(b2c.orders_per_customer, 2)"
                :b2b="formatNumber(b2b.orders_per_customer, 2)"
            />
            <CompareStat label="Opakovaný nákup" :b2c="formatPct(b2c.repeat_rate)" :b2b="formatPct(b2b.repeat_rate)" hint="podiel zákazníkov s 2+ objednávkami" />
            <CompareStat label="Položky / objednávka" :b2c="formatNumber(b2c.avg_items, 1)" :b2b="formatNumber(b2b.avg_items, 1)" />
            <CompareStat label="Marža" :b2c="formatPct(b2c.margin_pct)" :b2b="formatPct(b2b.margin_pct)" hint="podiel zisku na tržbách (relatívne)" />
        </div>

        <div
            v-if="data.aov_test"
            class="mb-6 rounded-xl border p-4 text-sm"
            :class="data.aov_test.significant ? 'border-green-200 bg-green-50 text-green-900' : 'border-neutral-200 bg-white text-neutral-700'"
        >
            <strong>Mann-Whitneyho U test</strong> rozdielu hodnôt objednávok medzi segmentmi:
            {{ data.aov_test.p_formatted }} (z = {{ data.aov_test.z }}; n₁ = {{ formatNumber(data.aov_test.n1) }} B2C, n₂ =
            {{ formatNumber(data.aov_test.n2) }} B2B).
            <template v-if="data.aov_test.significant">
                Rozdiel v hodnote objednávky medzi segmentmi je <strong>štatisticky významný</strong> (α = 0,05).
            </template>
            <template v-else>Rozdiel nie je štatisticky významný (α = 0,05).</template>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <ChartCard title="Vývoj počtu objednávok po mesiacoch" subtitle="B2C na ľavej osi, B2B na pravej osi">
                <VueApexCharts type="line" height="320" :options="monthlyOrdersOptions" :series="monthlyOrdersSeries" />
            </ChartCard>
            <ChartCard
                title="Rozdelenie hodnôt objednávok"
                subtitle="Podiel objednávok v cenových pásmach (bez DPH) — tvar distribúcie v segmentoch"
            >
                <VueApexCharts type="bar" height="320" :options="histogramOptions" :series="histogramSeries" />
            </ChartCard>
            <ChartCard
                title="Vývoj marže po mesiacoch"
                subtitle="Podiel zisku na tržbách v % — zobrazované len relatívne, bez absolútnych súm"
            >
                <VueApexCharts type="line" height="300" :options="marginOptions" :series="marginSeries" />
            </ChartCard>
        </div>
    </AnalyticsLayout>
</template>
