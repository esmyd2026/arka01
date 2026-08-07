<script setup>
defineProps({
    value: {
        type: String,
    },
});
</script>

<template>
    <!-- Etiqueta de formulario en texto claro, para que se lea bien sobre el fondo oscuro -->
    <label class="block font-medium text-sm text-arka-text">
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>
