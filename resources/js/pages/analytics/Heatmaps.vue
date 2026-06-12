<script setup lang="ts">
import VueApexCharts from 'vue3-apexcharts';
import AnalyticsLayout from '@/layouts/analytics/AnalyticsLayout.vue';
import { SEGMENT_COLORS, SEGMENT_LABELS, baseChartOptions } from '@/lib/analytics';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Heatmap {
    id: number;
    segment: 'b2c' | 'b2b';
    page_label: string;
    type: 'click' | 'scroll' | 'area';
    device: string | null;
    period_label: string | null;
    notes: string | null;
    csv_data: { headers: string[]; rows: string[][]; meta?: Record<string, string> } | null;
    png_url: string | null;
}

const props = defineProps<{ heatmaps: Heatmap[] }>();

const TYPE_LABELS: Record<string, string> = { click: 'Kliky', scroll: 'Scroll', area: 'Oblasti' };

const form = useForm({
    segment: 'b2c',
    page_label: '',
    type: 'click',
    device: '',
    period_label: '',
    notes: '',
    csv: null as File | null,
    png: null as File | null,
});

function submit() {
    form.post('/heatmapy', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset('csv', 'png', 'notes'),
    });
}

function remove(id: number) {
    if (confirm('Zmazať tento záznam heatmapy?')) {
        router.delete(`/heatmapy/${id}`, { preserveScroll: true });
    }
}

const lightbox = ref<string | null>(null);

// skupiny podľa stránky — vedľa seba B2C a B2B
const groups = computed(() => {
    const map = new Map<string, Heatmap[]>();
    for (const h of props.heatmaps) {
        const list = map.get(h.page_label) ?? [];
        list.push(h);
        map.set(h.page_label, list);
    }
    return [...map.entries()].map(([label, items]) => ({ label, items }));
});

// scroll CSV → krivka (x = hĺbka stránky %, y = % používateľov)
function scrollSeries(h: Heatmap) {
    if (!h.csv_data?.rows?.length) return null;
    const points = h.csv_data.rows
        .map((r) => [parseFloat(r[0]?.replace('%', '')), parseFloat(r[1]?.replace('%', ''))])
        .filter(([x, y]) => Number.isFinite(x) && Number.isFinite(y));
    if (points.length < 3) return null;
    points.sort((a, b) => a[0] - b[0]);
    return points;
}

// porovnávací graf scrollu: ak má skupina scroll CSV pre oba segmenty
function scrollComparison(items: Heatmap[]) {
    const series = [];
    for (const segment of ['b2c', 'b2b'] as const) {
        const h = items.find((i) => i.segment === segment && i.type === 'scroll' && scrollSeries(i));
        if (h) {
            series.push({ name: SEGMENT_LABELS[segment], data: scrollSeries(h)!.map(([x, y]) => ({ x, y })) });
        }
    }
    return series.length >= 1 ? series : null;
}

const scrollChartOptions = baseChartOptions({
    chart: { type: 'line', height: 300 },
    colors: [SEGMENT_COLORS.b2c, SEGMENT_COLORS.b2b],
    stroke: { curve: 'smooth', width: 3 },
    xaxis: { type: 'numeric', title: { text: 'Hĺbka stránky (%)' }, min: 0, max: 100 },
    yaxis: { title: { text: 'Podiel používateľov (%)' }, min: 0, max: 100 },
    legend: { position: 'top' },
});

// Clarity klikové CSV obsahuje plné CSS selektory — skrátime na čitateľný názov prvku
function shortSelector(selector: string): string {
    if (!selector.includes('>')) return selector;
    const parts = selector
        .split('>')
        .map((p) => p.trim().replace(/:nth-of-type\(\d+\)/g, ''))
        .filter(Boolean);
    return parts
        .slice(-2)
        .map((p) => {
            const segments = p.split('.');
            const tag = segments[0] || '?';
            // preferuj sémantické triedy (ikony, tlačidlá) pred Tailwind utilitami
            const semantic = segments.slice(1).find((c) => /^(pi-|fa-|icon|btn|search|cart|kosik|menu|nav|logo|banner|slider|product)/i.test(c));
            return semantic ? `${tag}.${semantic}` : tag;
        })
        .join(' › ');
}

// klikové CSV → top 10 prvkov podľa podielu klikov
function clickTop(h: Heatmap) {
    if (!h.csv_data?.rows?.length) return null;
    const headers = h.csv_data.headers.map((x) => x.toLowerCase());
    const shareIdx = headers.findIndex((x) => x.includes('%'));
    const labelIdx = headers.findIndex((x) => /button|element|prvok/.test(x));
    if (shareIdx < 0 || labelIdx < 0) return null;
    const rows = h.csv_data.rows
        .map((r) => ({ label: shortSelector(r[labelIdx] ?? ''), share: parseFloat((r[shareIdx] ?? '').replace('%', '')) }))
        .filter((r) => Number.isFinite(r.share))
        .slice(0, 10);
    return rows.length ? rows : null;
}

