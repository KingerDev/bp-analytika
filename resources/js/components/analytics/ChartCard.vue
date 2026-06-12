<script setup lang="ts">
defineProps<{
    title: string;
    subtitle?: string;
}>();
</script>

<template>
    <section class="rounded-xl border border-neutral-200 bg-white p-5 shadow-sm">
        <div class="mb-4">
            <h2 class="text-base font-semibold">{{ title }}</h2>
            <p v-if="subtitle" class="mt-0.5 text-xs text-neutral-500">{{ subtitle }}</p>
        </div>
        <slot />
    </section>
</template>
