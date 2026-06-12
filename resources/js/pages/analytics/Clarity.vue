<script setup lang="ts">
import VueApexCharts from 'vue3-apexcharts';
import AnalyticsLayout from '@/layouts/analytics/AnalyticsLayout.vue';
import ChartCard from '@/components/analytics/ChartCard.vue';
import CompareStat from '@/components/analytics/CompareStat.vue';
import DataTable from '@/components/analytics/DataTable.vue';
import { SEGMENT_COLORS, SEGMENT_LABELS, baseChartOptions, formatNumber, formatPct } from '@/lib/analytics';
import { computed } from 'vue';

interface Snapshot {
    segment: 'b2c' | 'b2b';
    captured_on: string;
    num_days: number;
    sessions: number;
    bot_sessions: number;
    users: number;
    pages_per_session: number | null;
    engagement_avg_seconds: number | null;
    active_avg_seconds: number | null;
    scroll_depth: number | null;
    dead_clicks: number;
    rage_clicks: number;
    quick_backs: number;
    excessive_scrolls: number;
    script_errors: number;
    error_clicks: number;
    devices: { device: string; sessions: number }[] | null;
    top_pages: { url: string; visits: number }[] | null;
}

interface HistoryRow {
    segment: 'b2c' | 'b2b';
    captured_on: string;
    sessions: number;
    users: number;
    pages_per_session: number | null;
    active_avg_seconds: number | null;
    rage_clicks: number;
    dead_clicks: number;
    script_errors: number;
}

const props = defineProps<{
    latest: { b2c: Snapshot | null; b2b: Snapshot | null };
    history: HistoryRow[];
    runs: { segment: string; status: string; sessions: number | null; message: string | null; ran_at: string }[];
    health: Record<'b2c' | 'b2b', { last_success: string | null; stale: boolean }>;
    configured: { b2c: boolean; b2b: boolean };
    meta: Record<string, unknown>;
}>();

const anyStale = ['b2c', 'b2b'].some((s) => props.health[s as 'b2c' | 'b2b'].stale);

const b2c = props.latest.b2c;
const b2b = props.latest.b2b;

// B2B tracking je nespoľahlivý, kým sessions ≈ pageviews (cookieless režim)
const b2bTrackingBroken = computed(() => b2b !== null && b2b.pages_per_session !== null && b2b.pages_per_session <= 1.05);

const fmtSeconds = (v: number | null | undefined) => (v === null || v === undefined ? '–' : `${formatNumber(v, 0)} s`);

// klikové metriky na 1 000 relácií, aby boli rôzne veľké segmenty porovnateľné
const per1k = (count: number | undefined, sessions: number | undefined) =>
    !sessions || count === undefined ? '–' : formatNumber((1000 * count) / sessions, 1);

function deviceOptions(snapshot: Snapshot, segment: 'b2c' | 'b2b') {
    const devices = (snapshot.devices ?? []).filter((d) => d.sessions > 0);
    return baseChartOptions({
        chart: { type: 'donut', height: 280 },
        labels: devices.map((d) => d.device),
        legend: { position: 'bottom' },
        theme: { monochrome: { enabled: true, color: SEGMENT_COLORS[segment], shadeIntensity: 0.85 } },
        dataLabels: { enabled: true, formatter: (v: number) => `${v.toFixed(1)} %` },
    });
}
const deviceSeries = (snapshot: Snapshot) => (snapshot.devices ?? []).filter((d) => d.sessions > 0).map((d) => d.sessions);

const pageColumns = [
    { key: 'path', label: 'Stránka' },
    { key: 'visits', label: 'Relácie', align: 'right' as const },
];
const pageRows = (snapshot: Snapshot) =>
    (snapshot.top_pages ?? []).map((p) => ({ path: p.url.replace(/^https?:\/\/[^/]+/, '') || '/', visits: p.visits }));

// vývoj v čase z archivovaných snapshotov
const historyDates = computed(() => [...new Set(props.history.map((h) => h.captured_on.slice(0, 10)))].sort());
const hasHistory = computed(() => historyDates.value.length > 1);

function historySeries(field: keyof HistoryRow) {
    return (['b2c', 'b2b'] as const).map((segment) => ({
        name: SEGMENT_LABELS[segment],
        data: historyDates.value.map((d) => {
            const row = props.history.find((h) => h.segment === segment && h.captured_on.startsWith(d));
            return row ? Number(row[field] ?? 0) : null;
        }),
    }));
}

