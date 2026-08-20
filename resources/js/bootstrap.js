/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */

import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Sesión vencida en un pedido "de fondo" (pedido explícito del usuario, caso
 * real: el chat de una carrera mostraba "No se pudo mandar el mensaje" — un
 * error genérico que no explicaba que la sesión había vencido). Los pedidos
 * de Inertia (navegar de pantalla) ya tienen su propio arreglo para esto
 * (App\Exceptions\Handler::render(), evita el iframe atrapado de Inertia en
 * un 419) — pero un `window.axios.post()` directo (chat, buscador de
 * conductores en Mis flotas, ping de ubicación del conductor) nunca pasa por
 * ahí. Sin esto, cualquiera de esos pedidos de fondo fallaba con un mensaje
 * confuso en vez de explicar la causa real y ofrecer la salida obvia.
 */
window.axios.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 419) {
            alert('Su sesión expiró. Se va a recargar la página para que vuelva a entrar.');
            window.location.reload();
        }
        return Promise.reject(error);
    }
);

/**
 * Echo expone una API para suscribirse a canales y escuchar eventos que
 * Laravel transmite por WebSocket. Acá se conecta contra nuestro propio
 * servidor Reverb (self-hosted, sección 9.1/9.8 del alcance).
 *
 * Importante: el broadcaster es "reverb" (no "pusher"). Reverb habla el
 * protocolo de Pusher por dentro, pero pusher-js moderno exige un "cluster"
 * que Reverb no usa — el conector nativo "reverb" de Echo evita ese chequeo.
 * Usar "pusher" acá rompe la app entera (hasta el login) apenas carga.
 *
 * Envuelto en try/catch a propósito: si algún día falta configuración de
 * Reverb, que se pierda el tiempo real es aceptable — que se caiga toda la
 * aplicación (incluido el login) por eso, no.
 */

import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

try {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
} catch (error) {
    console.error('No se pudo conectar a Reverb (tiempo real desactivado):', error);
}
