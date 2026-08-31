<script setup>
import { computed } from 'vue';

const props = defineProps({
    trust: { type: Object, default: null },
    compact: { type: Boolean, default: false },
});

const title = computed(() => {
    if (!props.trust) return '';
    const mutual = props.trust.mutual_people > 0 ? ` · ${props.trust.mutual_people} en común` : '';
    // Pedido explícito del usuario: "no coloques 50/100 solo deja el 50%" —
    // el score ya viene 0-100 desde TrustIndexCalculator, se muestra directo
    // como porcentaje en vez de fracción, acá y en cualquier otra pantalla
    // que use este mismo componente.
    return `Índice de confianza: ${props.trust.score}% · ${props.trust.level}${mutual}`;
});
</script>

<template>
    <span
        v-if="trust"
        class="inline-flex shrink-0 items-center rounded-full border border-arka-primary/25 bg-arka-primary/10 font-semibold text-arka-primary-bright"
        :class="compact ? 'gap-1 px-2 py-0.5 text-[10px]' : 'gap-1.5 px-2.5 py-1 text-xs'"
        :title="title"
        :aria-label="title"
    >
        <svg :class="compact ? 'h-3 w-3' : 'h-3.5 w-3.5'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3 5 6v5c0 4.6 2.9 8 7 10 4.1-2 7-5.4 7-10V6l-7-3Z" />
            <path stroke-linecap="round" d="m9.3 12 1.8 1.8 3.8-4" />
        </svg>
        Confianza {{ trust.score }}%
        <span v-if="!compact && trust.mutual_people > 0" class="text-arka-text-muted">· {{ trust.mutual_people }} en común</span>
    </span>
</template>