const historyOptions = (yTitle: string) =>
    baseChartOptions({
        chart: { type: 'line', height: 280 },
        colors: [SEGMENT_COLORS.b2c, SEGMENT_COLORS.b2b],
        stroke: { curve: 'smooth', width: 3 },
        xaxis: { categories: historyDates.value },
        yaxis: { title: { text: yTitle } },
        legend: { position: 'top' },
    });
</script>

<template>
    <AnalyticsLayout
        title="Behaviorálne dáta z webu (Microsoft Clarity)"
        subtitle="Správanie návštevníkov: zariadenia, engagement, scroll, problémové interakcie — denné snapshoty z Clarity Data Export API"
    >
        <div v-if="anyStale" class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">
            🔴 <strong>Pozor: snapshoty nebežia!</strong>
            <template v-for="segment in (['b2c', 'b2b'] as const)" :key="segment">
                <span v-if="health[segment].stale">
                    {{ segment.toUpperCase() }}: posledný úspešný snapshot
                    {{ health[segment].last_success ? health[segment].last_success : 'nikdy' }}.
                </span>
            </template>
            Clarity API vidí len 3 dni dozadu — ak snapshoty nepobežia, v trendoch vznikne nenahraditeľná diera. Skontroluj cron na
            hostingu (<code class="rounded bg-red-100 px-1">php artisan analytics:clarity-snapshot</code>).
        </div>

        <div
            v-if="b2bTrackingBroken"
            class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
        >
            ⚠️ <strong>B2B tracking zatiaľ beží v cookieless režime</strong> — relácie sa nespájajú (presne 1,00 strany na reláciu),
            takže počty relácií, používateľov a engagement B2B nie sú spoľahlivé. Platné sú metriky viazané na zobrazenia stránky
            (rage/dead clicks, script errors, zariadenia). Po nasadení <code class="rounded bg-amber-100 px-1">clarity('consent')</code>
            sa od ďalšieho snapshotu začnú zbierať korektné dáta.
        </div>

        <div v-if="!b2c && !b2b" class="rounded-xl border border-neutral-200 bg-white p-6 text-sm text-neutral-600">
            Zatiaľ žiadne snapshoty. Spusti <code class="rounded bg-neutral-100 px-1">php artisan analytics:clarity-snapshot</code>
            {{ configured.b2c && configured.b2b ? '' : '(najprv doplň CLARITY_*_TOKEN do .env)' }}.
        </div>

        <template v-if="b2c && b2b">
            <p class="mb-3 text-xs text-neutral-500">
                Posledný snapshot: {{ b2c.captured_on.slice(0, 10) }} (okno {{ b2c.num_days }} dni). Klikové metriky sú prepočítané na
                1 000 relácií kvôli porovnateľnosti segmentov.
            </p>
            <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                <CompareStat label="Relácie" :b2c="formatNumber(b2c.sessions)" :b2b="formatNumber(b2b.sessions)" />
                <CompareStat label="Používatelia" :b2c="formatNumber(b2c.users)" :b2b="formatNumber(b2b.users)" />
                <CompareStat
                    label="Strán na reláciu"
                    :b2c="formatNumber(b2c.pages_per_session, 2)"
                    :b2b="formatNumber(b2b.pages_per_session, 2)"
                />
                <CompareStat
                    label="Aktívny čas na relácii"
                    :b2c="fmtSeconds(b2c.active_avg_seconds)"
                    :b2b="fmtSeconds(b2b.active_avg_seconds)"
                    :hint="`celkový čas: ${fmtSeconds(b2c.engagement_avg_seconds)} / ${fmtSeconds(b2b.engagement_avg_seconds)}`"
                />
                <CompareStat
                    label="Scroll depth"
                    :b2c="formatPct(b2c.scroll_depth)"
                    :b2b="formatPct(b2b.scroll_depth)"
                />
                <CompareStat
                    label="Rage clicks / 1000 relácií"
                    :b2c="per1k(b2c.rage_clicks, b2c.sessions)"
                    :b2b="per1k(b2b.rage_clicks, b2b.sessions)"
                    :hint="`absolútne: ${formatNumber(b2c.rage_clicks)} / ${formatNumber(b2b.rage_clicks)}`"
                />
                <CompareStat
                    label="Dead clicks / 1000 relácií"
                    :b2c="per1k(b2c.dead_clicks, b2c.sessions)"
                    :b2b="per1k(b2b.dead_clicks, b2b.sessions)"
                    :hint="`absolútne: ${formatNumber(b2c.dead_clicks)} / ${formatNumber(b2b.dead_clicks)}`"
                />
                <CompareStat
                    label="Script errors"
                    :b2c="formatNumber(b2c.script_errors)"
                    :b2b="formatNumber(b2b.script_errors)"
                    :hint="`quick backs: ${formatNumber(b2c.quick_backs)} / ${formatNumber(b2b.quick_backs)}`"
                />
            </div>

            <div class="mb-6 grid gap-6 xl:grid-cols-2">
                <ChartCard
                    v-for="segment in (['b2c', 'b2b'] as const)"
                    :key="segment"
                    :title="`Zariadenia – ${SEGMENT_LABELS[segment]}`"
                    subtitle="Podiel relácií podľa zariadenia"
                >
                    <VueApexCharts
                        type="donut"
                        height="280"
                        :options="deviceOptions(latest[segment]!, segment)"
                        :series="deviceSeries(latest[segment]!)"
                    />
                </ChartCard>
            </div>

            <div class="mb-6 grid gap-6 xl:grid-cols-2">
                <ChartCard
                    v-for="segment in (['b2c', 'b2b'] as const)"
                    :key="segment"
                    :title="`Najnavštevovanejšie stránky – ${SEGMENT_LABELS[segment]}`"
                >
                    <DataTable :columns="pageColumns" :rows="pageRows(latest[segment]!)" :export-name="`clarity-stranky-${segment}`" />
                </ChartCard>
            </div>

            <div v-if="hasHistory" class="grid gap-6 xl:grid-cols-2">
                <ChartCard title="Vývoj relácií" subtitle="Z denných snapshotov (3-dňové okná sa prekrývajú)">
                    <VueApexCharts type="line" height="280" :options="historyOptions('Relácie')" :series="historySeries('sessions')" />
                </ChartCard>
                <ChartCard title="Vývoj rage clicks" subtitle="Indikátor frustrácie používateľov v čase">
                    <VueApexCharts type="line" height="280" :options="historyOptions('Rage clicks')" :series="historySeries('rage_clicks')" />
                </ChartCard>
            </div>
            <div v-else class="rounded-xl border border-neutral-200 bg-white p-4 text-xs text-neutral-500">
                Trendy v čase sa zobrazia, keď bude archivovaných viac denných snapshotov — spúšťaj
                <code class="rounded bg-neutral-100 px-1">php artisan analytics:clarity-snapshot</code> raz denne
                (Clarity API vracia len posledné 3 dni; limit 10 requestov/projekt/deň, jeden snapshot = 2 requesty).
            </div>
        </template>

        <section class="mt-6 rounded-xl border border-neutral-200 bg-white p-5 shadow-sm">
            <div class="mb-3 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold">História sťahovania snapshotov</h2>
                    <p class="text-xs text-neutral-500">Kontrola automatizácie — každý pokus o stiahnutie (aj neúspešný)</p>
                </div>
                <div class="flex gap-3 text-xs">
                    <span v-for="segment in (['b2c', 'b2b'] as const)" :key="segment" class="flex items-center gap-1">
                        <span class="inline-block h-2 w-2 rounded-full" :class="health[segment].stale ? 'bg-red-500' : 'bg-green-500'"></span>
                        {{ segment.toUpperCase() }}:
                        {{ health[segment].last_success ?? 'zatiaľ nikdy' }}
                    </span>
                </div>
            </div>
            <p v-if="!runs.length" class="text-sm text-neutral-500">Zatiaľ žiadne zaznamenané behy.</p>
            <div v-else class="overflow-x-auto rounded-lg border border-neutral-200">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                        <tr>
                            <th class="px-3 py-2">Čas</th>
                            <th class="px-3 py-2">Segment</th>
                            <th class="px-3 py-2">Výsledok</th>
                            <th class="px-3 py-2 text-right">Relácie</th>
                            <th class="px-3 py-2">Chyba</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-for="(run, i) in runs" :key="i">
                            <td class="px-3 py-2 tabular-nums">{{ run.ran_at }}</td>
                            <td class="px-3 py-2 uppercase">{{ run.segment }}</td>
                            <td class="px-3 py-2">
                                <span
                                    class="rounded px-1.5 py-0.5 text-xs font-medium"
                                    :class="run.status === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                                >
                                    {{ run.status === 'success' ? '✓ úspech' : '✗ zlyhanie' }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ run.sessions ?? '–' }}</td>
                            <td class="max-w-md truncate px-3 py-2 text-xs text-red-700" :title="run.message ?? ''">{{ run.message ?? '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AnalyticsLayout>
</template>
