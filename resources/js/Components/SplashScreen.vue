<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { playStartupChime } from '@/Utils/liveAlert';
import { usePage } from '@inertiajs/vue3';
import { shouldShowStartupSplash } from '@/Utils/startupSplash';

// Pantalla de carga inicial: fondo verde profundo con el logo preparado
// específicamente para superficies oscuras — vive una sola vez en app.js (no en
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
        <div v-if="visible" class="startup-splash fixed inset-0 z-[9999] flex items-center justify-center px-8">
            <div class="startup-splash__halo">
                <img
                    src="/img/logo arka01 completo sin fondo.png"
                    alt="Arka01"
                    class="startup-splash__logo"
                />
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.startup-splash {
    background:
        radial-gradient(circle at 50% 44%, rgba(52, 211, 153, .16), transparent 34%),
        linear-gradient(155deg, #0c2118 0%, #07120d 58%, #030806 100%);
}
.startup-splash__halo {
    position: relative;
    display: grid;
    width: min(82vw, 340px);
    min-height: 180px;
    place-items: center;
    isolation: isolate;
}
.startup-splash__halo::before,
.startup-splash__halo::after {
    position: absolute;
    z-index: -1;
    content: '';
    pointer-events: none;
    border-radius: 999px;
}
.startup-splash__halo::before {
    width: 88%;
    height: 76%;
    background: radial-gradient(ellipse, rgba(52, 211, 153, .24) 0%, rgba(20, 125, 88, .10) 44%, transparent 74%);
    filter: blur(24px);
    animation: splash-glow 1.8s ease-in-out infinite alternate;
}
.startup-splash__halo::after {
    width: 58%;
    height: 42%;
    background: rgba(110, 231, 183, .13);
    filter: blur(34px);
}
.startup-splash__logo {
    position: relative;
    width: min(72vw, 285px);
    height: auto;
    animation: splash-breathe 1.5s ease-in-out infinite alternate;
    filter:
        drop-shadow(0 0 10px rgba(52, 211, 153, .20))
        drop-shadow(0 10px 24px rgba(0, 0, 0, .28));
}
@keyframes splash-breathe {
    from { opacity: .82; transform: scale(.985); }
    to { opacity: 1; transform: scale(1); }
}
@keyframes splash-glow {
    from { opacity: .62; transform: scale(.92); }
    to { opacity: 1; transform: scale(1.04); }
}
.splash-fade-leave-active {
    transition: opacity 0.6s ease;
}
.splash-fade-leave-to {
    opacity: 0;
}
@media (prefers-reduced-motion: reduce) {
    .startup-splash__logo,
    .startup-splash__halo::before { animation: none; }
}
</style>
