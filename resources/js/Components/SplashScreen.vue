<script setup>
import { onMounted, ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { playStartupChime } from '@/Utils/liveAlert';

// Pantalla de carga inicial (pedido explícito del usuario): fondo negro con
// el logo, solo al arrancar la app — vive una sola vez en app.js (no en
// cada layout), así que nunca vuelve a aparecer en las navegaciones
// siguientes (Inertia no recarga la página real entre pantallas, esto solo
// corre en el arranque de verdad, F5 o primera visita).
const visible = ref(true);

onMounted(() => {
    playStartupChime();

    setTimeout(() => {
        visible.value = false;
    }, 1800);
});
</script>

<template>
    <Transition name="splash-fade">
        <div v-if="visible" class="fixed inset-0 z-[9999] bg-black flex items-center justify-center">
            <ApplicationLogo size="h-24 sm:h-28 animate-pulse" />
        </div>
    </Transition>
</template>

<style scoped>
.splash-fade-leave-active {
    transition: opacity 0.6s ease;
}
.splash-fade-leave-to {
    opacity: 0;
}
</style>
