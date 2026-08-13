<script setup>
// Mismo criterio que PrimaryButton.vue: variante compacta para filas de
// tabla (pedido explícito del usuario, "mejorar esos estilos de botones").
defineProps({
    size: { type: String, default: 'md' }, // 'md' | 'sm'
});
</script>

<template>
    <!-- Botón de peligro: usa el color de error de la paleta (rojo coral), no el rojo genérico de Tailwind -->
    <button
        class="inline-flex items-center bg-arka-danger border border-transparent rounded-arka font-semibold text-arka-base hover:opacity-90 active:opacity-100 focus:outline-none focus:ring-2 focus:ring-arka-danger focus:ring-offset-2 focus:ring-offset-arka-base transition ease-in-out duration-150"
        :class="size === 'sm' ? 'px-2.5 py-1 text-[11px] tracking-wide' : 'px-4 py-2 text-xs uppercase tracking-widest'"
    >
        <slot />
    </button>
</template>
