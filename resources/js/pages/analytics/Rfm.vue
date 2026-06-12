<script setup lang="ts">
import VueApexCharts from 'vue3-apexcharts';
import AnalyticsLayout from '@/layouts/analytics/AnalyticsLayout.vue';
import ChartCard from '@/components/analytics/ChartCard.vue';
import CompareStat from '@/components/analytics/CompareStat.vue';
import DataTable from '@/components/analytics/DataTable.vue';
import { SEGMENT_COLORS, SEGMENT_LABELS, baseChartOptions, formatEur, formatNumber } from '@/lib/analytics';
import { computed } from 'vue';

interface RfmRow {
    key: string;
    label: string;
    customers: number;
    share: number;
    revenue: number;
}

interface SegmentRfm {
    label: string;
    rfm_distribution: RfmRow[];
    frequency_distribution: { labels: string[]; counts: number[]; shares: number[] };
    interpurchase_days: { avg: number | null; median: number | null };
    customers: number;
}

const props = defineProps<{
    data: {
        segments: Record<'b2c' | 'b2b', SegmentRfm>;
        cohorts: Record<'b2c' | 'b2b', { quarters: string[]; rows: { cohort: string; size: number; retention: (number | null)[] }[] }>;
    };
    meta: Record<string, unknown>;
}>();

const frequencyOptions = baseChartOptions({
    chart: { type: 'bar', height: 300 },
    colors: [SEGMENT_COLORS.b2c, SEGMENT_COLORS.b2b],
    plotOptions: { bar: { columnWidth: '60%' } },
    xaxis: { categories: props.data.segments.b2c.frequency_distribution.labels, title: { text: 'Počet objednávok zákazníka' } },
    yaxis: { title: { text: 'Podiel zákazníkov (%)' } },
    legend: { position: 'top' },
});
const frequencySeries = computed(() => [
    { name: SEGMENT_LABELS.b2c, data: props.data.segments.b2c.frequency_distribution.shares },
    { name: SEGMENT_LABELS.b2b, data: props.data.segments.b2b.frequency_distribution.shares },
]);

function rfmChartOptions(segment: 'b2c' | 'b2b') {
    return baseChartOptions({
        chart: { type: 'bar', height: 280 },
        colors: [SEGMENT_COLORS[segment]],
        plotOptions: { bar: { horizontal: true, barHeight: '55%' } },
        xaxis: { categories: props.data.segments[segment].rfm_distribution.map((r) => r.label), title: { text: 'Podiel zákazníkov (%)' } },
    });
}
function rfmSeries(segment: 'b2c' | 'b2b') {
    return [{ name: 'Podiel zákazníkov (%)', data: props.data.segments[segment].rfm_distribution.map((r) => r.share) }];
}

const rfmColumns = [
    { key: 'label', label: 'RFM segment' },
    { key: 'customers', label: 'Zákazníci', align: 'right' as const },
    { key: 'share', label: 'Podiel (%)', align: 'right' as const },
    { key: 'revenue_fmt', label: 'Tržby', align: 'right' as const },
];
function rfmRows(segment: 'b2c' | 'b2b') {
    return props.data.segments[segment].rfm_distribution.map((r) => ({ ...r, revenue_fmt: formatEur(r.revenue) }));
}

// farebná škála pre kohortovú tabuľku
function cellColor(value: number | null): string {
    if (value === null) return '';
    if (value >= 60) return 'bg-blue-600 text-white';
    if (value >= 40) return 'bg-blue-400 text-white';
    if (value >= 25) return 'bg-blue-200';
    if (value >= 10) return 'bg-blue-100';
    return 'bg-blue-50';
}
</script>

<template>
    <AnalyticsLayout
        title="RFM segmentácia a retencia"
        subtitle="Vernosť a hodnota zákazníkov: RFM skóre (recency–frequency–monetary), frekvencia nákupov a kvartálne kohorty"
    >
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <CompareStat
                label="Aktívni zákazníci"
                :b2c="formatNumber(data.segments.b2c.customers)"
                :b2b="formatNumber(data.segments.b2b.customers)"
            />
            <CompareStat
                label="Ø dní medzi nákupmi"
                :b2c="formatNumber(data.segments.b2c.interpurchase_days.avg, 1)"
                :b2b="formatNumber(data.segments.b2b.interpurchase_days.avg, 1)"
                hint="zákazníci s 2+ objednávkami"
            />
            <CompareStat
                label="Medián dní medzi nákupmi"
                :b2c="formatNumber(data.segments.b2c.interpurchase_days.median, 1)"
                :b2b="formatNumber(data.segments.b2b.interpurchase_days.median, 1)"
            />
        </div>

        <div class="mb-6 grid gap-6 xl:grid-cols-2">
            <ChartCard v-for="segment in (['b2c', 'b2b'] as const)" :key="segment" :title="`RFM segmenty – ${data.segments[segment].label}`">
                <VueApexCharts type="bar" height="280" :options="rfmChartOptions(segment)" :series="rfmSeries(segment)" />
                <DataTable :columns="rfmColumns" :rows="rfmRows(segment)" :export-name="`rfm-${segment}`" />
            </ChartCard>
        </div>

        <div class="mb-6">
            <ChartCard
                title="Frekvencia nákupov"
                subtitle="Podiel zákazníkov podľa počtu objednávok za sledované obdobie — jednorazoví vs. opakovaní zákazníci"
            >
                <VueApexCharts type="bar" height="300" :options="frequencyOptions" :series="frequencySeries" />
            </ChartCard>
        </div>

        <div class="grid gap-6">
            <ChartCard
                v-for="segment in (['b2c', 'b2b'] as const)"
                :key="segment"
                :title="`Kohortová retencia – ${data.segments[segment].label}`"
                subtitle="Podiel zákazníkov kohorty (kvartál prvého nákupu), ktorí nakúpili aj v nasledujúcich kvartáloch (%)"
            >
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="text-left text-neutral-500">
                                <th class="px-2 py-1">Kohorta</th>
                                <th class="px-2 py-1 text-right">Zákazníci</th>
                                <th v-for="(q, i) in data.cohorts[segment].quarters" :key="q" class="px-2 py-1 text-center">+{{ i }}Q</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in data.cohorts[segment].rows" :key="row.cohort">
                                <td class="px-2 py-1 font-medium">{{ row.cohort }}</td>
                                <td class="px-2 py-1 text-right tabular-nums">{{ row.size }}</td>
                                <td
                                    v-for="(value, i) in row.retention"
                                    :key="i"
                                    class="px-2 py-1 text-center tabular-nums"
                                    :class="cellColor(value)"
                                >
                                    {{ value === null ? '' : value }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </ChartCard>
        </div>
    </AnalyticsLayout>
</template>
