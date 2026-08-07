import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import ConfirmDialogHost from './Components/ConfirmDialogHost.vue';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        // ConfirmDialogHost, una sola vez acá (no en cada layout): reemplaza
        // los window.confirm() nativos por un diálogo con el estilo oscuro de
        // la app (pedido explícito del usuario) — ver Utils/confirmDialog.js.
        return createApp({ render: () => [h(App, props), h(ConfirmDialogHost)] })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    // Barra de progreso de navegación en el verde primario de la marca, no el gris por defecto.
    progress: {
        color: '#34d399',
    },
});

// PWA (sección 9.2): registra el service worker para que la app se pueda
// instalar y reciba notificaciones push. Si falla (navegador viejo, http sin
// TLS en algún entorno), se ignora — no es crítico para que la app funcione.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}
