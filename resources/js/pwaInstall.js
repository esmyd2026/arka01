import { computed, ref } from 'vue';

// Instalar Arka01 como app (pedido explícito del usuario: "un botón que el
// cliente accione para guardar un acceso directo en su teléfono o
// computador"). El navegador ya sabe instalarla sola (manifest.json +
// sw.js, ver app.blade.php/app.js) — lo único que faltaba era un botón
// propio que dispare ese diálogo nativo, en vez de esperar a que el usuario
// encuentre la opción escondida del menú del navegador.
const deferredPrompt = ref(null);
const installed = ref(false);

// Chrome/Edge avisan acá ANTES de mostrar su propio mini-banner —
// preventDefault() lo frena para poder ofrecerlo nosotros, desde nuestro
// propio botón, en el momento que el usuario elija.
window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredPrompt.value = event;
});

window.addEventListener('appinstalled', () => {
    deferredPrompt.value = null;
    installed.value = true;
});

export const canInstallApp = computed(() => deferredPrompt.value !== null && !installed.value);

export async function installApp() {
    if (!deferredPrompt.value) return false;

    deferredPrompt.value.prompt();
    const { outcome } = await deferredPrompt.value.userChoice;
    deferredPrompt.value = null;

    return outcome === 'accepted';
}