function clickChartOptions(h: Heatmap, segment: 'b2c' | 'b2b') {
    return baseChartOptions({
        chart: { type: 'bar', height: 300 },
        colors: [SEGMENT_COLORS[segment]],
        plotOptions: { bar: { horizontal: true, barHeight: '60%' } },
        xaxis: { categories: clickTop(h)!.map((r) => r.label), title: { text: 'Podiel klikov (%)' } },
        yaxis: { labels: { maxWidth: 220, style: { fontSize: '10px' } } },
    });
}

// metadáta z preambuly Clarity exportu (obdobie, page views, počet klikov)
function metaChips(h: Heatmap): { label: string; value: string }[] {
    const meta = h.csv_data?.meta ?? {};
    const wanted: Record<string, string> = { 'Date range': 'Obdobie', 'Page views': 'Zobrazenia', 'Total clicks': 'Kliky spolu', Metric: 'Metrika' };
    return Object.entries(wanted)
        .filter(([key]) => meta[key])
        .map(([key, label]) => ({ label, value: meta[key] }));
}
</script>

<template>
    <AnalyticsLayout
        title="Heatmapy (Clarity)"
        subtitle="Manuálne exporty z Clarity → Heatmaps → Download (PNG/CSV). Aplikácia páruje B2C a B2B podľa označenia stránky a zo scroll CSV kreslí porovnávacie krivky."
    >
        <section class="mb-8 rounded-xl border border-neutral-200 bg-white p-5 shadow-sm">
            <h2 class="mb-1 text-base font-semibold">Nahrať heatmapu</h2>
            <p class="mb-4 text-xs text-neutral-500">
                V Clarity otvor Heatmaps → vyber stránku, typ a zariadenie → tlačidlo Download (PNG aj CSV). Použi rovnaké
                označenie stránky pre B2C aj B2B verziu (napr. „Homepage"), nech sa spárujú vedľa seba.
            </p>
            <form class="grid grid-cols-2 gap-3 lg:grid-cols-4" @submit.prevent="submit">
                <label class="text-xs font-medium text-neutral-600">
                    Segment
                    <select v-model="form.segment" class="mt-1 w-full rounded-md border-neutral-300 text-sm">
                        <option value="b2c">Maloobchod (B2C)</option>
                        <option value="b2b">Veľkoobchod (B2B)</option>
                    </select>
                </label>
                <label class="text-xs font-medium text-neutral-600">
                    Stránka (párovací názov)
                    <input v-model="form.page_label" required placeholder="napr. Homepage" class="mt-1 w-full rounded-md border-neutral-300 text-sm" />
                </label>
                <label class="text-xs font-medium text-neutral-600">
                    Typ heatmapy
                    <select v-model="form.type" class="mt-1 w-full rounded-md border-neutral-300 text-sm">
                        <option value="click">Kliky</option>
                        <option value="scroll">Scroll</option>
                        <option value="area">Oblasti</option>
                    </select>
                </label>
                <label class="text-xs font-medium text-neutral-600">
                    Zariadenie
                    <select v-model="form.device" class="mt-1 w-full rounded-md border-neutral-300 text-sm">
                        <option value="">—</option>
                        <option>PC</option>
                        <option>Mobile</option>
                        <option>Tablet</option>
                    </select>
                </label>
                <label class="text-xs font-medium text-neutral-600">
                    Obdobie (voliteľné)
                    <input v-model="form.period_label" placeholder="napr. 12.–14.6.2026" class="mt-1 w-full rounded-md border-neutral-300 text-sm" />
                </label>
                <label class="text-xs font-medium text-neutral-600">
                    PNG obrázok
                    <input type="file" accept=".png,.jpg,.jpeg,.webp" class="mt-1 w-full text-xs" @change="form.png = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                </label>
                <label class="text-xs font-medium text-neutral-600">
                    CSV dáta
                    <input type="file" accept=".csv,.txt" class="mt-1 w-full text-xs" @change="form.csv = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                </label>
                <div class="flex items-end">
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                    >
                        {{ form.processing ? 'Nahrávam…' : 'Nahrať' }}
                    </button>
                </div>
                <p v-if="Object.keys(form.errors).length" class="col-span-full text-xs text-red-600">
                    {{ Object.values(form.errors).join(' ') }}
                </p>
            </form>
        </section>

        <p v-if="!groups.length" class="rounded-xl border border-neutral-200 bg-white p-6 text-sm text-neutral-500">
            Zatiaľ žiadne heatmapy. Stiahni prvé exporty z Clarity a nahraj ich formulárom vyššie.
        </p>

        <section v-for="group in groups" :key="group.label" class="mb-10">
            <h2 class="mb-3 text-lg font-semibold">{{ group.label }}</h2>

            <div v-if="scrollComparison(group.items)" class="mb-4 rounded-xl border border-neutral-200 bg-white p-5 shadow-sm">
                <h3 class="mb-2 text-sm font-semibold">Porovnanie scroll hĺbky</h3>
                <VueApexCharts type="line" height="300" :options="scrollChartOptions" :series="scrollComparison(group.items)!" />
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                <div
                    v-for="h in group.items"
                    :key="h.id"
                    class="rounded-xl border bg-white p-4 shadow-sm"
                    :class="h.segment === 'b2c' ? 'border-blue-200' : 'border-amber-200'"
                >
                    <div class="mb-2 flex items-center justify-between">
                        <div class="text-sm">
                            <span class="font-semibold" :class="h.segment === 'b2c' ? 'text-blue-700' : 'text-amber-700'">
                                {{ SEGMENT_LABELS[h.segment] }}
                            </span>
                            <span class="ml-2 rounded bg-neutral-100 px-1.5 py-0.5 text-xs">{{ TYPE_LABELS[h.type] }}</span>
                            <span v-if="h.device" class="ml-1 rounded bg-neutral-100 px-1.5 py-0.5 text-xs">{{ h.device }}</span>
                            <span v-if="h.period_label" class="ml-1 text-xs text-neutral-400">{{ h.period_label }}</span>
                        </div>
                        <button class="text-xs text-neutral-400 hover:text-red-600" type="button" @click="remove(h.id)">✕ zmazať</button>
                    </div>
                    <div v-if="metaChips(h).length" class="mb-2 flex flex-wrap gap-1.5">
                        <span
                            v-for="chip in metaChips(h)"
                            :key="chip.label"
                            class="rounded bg-neutral-50 px-1.5 py-0.5 text-[11px] text-neutral-500"
                        >
                            {{ chip.label }}: <strong class="text-neutral-700">{{ chip.value }}</strong>
                        </span>
                    </div>
                    <div v-if="h.png_url" class="max-h-[28rem] overflow-y-auto rounded-lg border border-neutral-100">
                        <img
                            :src="h.png_url"
                            :alt="`${group.label} – ${h.segment}`"
                            class="h-auto w-full cursor-zoom-in"
                            @click="lightbox = h.png_url"
                        />
                    </div>
                    <p v-if="h.png_url" class="mt-1 text-[11px] text-neutral-400">V náhľade sa dá skrolovať · klik na obrázok = plná veľkosť</p>
                    <div v-if="clickTop(h)" class="mt-2">
                        <h4 class="mb-1 text-xs font-semibold text-neutral-600">Top prvky podľa podielu klikov</h4>
                        <VueApexCharts
                            type="bar"
                            height="300"
                            :options="clickChartOptions(h, h.segment)"
                            :series="[{ name: 'Podiel klikov (%)', data: clickTop(h)!.map((r) => r.share) }]"
                        />
                    </div>
                    <div v-if="h.csv_data?.rows?.length" class="mt-2 max-h-48 overflow-auto rounded border border-neutral-100">
                        <table class="w-full table-fixed text-xs">
                            <thead class="sticky top-0 bg-neutral-50 text-left text-neutral-500">
                                <tr>
                                    <th
                                        v-for="(head, j) in h.csv_data.headers"
                                        :key="head"
                                        class="px-2 py-1"
                                        :class="h.csv_data.headers.length > 2 && j === 1 ? 'w-1/2' : ''"
                                    >
                                        {{ head }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-50">
                                <tr v-for="(row, i) in h.csv_data.rows.slice(0, 50)" :key="i">
                                    <td v-for="(cell, j) in row" :key="j" class="truncate px-2 py-1" :title="cell">
                                        {{ cell.includes('>') ? shortSelector(cell) : cell }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="h.notes" class="mt-2 text-xs text-neutral-500">{{ h.notes }}</p>
                </div>
            </div>
        </section>

        <div
            v-if="lightbox"
            class="fixed inset-0 z-50 cursor-zoom-out overflow-y-auto bg-black/80 p-6"
            @click="lightbox = null"
        >
            <img :src="lightbox" class="mx-auto h-auto w-full max-w-4xl rounded-lg" alt="Heatmapa" />
        </div>
    </AnalyticsLayout>
</template>
