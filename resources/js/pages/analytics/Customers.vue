<script setup lang="ts">
import VueApexCharts from 'vue3-apexcharts';
import AnalyticsLayout from '@/layouts/analytics/AnalyticsLayout.vue';
import ChartCard from '@/components/analytics/ChartCard.vue';
import DataTable from '@/components/analytics/DataTable.vue';
import { SEGMENT_COLORS, baseChartOptions, formatEur, formatNumber, formatPct } from '@/lib/analytics';
import { computed } from 'vue';

interface SectorStats {
    label: string;
    orgs: number;
    customers: number;
    orders: number;
    revenue: number;
    aov: number;
    avg_items: number;
    median_approval_hours: number | null;
}

interface Concentration {
    unit_label: string;
    units: number;
    gini: number | null;
    top20_share: number;
    lorenz: { x: number; y: number }[];
    abc: { counts: Record<string, number>; shares: Record<string, number> };
}

const props = defineProps<{
    data: {
        public_sector: { public?: SectorStats; private?: SectorStats; aov_test: { p_formatted: string; significant: boolean } | null };
        public_sector_seasonality: { labels: string[]; series: Record<string, number[]> };
        organizations: {
            total_orgs: number;
            avg_users_per_org: number;
            multi_user_share: number;
            buckets: { labels: string[]; counts: number[] };
            top: { code: string; type: string; users: number; orders: number; revenue: number }[];
        };
        concentration: { b2c: Concentration; b2b: Concentration };
    };
    meta: Record<string, unknown>;
}>();

const ps = props.data.public_sector;

const sectorColumns = [
    { key: 'metric', label: 'Metrika' },
    { key: 'public', label: 'Verejná správa', align: 'right' as const },
    { key: 'private', label: 'Súkromné firmy', align: 'right' as const },
];
const sectorRows = computed(() => {
    if (!ps.public || !ps.private) return [];
    return [
        { metric: 'Organizácie', public: formatNumber(ps.public.orgs), private: formatNumber(ps.private.orgs) },
        { metric: 'Zákaznícke účty', public: formatNumber(ps.public.customers), private: formatNumber(ps.private.customers) },
        { metric: 'Objednávky', public: formatNumber(ps.public.orders), private: formatNumber(ps.private.orders) },
        { metric: 'Tržby (bez DPH)', public: formatEur(ps.public.revenue), private: formatEur(ps.private.revenue) },
        { metric: 'Priemerná objednávka', public: formatEur(ps.public.aov), private: formatEur(ps.private.aov) },
        { metric: 'Ø položiek na objednávku', public: formatNumber(ps.public.avg_items, 1), private: formatNumber(ps.private.avg_items, 1) },
        {
            metric: 'Medián schvaľovania (h)',
            public: formatNumber(ps.public.median_approval_hours, 1),
            private: formatNumber(ps.private.median_approval_hours, 1),
        },
    ];
});

const seasonalityOptions = baseChartOptions({
    chart: { type: 'bar', height: 320 },
    colors: ['#7c3aed', '#f59e0b'],
    plotOptions: { bar: { columnWidth: '60%' } },
    xaxis: { categories: props.data.public_sector_seasonality.labels },
    yaxis: { title: { text: 'Podiel objednávok (%)' } },
    legend: { position: 'top' },
});
const seasonalitySeries = [
    { name: 'Verejná správa', data: props.data.public_sector_seasonality.series.public },
    { name: 'Súkromné firmy', data: props.data.public_sector_seasonality.series.private },
];

const orgBucketOptions = baseChartOptions({
    chart: { type: 'bar', height: 280 },
    colors: [SEGMENT_COLORS.b2b],
    plotOptions: { bar: { columnWidth: '60%' } },
    xaxis: { categories: props.data.organizations.buckets.labels, title: { text: 'Počet zákazníckych účtov organizácie' } },
    yaxis: { title: { text: 'Počet organizácií' } },
});

const lorenzOptions = baseChartOptions({
    chart: { type: 'line', height: 340 },
    colors: [SEGMENT_COLORS.b2c, SEGMENT_COLORS.b2b, '#9ca3af'],
    stroke: { curve: 'straight', width: [3, 3, 2], dashArray: [0, 0, 6] },
    xaxis: { type: 'numeric', min: 0, max: 100, title: { text: 'Top % zákazníkov / organizácií (zoradené podľa tržieb)' } },
    yaxis: { min: 0, max: 100, title: { text: 'Kumulatívny podiel tržieb (%)' } },
    legend: { position: 'top' },
});
const lorenzSeries = computed(() => [
    { name: `B2C zákazníci (Gini ${formatNumber(props.data.concentration.b2c.gini, 2)})`, data: props.data.concentration.b2c.lorenz.map((p) => ({ x: p.x, y: p.y })) },
    { name: `B2B organizácie (Gini ${formatNumber(props.data.concentration.b2b.gini, 2)})`, data: props.data.concentration.b2b.lorenz.map((p) => ({ x: p.x, y: p.y })) },
    { name: 'Rovnomerné rozdelenie', data: [{ x: 0, y: 0 }, { x: 100, y: 100 }] },
]);

const abcColumns = [
    { key: 'class', label: 'Trieda' },
    { key: 'rule', label: 'Definícia' },
    { key: 'b2c', label: 'B2C zákazníci', align: 'right' as const },
    { key: 'b2b', label: 'B2B organizácie', align: 'right' as const },
];
const abcRows = computed(() => {
    const c = props.data.concentration;
    return (['A', 'B', 'C'] as const).map((cls) => ({
        class: cls,
        rule: cls === 'A' ? 'tvorí 80 % tržieb' : cls === 'B' ? 'ďalších 15 % tržieb' : 'posledných 5 % tržieb',
        b2c: `${formatNumber(c.b2c.abc.counts[cls])} (${formatPct(c.b2c.abc.shares[cls])})`,
        b2b: `${formatNumber(c.b2b.abc.counts[cls])} (${formatPct(c.b2b.abc.shares[cls])})`,
    }));
});

