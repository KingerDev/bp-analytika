<script setup lang="ts">
defineProps<{
    label: string;
    b2c: string;
    b2b: string;
    hint?: string;
}>();
</script>

<template>
    <div class="rounded-xl border border-neutral-200 bg-white p-4 shadow-sm">
        <div class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ label }}</div>
        <div class="mt-2 grid grid-cols-2 gap-2">
            <div>
                <div class="text-[11px] font-medium text-blue-600">B2C</div>
                <div class="text-lg font-bold tabular-nums">{{ b2c }}</div>
            </div>
            <div>
                <div class="text-[11px] font-medium text-amber-600">B2B</div>
                <div class="text-lg font-bold tabular-nums">{{ b2b }}</div>
            </div>
        </div>
        <div v-if="hint" class="mt-1 text-[11px] text-neutral-400">{{ hint }}</div>
    </div>
</template>
