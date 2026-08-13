<script setup>
// Mismo criterio que PrimaryButton.vue: variante compacta para filas de
// tabla (pedido explícito del usuario, "mejorar esos estilos de botones").
defineProps({
    type: {
        type: String,
        default: 'button',
    },
    size: { type: String, default: 'md' }, // 'md' | 'sm'
});
</script>

<template>
    <!-- Botón secundario: superficie de tarjeta con borde sutil, para acciones no principales -->
    <button
        :type="type"
        class="inline-flex items-center bg-arka-card border border-arka-text-muted/30 rounded-arka font-semibold text-arka-text shadow-sm hover:bg-arka-base focus:outline-none focus:ring-2 focus:ring-arka-primary focus:ring-offset-2 focus:ring-offset-arka-base disabled:opacity-25 transition ease-in-out duration-150"
        :class="size === 'sm' ? 'px-2.5 py-1 text-[11px] tracking-wide' : 'px-4 py-2 text-xs uppercase tracking-widest'"
    >
        <slot />
    </button>
</template>
