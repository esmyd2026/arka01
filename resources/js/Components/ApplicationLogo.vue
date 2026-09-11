<script setup>
import { computed } from 'vue';

// El isotipo original se acompaña con texto semántico: oscuro en el tema claro
// y blanco suave en el tema oscuro, sin añadir una placa negra artificial.
const props = defineProps({
    size: { type: String, default: 'h-8' },
});

// El isotipo sí tiene transparencia real y funciona tanto en encabezados
// compactos como en el hero sin añadir fondos artificiales.
const LOGO_SRC = '/img/logo-arka01-icono.png';

const textSizeClass = computed(() => {
    if (props.size.includes('h-24') || props.size.includes('h-28')) return 'text-5xl sm:text-6xl';
    if (props.size.includes('h-14') || props.size.includes('h-16')) return 'text-4xl sm:text-5xl';
    if (props.size.includes('h-11')) return 'text-3xl sm:text-4xl';
    if (props.size.includes('h-10')) return 'text-2xl';
    if (props.size.includes('h-9')) return 'text-xl sm:text-2xl';
    if (props.size.includes('h-6') || props.size.includes('h-7')) return 'text-base sm:text-lg';
    return 'text-xl';
});
</script>

<template>
    <span class="inline-flex w-auto select-none items-center gap-1.5" :class="size" aria-label="Arka01">
        <img :src="LOGO_SRC" alt="" class="h-full w-auto object-contain" />
        <span class="font-black leading-none tracking-[-0.065em] text-arka-text" :class="textSizeClass">
            Arka<span class="text-arka-primary">01</span>
        </span>
    </span>
</template>
