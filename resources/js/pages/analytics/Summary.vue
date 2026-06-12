<script setup lang="ts">
import AnalyticsLayout from '@/layouts/analytics/AnalyticsLayout.vue';

defineProps<{
    sections: { title: string; items: string[] }[];
    meta: { date_from: string | null; date_to: string | null; imported_at: string | null };
}>();

function print() {
    window.print();
}
</script>

<template>
    <AnalyticsLayout
        title="Zhrnutie pre bakalársku prácu"
        subtitle="Automaticky generovaný sumár kľúčových zistení z aktuálnych dát — podklad pre analytickú kapitolu"
    >
        <div class="mb-6 flex items-center justify-between print:hidden">
            <p class="text-xs text-neutral-500">
                Čísla sa generujú vždy z aktuálneho importu ({{ meta.imported_at }}). Text ber ako podklad — preformuluj ho vlastnými
                slovami a doplň interpretácie.
            </p>
            <button
                class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                type="button"
                @click="print"
            >
                🖨 Tlačiť / uložiť ako PDF
            </button>
        </div>

        <article class="max-w-4xl rounded-xl border border-neutral-200 bg-white p-8 shadow-sm print:border-0 print:shadow-none">
            <h1 class="mb-1 text-xl font-bold">
                Nákupné správanie zákazníkov kancelárskeho e-shopu: porovnanie maloobchodného a veľkoobchodného segmentu
            </h1>
            <p class="mb-6 text-sm text-neutral-500">Zhrnutie kvantitatívnej analýzy · obdobie {{ meta.date_from }} – {{ meta.date_to }}</p>

            <section v-for="(section, i) in sections" :key="section.title" class="mb-6">
                <h2 class="mb-2 text-base font-semibold">{{ i + 1 }}. {{ section.title }}</h2>
                <p v-for="(item, j) in section.items" :key="j" class="mb-2 text-sm leading-relaxed text-neutral-800">
                    {{ item }}
                </p>
            </section>
        </article>
    </AnalyticsLayout>
</template>
