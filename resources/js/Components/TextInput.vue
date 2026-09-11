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
    <!-- Input claro: superficie blanca, texto oscuro y foco en verde de marca. -->
    <input
        class="rounded-arka shadow-sm focus:border-arka-primary focus:ring-arka-primary"
        :class="light
            ? 'border-arka-ink/10 bg-white text-arka-ink placeholder:text-arka-ink/35'
            : 'border-arka-text-muted/30 bg-arka-card text-arka-text placeholder:text-arka-text-muted'"
        v-model="model"
        ref="input"
    />
</template>
