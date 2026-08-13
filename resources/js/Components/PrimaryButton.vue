<script setup>
// Pedido explícito del usuario ("mejorar esos estilos de botones", con
// captura de una tabla admin donde este botón se veía pesado al lado de un
// link de texto chico): variante compacta para filas de tabla — mismo
// color/semántica, menos padding y sin el tracking ancho en mayúsculas que
// tiene sentido en un botón grande y solo, no repetido en cada fila.
defineProps({
    size: { type: String, default: 'md' }, // 'md' | 'sm'
});
</script>

<template>
    <!-- Botón principal: fondo verde menta (color de marca), texto oscuro para contraste -->
    <button
        class="inline-flex items-center bg-arka-primary border border-transparent rounded-arka font-semibold text-arka-base hover:bg-arka-primary-bright focus:bg-arka-primary-bright active:bg-arka-primary focus:outline-none focus:ring-2 focus:ring-arka-primary focus:ring-offset-2 focus:ring-offset-arka-base transition ease-in-out duration-150 disabled:opacity-50"
        :class="size === 'sm' ? 'px-2.5 py-1 text-[11px] tracking-wide' : 'px-4 py-2 text-xs uppercase tracking-widest'"
    >
        <slot />
    </button>
</template>
