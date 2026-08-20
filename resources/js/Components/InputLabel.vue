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
    <!-- Etiqueta de formulario en texto claro, para que se lea bien sobre el fondo oscuro -->
    <label class="block text-sm font-semibold" :class="light ? 'text-arka-base/80' : 'text-arka-text'">
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>
