<script setup>
defineProps({
    label: { type: String, default: null },
});
</script>

<template>
    <span
        v-if="label"
        class="inline-flex items-center gap-1 rounded-full border border-arka-primary/25 bg-arka-primary/10 px-2 py-0.5 text-[10px] font-semibold text-arka-primary-bright"
    >
        <svg class="h-3 w-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3 5.5 5.5v5.8c0 4.2 2.7 7.9 6.5 9.7 3.8-1.8 6.5-5.5 6.5-9.7V5.5L12 3Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4" />
        </svg>
        {{ label }}
    </span>
</template>
