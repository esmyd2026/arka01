<script setup>
import { onMounted, ref } from 'vue';

// [String, Number] porque algunos campos (ej. cantidad de pasajeros) usan
// v-model.number, que convierte el valor a Number antes de llegar acá.
const model = defineModel({
    type: [String, Number],
    required: true,
});

const input = ref(null);

defineProps({
    light: {
        type: Boolean,
        default: false,
    },
});

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <!-- Input oscuro: fondo de tarjeta, texto claro, foco en verde menta -->
    <input
        class="rounded-arka shadow-sm focus:border-arka-primary focus:ring-arka-primary"
        :class="light
            ? 'border-arka-base/10 bg-white text-arka-base placeholder:text-arka-base/35'
            : 'border-arka-text-muted/30 bg-arka-card text-arka-text placeholder:text-arka-text-muted'"
        v-model="model"
        ref="input"
    />
</template>
