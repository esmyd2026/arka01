<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { playStartupChime } from '@/Utils/liveAlert';
import { usePage } from '@inertiajs/vue3';
import { shouldShowStartupSplash } from '@/Utils/startupSplash';

// Pantalla de carga inicial (pedido explícito del usuario): fondo negro con
// el logo, solo al arrancar la app — vive una sola vez en app.js (no en
// cada layout). sessionStorage también lo protege frente a una recarga real
// accidental durante la navegación: solo reaparece después de cerrar sesión.
const page = usePage();
const visible = ref(shouldShowStartupSplash(Boolean(page.props.auth?.user)));
let hideTimer = null;

function playAndScheduleHide() {
    playStartupChime();
    clearTimeout(hideTimer);
    hideTimer = setTimeout(() => {
        visible.value = false;
    }, 1800);
}

onMounted(() => {
    if (!visible.value) return;
    playAndScheduleHide();
});

// El login puede completarse mediante Inertia sin recargar el documento. En
// ese caso el componente no se monta otra vez, así que observamos únicamente
// la transición de invitado a usuario autenticado para mostrarlo una vez.
watch(
    () => page.props.auth?.user?.id ?? null,
    (userId, previousUserId) => {
        if (userId && !previousUserId && shouldShowStartupSplash(true)) {
            visible.value = true;
            playAndScheduleHide();
        }
    },
);

onBeforeUnmount(() => clearTimeout(hideTimer));
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
