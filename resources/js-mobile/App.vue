<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { App as CapacitorApp } from '@capacitor/app';
import { useRouter } from 'vue-router';
import MobileSplash from './components/MobileSplash.vue';

// Botón Atrás de Android (roadmap Hito 4): sin esto, Capacitor sale de la
// app directo sin importar en qué pantalla esté — acá primero intenta
// retroceder dentro del historial de la propia app, y solo si ya no hay
// a dónde volver, deja que cierre la app (comportamiento esperado en
// Android: la pantalla raíz sí se sale con Atrás, no queda "atrapado").
const router = useRouter();
const showSplash = ref(true);
let removeListener;
let splashTimer;

onMounted(async () => {
    // El splash nativo cubre el arranque de Android. Esta transición breve
    // evita un destello vacío mientras Vue resuelve sesión y primera ruta.
    await router.isReady();
    splashTimer = window.setTimeout(() => {
        showSplash.value = false;
    }, 450);

    const handle = await CapacitorApp.addListener('backButton', () => {
        // Bug encontrado probando en el emulador: con el teclado abierto
        // (ej. llenando el registro) Atrás salía de la app entera en vez de
        // solo cerrar el teclado — WebView no siempre consume el back antes
        // de que llegue acá. Si hay un campo de texto enfocado, este toque
        // de Atrás solo le saca el foco (cierra el teclado); recién el
        // siguiente toque navega o cierra la app.
        const focused = document.activeElement;
        if (focused && (focused.tagName === 'INPUT' || focused.tagName === 'TEXTAREA' || focused.tagName === 'SELECT')) {
            focused.blur();
            return;
        }

        if (window.history.state?.back) {
            router.back();
        } else {
            CapacitorApp.exitApp();
        }
    });
    removeListener = () => handle.remove();
});

onUnmounted(() => {
    window.clearTimeout(splashTimer);
    removeListener?.();
});
</script>

<template>
    <router-view />
    <Transition name="splash-fade">
        <MobileSplash v-if="showSplash" />
    </Transition>
</template>

<style>
.splash-fade-leave-active { transition: opacity 220ms ease; }
.splash-fade-leave-to { opacity: 0; }
</style>
