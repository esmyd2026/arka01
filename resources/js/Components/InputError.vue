<script setup>
defineProps({
    message: {
        type: String,
    },
});
</script>

<template>
    <div v-show="message">
        <!-- Mensaje de error de validación, con el rojo coral de la paleta (no el rojo genérico de Tailwind) -->
        <p class="text-sm text-arka-danger">
            {{ message }}
        </p>
    </div>
</template>
