<script setup>
// Ícono por grupo de la nav admin (ver Utils/adminNav.js) — un solo lugar
// para los 6 trazos, reusado en el dropdown de escritorio, el bottom sheet
// del FAB en móvil y las tarjetas del Inicio.
defineProps({
    icon: { type: String, required: true },
});
</script>

<template>
    <svg class="h-full w-full" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <template v-if="icon === 'route'">
            <circle cx="6" cy="18" r="2.2" stroke-linecap="round" />
            <circle cx="18" cy="6" r="2.2" stroke-linecap="round" />
            <path stroke-linecap="round" d="M8 18h5a3 3 0 0 0 3-3V9a3 3 0 0 1 3-3" />
        </template>
        <template v-else-if="icon === 'people'">
            <circle cx="9" cy="8" r="3" stroke-linecap="round" stroke-linejoin="round" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 19a6 6 0 0 1 12 0" />
            <circle cx="17.5" cy="9" r="2.3" stroke-linecap="round" stroke-linejoin="round" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.8 13.2c2.5 0 4.7 1.9 5.2 5" />
        </template>
        <template v-else-if="icon === 'tag'">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11.5 3.5h5a2 2 0 0 1 2 2v5a2 2 0 0 1-.6 1.4l-8 8a2 2 0 0 1-2.8 0l-5-5a2 2 0 0 1 0-2.8l8-8a2 2 0 0 1 1.4-.6Z" />
            <circle cx="15.5" cy="8.5" r="1.4" fill="currentColor" stroke="none" />
        </template>
        <template v-else-if="icon === 'shield'">
            <path stroke-linecap="round" stroke-linejoin="round" d="m12 3 8 3.5v5.2c0 4.4-3 7.6-8 9.3-5-1.7-8-4.9-8-9.3V6.5L12 3Z" />
        </template>
        <template v-else-if="icon === 'doc'">
            <path stroke-linecap="round" stroke-linejoin="round" d="M7 3.5h7.5L18 7v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1v-15a1 1 0 0 1 1-1Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M14 3.5V7h4M8.5 12h7M8.5 15.5h7" />
        </template>
        <template v-else-if="icon === 'gear'">
            <circle cx="12" cy="12" r="3" stroke-linecap="round" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.2M12 18.8V21M4.9 6.5l1.7 1.4M17.4 16.1l1.7 1.4M3 12h2.2M18.8 12H21M4.9 17.5l1.7-1.4M17.4 7.9l1.7-1.4" />
        </template>
    </svg>
</template>