const topOrgColumns = [
    { key: 'code', label: 'Organizácia' },
    { key: 'type', label: 'Typ' },
    { key: 'users', label: 'Účty', align: 'right' as const },
    { key: 'orders', label: 'Objednávky', align: 'right' as const },
    { key: 'revenue_fmt', label: 'Tržby', align: 'right' as const },
];
const topOrgRows = computed(() => props.data.organizations.top.map((o) => ({ ...o, revenue_fmt: formatEur(o.revenue) })));
</script>

<template>
    <AnalyticsLayout
        title="Zákazníci a koncentrácia tržieb"
        subtitle="Štruktúra B2B zákazníkov (verejná správa vs. firmy, organizácie a nákupné centrá) a koncentrácia tržieb oboch segmentov"
    >
        <h2 class="mb-3 text-lg font-semibold">Koncentrácia tržieb (Pareto)</h2>
        <div class="mb-6 grid gap-6 xl:grid-cols-3">
            <div class="xl:col-span-2">
                <ChartCard
                    title="Lorenzova krivka"
                    subtitle="Aký podiel tržieb tvorí top X % zákazníkov (B2C) resp. organizácií (B2B); čím ďalej od diagonály, tým väčšia koncentrácia"
                >
                    <VueApexCharts type="line" height="340" :options="lorenzOptions" :series="lorenzSeries" />
                </ChartCard>
            </div>
            <div class="space-y-4">
                <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm">
                    <div class="text-xs font-medium uppercase text-neutral-500">Top 20 % jednotiek tvorí</div>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        <div>
                            <div class="text-[11px] font-medium text-blue-600">B2C zákazníci</div>
                            <div class="text-xl font-bold">{{ formatPct(data.concentration.b2c.top20_share) }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] font-medium text-amber-600">B2B organizácie</div>
                            <div class="text-xl font-bold">{{ formatPct(data.concentration.b2b.top20_share) }}</div>
                        </div>
                    </div>
                    <div class="mt-1 text-[11px] text-neutral-400">podiel na tržbách segmentu</div>
                </div>
                <ChartCard title="ABC analýza">
                    <DataTable :columns="abcColumns" :rows="abcRows" export-name="abc-analyza" />
                </ChartCard>
            </div>
        </div>

        <h2 class="mb-3 text-lg font-semibold">Verejná správa vs. súkromné firmy (B2B)</h2>
        <div
            v-if="ps.aov_test"
            class="mb-4 rounded-xl border p-4 text-sm"
            :class="ps.aov_test.significant ? 'border-green-200 bg-green-50 text-green-900' : 'border-neutral-200 bg-white text-neutral-700'"
        >
            Rozdiel hodnôt objednávok medzi verejnou správou a firmami {{ ps.aov_test.significant ? 'je' : 'nie je' }} štatisticky
            významný (Mann-Whitneyho U test, {{ ps.aov_test.p_formatted }}).
        </div>
        <div class="mb-6 grid gap-6 xl:grid-cols-2">
            <ChartCard title="Porovnanie metrík">
                <DataTable :columns="sectorColumns" :rows="sectorRows" export-name="verejna-sprava-vs-firmy" />
            </ChartCard>
            <ChartCard
                title="Sezónnosť objednávok"
                subtitle="Podiel objednávok podľa mesiaca — rozpočtové cykly verejnej správy vs. priebežný dopyt firiem"
            >
                <VueApexCharts type="bar" height="320" :options="seasonalityOptions" :series="seasonalitySeries" />
            </ChartCard>
        </div>

        <h2 class="mb-3 text-lg font-semibold">Organizácie a nákupné centrá (B2B)</h2>
        <div class="mb-4 grid grid-cols-3 gap-4">
            <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-medium uppercase text-neutral-500">Organizácie s objednávkou</div>
                <div class="mt-1 text-2xl font-bold">{{ formatNumber(data.concentration.b2b.units) }}</div>
            </div>
            <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-medium uppercase text-neutral-500">Ø účtov na organizáciu</div>
                <div class="mt-1 text-2xl font-bold">{{ formatNumber(data.organizations.avg_users_per_org, 2) }}</div>
            </div>
            <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm">
                <div class="text-xs font-medium uppercase text-neutral-500">Organizácie s 2+ účtami</div>
                <div class="mt-1 text-2xl font-bold">{{ formatPct(data.organizations.multi_user_share) }}</div>
                <div class="text-[11px] text-neutral-400">indikátor nákupného centra</div>
            </div>
        </div>
        <div class="grid gap-6 xl:grid-cols-2">
            <ChartCard title="Veľkosť nákupného centra" subtitle="Rozdelenie organizácií podľa počtu zákazníckych účtov">
                <VueApexCharts
                    type="bar"
                    height="280"
                    :options="orgBucketOptions"
                    :series="[{ name: 'Organizácie', data: data.organizations.buckets.counts }]"
                />
            </ChartCard>
            <ChartCard title="Top 10 organizácií podľa tržieb" subtitle="Anonymizované poradové označenie">
                <DataTable :columns="topOrgColumns" :rows="topOrgRows" export-name="top-organizacie" />
            </ChartCard>
        </div>
    </AnalyticsLayout>
</template>
