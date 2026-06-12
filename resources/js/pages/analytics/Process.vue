<script setup lang="ts">
import VueApexCharts from 'vue3-apexcharts';
import AnalyticsLayout from '@/layouts/analytics/AnalyticsLayout.vue';
import ChartCard from '@/components/analytics/ChartCard.vue';
import CompareStat from '@/components/analytics/CompareStat.vue';
import DataTable from '@/components/analytics/DataTable.vue';
import { SEGMENT_COLORS, baseChartOptions, formatEur, formatNumber, formatPct } from '@/lib/analytics';

interface MethodRow {
    method: string;
    count: number;
    share: number;
}

interface SegmentProcess {
    label: string;
    payment_methods: MethodRow[];
    shipping_methods: MethodRow[];
    discount_usage: { share: number; avg_discount: number | null };
    status_distribution: { status: string; count: number }[];
    cart_snapshot: { items: number; customers: number };
}

const props = defineProps<{
    data: {
        segments: Record<'b2c' | 'b2b', SegmentProcess>;
        approval: {
            orders_with_approval: number;
            avg_hours: number | null;
            median_hours: number | null;
            distribution: { labels: string[]; counts: number[] };
            rejected_count: number;
            rejected_share: number;
            pending_count: number;
            roles: { approvers: number; decision_makers: number; influencers: number; total: number };
        };
        channel_b2c: { channel: string; count: number; share: number; aov: number }[];
        decision_speed: Record<
            'b2c' | 'b2b',
            {
                reg_to_first_median: number | null;
                reg_to_first_avg: number | null;
                reg_to_first_n: number;
                first_to_second_median: number | null;
                first_to_second_n: number;
            }
        >;
        payment_chi2: { chi2: number; df: number; p_formatted: string; cramers_v: number; significant: boolean } | null;
    };
    meta: Record<string, unknown>;
}>();

function donutOptions(rows: MethodRow[], segment: 'b2c' | 'b2b') {
    return baseChartOptions({
        chart: { type: 'donut', height: 300 },
        labels: rows.map((r) => r.method),
        legend: { position: 'bottom' },
        theme: { monochrome: { enabled: true, color: SEGMENT_COLORS[segment], shadeIntensity: 0.85 } },
        dataLabels: { enabled: true, formatter: (v: number) => `${v.toFixed(1)} %` },
    });
}

const approvalOptions = baseChartOptions({
    chart: { type: 'bar', height: 300 },
    colors: [SEGMENT_COLORS.b2b],
    plotOptions: { bar: { columnWidth: '60%' } },
    xaxis: { categories: props.data.approval.distribution.labels, title: { text: 'Čas od vytvorenia po schválenie' } },
    yaxis: { title: { text: 'Počet objednávok' } },
});

const methodColumns = [
    { key: 'method', label: 'Spôsob' },
    { key: 'count', label: 'Objednávky', align: 'right' as const },
    { key: 'share_fmt', label: 'Podiel', align: 'right' as const },
];
const methodRows = (rows: MethodRow[]) => rows.map((r) => ({ ...r, share_fmt: formatPct(r.share) }));

const channelColumns = [
    { key: 'channel', label: 'Kanál' },
    { key: 'count', label: 'Objednávky', align: 'right' as const },
    { key: 'share_fmt', label: 'Podiel', align: 'right' as const },
    { key: 'aov_fmt', label: 'Ø objednávka', align: 'right' as const },
];
const channelRows = props.data.channel_b2c.map((r) => ({ ...r, share_fmt: formatPct(r.share), aov_fmt: formatEur(r.aov) }));

const statusColumns = [
    { key: 'status', label: 'Stav objednávky' },
    { key: 'count', label: 'Počet', align: 'right' as const },
];
</script>

