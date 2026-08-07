<script setup>
import { onMounted, ref } from 'vue';

// [String, Number] porque algunos campos (ej. cantidad de pasajeros) usan
// v-model.number, que convierte el valor a Number antes de llegar acá.
const model = defineModel({
    type: [String, Number],
    required: true,
});

const input = ref(null);

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
        class="bg-arka-card border-arka-text-muted/30 text-arka-text placeholder-arka-text-muted rounded-arka shadow-sm focus:border-arka-primary focus:ring-arka-primary"
        v-model="model"
        ref="input"
    />
</template>
