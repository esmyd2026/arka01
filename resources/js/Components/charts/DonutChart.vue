<script setup>
import { computed } from 'vue';

// Torta/dona sin librería (no hay ninguna instalada en el proyecto, ver
// Utils/tierBadge.js y Admin/Operations.vue para el mismo criterio de "SVG a
// mano" que ya usa el resto de la app) — técnica de círculos concéntricos con
// stroke-dasharray, sin armar arcos <path> a mano.
const props = defineProps({
    // [{ label, value, color }] — color en hex, no clase Tailwind (hace
    // falta el valor real para el atributo `stroke` del SVG).
    segments: { type: Array, required: true },
    centerLabel: { type: String, default: '' },
});

const RADIUS = 40;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

const total = computed(() => props.segments.reduce((sum, s) => sum + s.value, 0));

const arcs = computed(() => {
    let cumulative = 0;

    return props.segments.map((segment) => {
        const fraction = total.value > 0 ? segment.value / total.value : 0;
        const length = fraction * CIRCUMFERENCE;
        const offset = cumulative;
        cumulative += length;

        return { ...segment, fraction, length, offset };
    });
});
</script>

<template>
    <div class="flex flex-col items-center gap-4">
        <div class="relative h-40 w-40 shrink-0">
            <svg viewBox="0 0 100 100" class="h-full w-full -rotate-90">
                <!-- Riel de fondo: se nota si algún segmento no llega a cubrir el 100%. -->
                <circle cx="50" cy="50" :r="RADIUS" fill="none" stroke="#1c2a24" stroke-width="14" />
                <circle
                    v-for="arc in arcs"
                    :key="arc.label"
                    cx="50"
                    cy="50"
                    :r="RADIUS"
                    fill="none"
                    :stroke="arc.color"
                    stroke-width="14"
                    stroke-linecap="round"
                    :stroke-dasharray="`${arc.length} ${CIRCUMFERENCE - arc.length}`"
                    :stroke-dashoffset="-arc.offset"
                />
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-2xl font-semibold text-arka-text">{{ total }}</span>
                <span v-if="centerLabel" class="text-xs text-arka-text-muted">{{ centerLabel }}</span>
            </div>
        </div>

        <ul class="w-full space-y-1.5">
            <li v-for="arc in arcs" :key="arc.label" class="flex items-center justify-between text-sm">
                <span class="flex items-center gap-2 text-arka-text-muted">
                    <span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{ backgroundColor: arc.color }" />
                    {{ arc.label }}
                </span>
                <span class="text-arka-text font-medium">
                    {{ arc.value }} <span class="text-arka-text-muted font-normal">({{ Math.round(arc.fraction * 100) }}%)</span>
                </span>
            </li>
        </ul>
    </div>
</template>
