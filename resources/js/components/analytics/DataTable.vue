<script setup lang="ts">
import { downloadCsv } from '@/lib/analytics';

const props = defineProps<{
    title?: string;
    columns: { key: string; label: string; align?: 'right' | 'left' }[];
    rows: Record<string, unknown>[];
    exportName?: string;
}>();

function onExport() {
    const mapped = props.rows.map((r) => Object.fromEntries(props.columns.map((c) => [c.label, r[c.key]])));
    downloadCsv(props.exportName ?? props.title ?? 'tabulka', mapped);
}
</script>

<template>
    <div>
        <div class="mb-2 flex items-center justify-between">
            <h3 v-if="title" class="text-sm font-semibold">{{ title }}</h3>
            <button
                class="rounded-md border border-neutral-300 px-2 py-1 text-xs text-neutral-600 hover:bg-neutral-100"
                type="button"
                @click="onExport"
            >
                ⬇ CSV
            </button>
        </div>
        <div class="overflow-x-auto rounded-lg border border-neutral-200">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th v-for="col in columns" :key="col.key" class="px-3 py-2" :class="col.align === 'right' ? 'text-right' : ''">
                            {{ col.label }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    <tr v-for="(row, i) in rows" :key="i" class="hover:bg-neutral-50">
                        <td
                            v-for="col in columns"
                            :key="col.key"
                            class="px-3 py-2"
                            :class="col.align === 'right' ? 'text-right tabular-nums' : ''"
                        >
                            {{ row[col.key] ?? '–' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
