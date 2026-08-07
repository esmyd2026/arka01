<script setup>
// Logotipo tipográfico de la marca (sección 9.9): dos colores, sin ícono
// genérico — antes acá vivía el logo por defecto de Laravel/Breeze, que no
// tenía nada que ver con la identidad de Arka01.
defineProps({
    // Tamaño de texto (clase Tailwind `text-*`), para poder pedir variantes
    // más grandes en pantallas como el login o la portada.
    size: { type: String, default: 'text-2xl' },
});
</script>

<template>
    <span class="inline-flex items-baseline font-extrabold tracking-tight leading-none select-none" :class="size">
        <span class="text-arka-text">Arka</span><span class="text-arka-primary">01</span>
    </span>
</template>