<template>
    <AnalyticsLayout
        title="Nákupný proces a rozhodovanie"
        subtitle="Platobné a dopravné preferencie, využívanie zliav a špecifiká rozhodovacieho procesu (B2B schvaľovací workflow, B2C kanály)"
    >
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <CompareStat
                label="Objednávky so zľavou"
                :b2c="formatPct(data.segments.b2c.discount_usage.share)"
                :b2b="formatPct(data.segments.b2b.discount_usage.share)"
            />
            <CompareStat
                label="Ø výška zľavy"
                :b2c="formatEur(data.segments.b2c.discount_usage.avg_discount)"
                :b2b="formatEur(data.segments.b2b.discount_usage.avg_discount)"
            />
            <CompareStat
                label="Položky v košíkoch"
                :b2c="formatNumber(data.segments.b2c.cart_snapshot.items)"
                :b2b="formatNumber(data.segments.b2b.cart_snapshot.items)"
                hint="aktuálny stav (nedokončené nákupy)"
            />
            <CompareStat
                label="Registrácia → 1. nákup"
                :b2c="`${formatNumber(data.decision_speed.b2c.reg_to_first_median, 0)} dní`"
                :b2b="`${formatNumber(data.decision_speed.b2b.reg_to_first_median, 0)} dní`"
                hint="medián; len zákazníci registrovaní v období"
            />
            <CompareStat
                label="1. → 2. objednávka"
                :b2c="`${formatNumber(data.decision_speed.b2c.first_to_second_median, 0)} dní`"
                :b2b="`${formatNumber(data.decision_speed.b2b.first_to_second_median, 0)} dní`"
                :hint="`medián; n = ${formatNumber(data.decision_speed.b2c.first_to_second_n)} / ${formatNumber(data.decision_speed.b2b.first_to_second_n)}`"
            />
        </div>

        <h2 class="mb-3 text-lg font-semibold">Rozhodovací proces v B2B (schvaľovací workflow)</h2>
        <div class="mb-6 grid gap-6 xl:grid-cols-2">
            <ChartCard title="Dĺžka schvaľovania objednávok" subtitle="Čas od vytvorenia objednávky po jej schválenie schvaľovateľom">
                <VueApexCharts
                    type="bar"
                    height="300"
                    :options="approvalOptions"
                    :series="[{ name: 'Objednávky', data: data.approval.distribution.counts }]"
                />
            </ChartCard>
            <div class="grid grid-cols-2 content-start gap-4">
                <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-medium uppercase text-neutral-500">Schvaľované objednávky</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums">{{ formatNumber(data.approval.orders_with_approval) }}</div>
                </div>
                <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-medium uppercase text-neutral-500">Medián schválenia</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums">{{ formatNumber(data.approval.median_hours, 1) }} h</div>
                    <div class="text-[11px] text-neutral-400">priemer {{ formatNumber(data.approval.avg_hours, 1) }} h</div>
                </div>
                <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-medium uppercase text-neutral-500">Zamietnuté schvaľovateľom</div>
                    <div class="mt-1 text-2xl font-bold tabular-nums">{{ formatNumber(data.approval.rejected_count) }}</div>
                    <div class="text-[11px] text-neutral-400">{{ formatPct(data.approval.rejected_share) }} všetkých B2B objednávok</div>
                </div>
                <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-medium uppercase text-neutral-500">Roly v nákupnom centre</div>
                    <div class="mt-1 text-sm">
                        <div>Schvaľovatelia: <strong>{{ formatNumber(data.approval.roles.approvers) }}</strong></div>
                        <div>Rozhodovatelia: <strong>{{ formatNumber(data.approval.roles.decision_makers) }}</strong></div>
                        <div>Ovplyvňovatelia: <strong>{{ formatNumber(data.approval.roles.influencers) }}</strong></div>
                    </div>
                </div>
            </div>
        </div>

        <h2 class="mb-3 text-lg font-semibold">Platobné preferencie</h2>
        <div v-if="data.payment_chi2" class="mb-4 rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-900">
            Rozloženie platobných metód závisí od segmentu (chi-kvadrát test, χ² = {{ formatNumber(data.payment_chi2.chi2, 1) }},
            df = {{ data.payment_chi2.df }}, {{ data.payment_chi2.p_formatted }}, Cramérovo V =
            {{ formatNumber(data.payment_chi2.cramers_v, 2) }}). Množiny metód sa v praxi neprekrývajú — B2C platí kartou a na
            dobierku, B2B na faktúru.
        </div>
        <div class="mb-6 grid gap-6 xl:grid-cols-2">
            <ChartCard v-for="segment in (['b2c', 'b2b'] as const)" :key="segment" :title="`Platby – ${data.segments[segment].label}`">
                <VueApexCharts
                    type="donut"
                    height="300"
                    :options="donutOptions(data.segments[segment].payment_methods, segment)"
                    :series="data.segments[segment].payment_methods.map((r) => r.share)"
                />
                <DataTable :columns="methodColumns" :rows="methodRows(data.segments[segment].payment_methods)" :export-name="`platby-${segment}`" />
            </ChartCard>
        </div>

        <h2 class="mb-3 text-lg font-semibold">Dopravné preferencie</h2>
        <div class="mb-6 grid gap-6 xl:grid-cols-2">
            <ChartCard v-for="segment in (['b2c', 'b2b'] as const)" :key="segment" :title="`Doprava – ${data.segments[segment].label}`">
                <VueApexCharts
                    type="donut"
                    height="300"
                    :options="donutOptions(data.segments[segment].shipping_methods, segment)"
                    :series="data.segments[segment].shipping_methods.map((r) => r.share)"
                />
                <DataTable
                    :columns="methodColumns"
                    :rows="methodRows(data.segments[segment].shipping_methods)"
                    :export-name="`doprava-${segment}`"
                />
            </ChartCard>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <ChartCard title="Nákupný kanál – B2C" subtitle="Web vs. mobilná aplikácia">
                <DataTable :columns="channelColumns" :rows="channelRows" export-name="kanaly-b2c" />
            </ChartCard>
            <ChartCard v-for="segment in (['b2c', 'b2b'] as const)" :key="segment" :title="`Stavy objednávok – ${data.segments[segment].label}`">
                <DataTable :columns="statusColumns" :rows="data.segments[segment].status_distribution" :export-name="`stavy-${segment}`" />
            </ChartCard>
        </div>
    </AnalyticsLayout>
</template>
