import { PushNotifications } from '@capacitor/push-notifications';
import { Capacitor } from '@capacitor/core';
import { API_BASE_URL } from '../apiBase';
import { getToken, getStoredUser } from './auth';

// Notificaciones push nativas (Hito 6, roadmap app móvil — pedido explícito
// del usuario: "aqui si manejaremos notificaciones push. para la carreras
// o solicitudes"). El "timbre" de WebPush (navegador) no alcanza con la app
// cerrada; esto reemplaza/complementa ese aviso en el celular. El backend
// ya sabe mandar por FCM (ver App\Services\Push\FcmSender) — acá solo falta
// pedir permiso, guardar el token del dispositivo y reaccionar al toque.

let initialized = false;

async function authHeaders() {
    const token = await getToken();
    return { Accept: 'application/json', Authorization: `Bearer ${token}` };
}

async function sendTokenToServer(pushToken) {
    try {
        await fetch(`${API_BASE_URL}/api/v1/device/push-token`, {
            method: 'PUT',
            headers: { ...(await authHeaders()), 'Content-Type': 'application/json' },
            body: JSON.stringify({ push_token: pushToken, push_provider: 'fcm' }),
        });
    } catch (e) {
        // Sin conexión en este momento no debe bloquear el resto de la app
        // — el próximo arranque con sesión activa vuelve a intentarlo.
    }
}

// A qué pantalla llevar según los datos que ya arma cada *PushNotification
// (mismo payload `data` que WebPush, ver App\Notifications\Channels\
// FcmChannel) — sin esto, tocar el aviso solo abriría la app en Inicio.
async function routeForNotification(router, data) {
    if (!data) return { name: 'home' };

    if (data.ride_request_id) {
        const user = await getStoredUser();
        if (user?.role === 'conductor' && data.category === 'incoming_ride') {
            return { name: 'incoming-rides' };
        }

        return { name: 'ride-status', params: { id: data.ride_request_id } };
    }

    const rideIdMatch = typeof data.url === 'string' ? data.url.match(/\/carreras\/(\d+)/) : null;
    if (rideIdMatch) {
        return { name: 'active-ride', params: { id: rideIdMatch[1] } };
    }

    if (data.category === 'incoming_ride') {
        return { name: 'incoming-rides' };
    }

    return { name: 'ride-history' };
}

export async function initPushNotifications(router) {
    // No tiene sentido en el navegador (`npm run dev:mobile`) ni debe
    // registrarse dos veces — MobileShell.vue se monta de nuevo en cada
    // pantalla, así que esto necesita ser un candado a nivel de módulo, no
    // un simple `onMounted`.
    if (initialized || !Capacitor.isNativePlatform()) return;
    initialized = true;

    try {
        let permission = await PushNotifications.checkPermissions();
        if (permission.receive !== 'granted') {
            permission = await PushNotifications.requestPermissions();
        }
        if (permission.receive !== 'granted') return;

        await PushNotifications.addListener('registration', (token) => {
            sendTokenToServer(token.value);
        });

        await PushNotifications.addListener('registrationError', () => {
            // Sin push nativo la app sigue siendo usable — WebPush/WhatsApp
            // cubren el resto de los avisos mientras tanto.
        });

        // La app está abierta: el sistema no muestra la notificación sola,
        // así que la sección "Carreras"/lo que corresponda igual se entera
        // en el próximo sondeo — acá no hace falta hacer nada más.
        await PushNotifications.addListener('pushNotificationReceived', () => {});

        await PushNotifications.addListener('pushNotificationActionPerformed', async (action) => {
            const destination = await routeForNotification(router, action.notification?.data);
            router.push(destination);
        });

        await PushNotifications.register();
    } catch (e) {
        // Best-effort: un celular sin Google Play Services (FCM) u otro
        // fallo de plataforma no debe romper el resto del arranque de la app.
    }
}
