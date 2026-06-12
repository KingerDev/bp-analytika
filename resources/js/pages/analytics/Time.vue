<script setup lang="ts">
import VueApexCharts from 'vue3-apexcharts';
import AnalyticsLayout from '@/layouts/analytics/AnalyticsLayout.vue';
import ChartCard from '@/components/analytics/ChartCard.vue';
import CompareStat from '@/components/analytics/CompareStat.vue';
import { SEGMENT_COLORS, SEGMENT_LABELS, baseChartOptions, formatNumber, formatPct } from '@/lib/analytics';
import { computed } from 'vue';

const props = defineProps<{
    data: {
        hourly: { labels: string[]; series: Record<string, number[]> };
        weekday: { labels: string[]; series: Record<string, number[]> };
        monthly_seasonality: { labels: string[]; series: Record<string, number[]> };
        work_hours_share: Record<'b2c' | 'b2b', number>;
        weekday_chi2: { chi2: number; df: number; p_formatted: string; cramers_v: number; significant: boolean } | null;
    };
    meta: Record<string, unknown>;
}>();

function comparisonOptions(categories: string[], type: 'line' | 'bar', xTitle: string) {
    const extra: Record<string, unknown> = {
        chart: { type, height: 320 },
        colors: [SEGMENT_COLORS.b2c, SEGMENT_COLORS.b2b],
        xaxis: { categories, title: { text: xTitle } },
        yaxis: { title: { text: 'Podiel objednávok (%)' } },
        legend: { position: 'top' },
    };
    if (type === 'line') {
        extra.stroke = { curve: 'smooth', width: 3 };
    } else {
        extra.plotOptions = { bar: { columnWidth: '60%' } };
    }
    return baseChartOptions(extra);
}

const toSeries = (data: Record<string, number[]>) => [
    { name: SEGMENT_LABELS.b2c, data: data.b2c },
    { name: SEGMENT_LABELS.b2b, data: data.b2b },
];

const hourlySeries = computed(() => toSeries(props.data.hourly.series));
const weekdaySeries = computed(() => toSeries(props.data.weekday.series));
const seasonalitySeries = computed(() => toSeries(props.data.monthly_seasonality.series));
</script>

<template>
    <AnalyticsLayout
        title="Časové vzorce nákupov"
        subtitle="Kedy segmenty nakupujú: hodina dňa, deň v týždni a sezónnosť — podiely v % pre porovnateľnosť rôzne veľkých segmentov"
    >
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <CompareStat
                label="Nákupy v pracovnom čase"
                :b2c="formatPct(data.work_hours_share.b2c)"
                :b2b="formatPct(data.work_hours_share.b2b)"
                hint="po–pia 8:00–17:00"
            />
        </div>

        <div
            v-if="data.weekday_chi2"
            class="mb-6 rounded-xl border p-4 text-sm"
            :class="data.weekday_chi2.significant ? 'border-green-200 bg-green-50 text-green-900' : 'border-neutral-200 bg-white text-neutral-700'"
        >
            Rozloženie objednávok v dňoch týždňa sa medzi segmentmi {{ data.weekday_chi2.significant ? '' : 'ne' }}líši štatisticky
            významne (chi-kvadrát test, χ² = {{ formatNumber(data.weekday_chi2.chi2, 1) }}, df = {{ data.weekday_chi2.df }},
            {{ data.weekday_chi2.p_formatted }}, Cramérovo V = {{ formatNumber(data.weekday_chi2.cramers_v, 2) }}).
        </div>

        <div class="grid gap-6">
            <ChartCard title="Rozloženie objednávok počas dňa" subtitle="Podiel objednávok podľa hodiny vytvorenia">
                <VueApexCharts type="line" height="320" :options="comparisonOptions(data.hourly.labels, 'line', 'Hodina dňa')" :series="hourlySeries" />
            </ChartCard>
            <ChartCard title="Rozloženie objednávok v týždni" subtitle="Podiel objednávok podľa dňa v týždni">
                <VueApexCharts type="bar" height="320" :options="comparisonOptions(data.weekday.labels, 'bar', '')" :series="weekdaySeries" />
            </ChartCard>
            <ChartCard title="Sezónnosť počas roka" subtitle="Podiel objednávok podľa kalendárneho mesiaca (agregované za celé obdobie)">
                <VueApexCharts
                    type="bar"
                    height="320"
                    :options="comparisonOptions(data.monthly_seasonality.labels, 'bar', '')"
                    :series="seasonalitySeries"
                />
            </ChartCard>
        </div>
    </AnalyticsLayout>
</template>
