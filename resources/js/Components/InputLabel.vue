<script setup>
defineProps({
    value: {
        type: String,
    },
    light: {
        type: Boolean,
        default: false,
    },
});
</script>

<template>
    <!-- Etiqueta de formulario con contraste consistente en fondos claros. -->
    <label class="block text-sm font-semibold" :class="light ? 'text-arka-ink/80' : 'text-arka-text'">
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>
