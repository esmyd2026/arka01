<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <!-- Botón secundario: superficie de tarjeta con borde sutil, para acciones no principales -->
    <button
        :type="type"
        class="inline-flex items-center px-4 py-2 bg-arka-card border border-arka-text-muted/30 rounded-arka font-semibold text-xs text-arka-text uppercase tracking-widest shadow-sm hover:bg-arka-base focus:outline-none focus:ring-2 focus:ring-arka-primary focus:ring-offset-2 focus:ring-offset-arka-base disabled:opacity-25 transition ease-in-out duration-150"
    >
        <slot />
    </button>
</template>
