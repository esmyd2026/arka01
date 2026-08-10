<script setup>
import { computed } from 'vue';

// Barras sin librería (mismo criterio que Admin/Operations.vue: "una barra
// con ancho proporcional alcanza", acá en vertical porque es una serie en el
// tiempo, un día detrás del otro).
const props = defineProps({
    data: { type: Array, required: true }, // [{ label, value }]
    valuePrefix: { type: String, default: '' },
});

const max = computed(() => Math.max(1, ...props.data.map((d) => d.value)));
</script>

<template>
    <div v-if="data.length" class="overflow-x-auto">
        <div class="flex items-end gap-2 h-40 min-w-max px-1">
            <div v-for="point in data" :key="point.label" class="flex flex-col items-center justify-end gap-1 w-8 h-full shrink-0" :title="`${point.label}: ${valuePrefix}${point.value}`">
                <span class="text-[10px] text-arka-text-muted leading-none">{{ point.value }}</span>
                <div class="w-full bg-arka-base/60 rounded-t-full flex items-end flex-1 overflow-hidden">
                    <div
                        class="w-full bg-arka-primary rounded-t-full transition-all"
                        :style="{ height: `${Math.max(4, (point.value / max) * 100)}%` }"
                    />
                </div>
                <span class="text-[10px] text-arka-text-muted leading-none whitespace-nowrap">{{ point.label }}</span>
            </div>
        </div>
    </div>
    <p v-else class="text-sm text-arka-text-muted">Todavía no hay datos para este rango.</p>
</template>
