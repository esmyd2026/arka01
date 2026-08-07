<script setup>
import { computed } from 'vue';

// Componente de estrellas reutilizable: modo solo-lectura (mostrar un
// promedio ya calculado) y modo input (v-model, para calificar del 1 al 5).
// Se usa en el directorio, el perfil público y el formulario de calificar
// al terminar una carrera (sección 3.6).
const props = defineProps({
    modelValue: {
        type: Number,
        default: 0,
    },
    // Promedio a mostrar en modo solo-lectura (puede tener decimales, ej. 4.3).
    rating: {
        type: Number,
        default: null,
    },
    readonly: {
        type: Boolean,
        default: false,
    },
    count: {
        type: Number,
        default: null,
    },
});

const emit = defineEmits(['update:modelValue']);

const displayValue = computed(() => (props.readonly ? Math.round(props.rating ?? 0) : props.modelValue));

function select(star) {
    if (props.readonly) return;
    emit('update:modelValue', star);
}
</script>

<template>
    <div class="inline-flex items-center gap-1">
        <button
            v-for="star in 5"
            :key="star"
            type="button"
            :disabled="readonly"
            class="text-lg leading-none"
            :class="[
                star <= displayValue ? 'text-arka-lime' : 'text-arka-text-muted/30',
                readonly ? 'cursor-default' : 'cursor-pointer hover:text-arka-lime',
            ]"
            @click="select(star)"
        >
            ★
        </button>

        <span v-if="readonly && rating != null" class="ms-1 text-sm text-arka-text-muted">
            {{ rating.toFixed(1) }}<span v-if="count != null"> ({{ count }})</span>
        </span>
    </div>
</template>
