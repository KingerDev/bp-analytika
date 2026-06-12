<script setup lang="ts">
import { Link, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    title: string;
    subtitle?: string;
}>();

interface Meta {
    period_months: number;
    date_from: string | null;
    date_to: string | null;
    imported_at: string | null;
}

const page = usePage();
const meta = computed(() => (page.props.meta ?? null) as Meta | null);

const nav = [
    { href: '/prehlad', label: 'Prehľad KPI', icon: '📊' },
    { href: '/rfm', label: 'RFM a retencia', icon: '👥' },
    { href: '/casove-vzorce', label: 'Časové vzorce', icon: '🕐' },
    { href: '/produkty', label: 'Produkty', icon: '📦' },
    { href: '/nakupny-proces', label: 'Nákupný proces', icon: '🛒' },
    { href: '/zakaznici', label: 'Zákazníci a koncentrácia', icon: '🏢' },
    { href: '/clarity', label: 'Clarity (web)', icon: '🖱️' },
    { href: '/heatmapy', label: 'Heatmapy', icon: '🔥' },
    { href: '/zhrnutie', label: 'Zhrnutie pre BP', icon: '📝' },
];

const isActive = (href: string) => page.url.startsWith(href);
</script>

<template>
    <Head :title="props.title" />
    <div class="flex min-h-screen bg-neutral-50 text-neutral-900">
        <aside class="fixed inset-y-0 left-0 w-60 border-r border-neutral-200 bg-white print:hidden">
            <div class="border-b border-neutral-200 px-5 py-4">
                <div class="text-lg font-bold">BP Analytika</div>
                <div class="text-xs text-neutral-500">Nákupné správanie B2C vs B2B</div>
            </div>
            <nav class="space-y-1 p-3">
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition"
                    :class="isActive(item.href) ? 'bg-blue-50 text-blue-700' : 'text-neutral-600 hover:bg-neutral-100'"
                >
                    <span>{{ item.icon }}</span>
                    <span>{{ item.label }}</span>
                </Link>
            </nav>
            <div class="absolute bottom-0 w-full border-t border-neutral-200 p-4 text-xs text-neutral-500">
                <div class="mb-2 flex items-center gap-3">
                    <span class="flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-full bg-blue-600"></span> B2C</span>
                    <span class="flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-full bg-amber-500"></span> B2B</span>
                </div>
                <template v-if="meta?.date_from">
                    <div>Obdobie: {{ meta.date_from }} – {{ meta.date_to }}</div>
                    <div>Import: {{ meta.imported_at }}</div>
                </template>
            </div>
        </aside>

        <main class="ml-60 flex-1 p-8 print:ml-0">
            <header class="mb-6">
                <h1 class="text-2xl font-bold">{{ props.title }}</h1>
                <p v-if="props.subtitle" class="mt-1 text-sm text-neutral-500">{{ props.subtitle }}</p>
            </header>
            <slot />
        </main>
    </div>
</template>
