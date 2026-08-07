<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <!-- Ítem de menú desplegable: al pasar el mouse se resalta con el fondo base (más oscuro que la tarjeta) -->
    <Link
        :href="href"
        class="block w-full px-4 py-2 text-start text-sm leading-5 text-arka-text hover:bg-arka-base focus:outline-none focus:bg-arka-base transition duration-150 ease-in-out"
    >
        <slot />
    </Link>
</template>
