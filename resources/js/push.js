// Suscripción a notificaciones push (sección 9.2 y 9.5: Web Push API con
// VAPID). Se activa a pedido del usuario, no automáticamente al entrar —
// pedir permiso sin que el usuario lo busque es la forma más rápida de que
// lo rechace para siempre.

// El navegador espera la llave VAPID como Uint8Array, no como el string
// base64url que devuelve el backend.
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    return Uint8Array.from([...rawData].map((char) => char.charCodeAt(0)));
}

export function pushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window;
}

export async function subscribeToPush(vapidPublicKey) {
    if (!pushSupported()) return false;

    try {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') return false;

        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
        });

        await window.axios.post(route('push-subscriptions.store'), subscription.toJSON());
        return true;
    } catch (error) {
        // Bug real reportado por el usuario: el navegador le concedía el
        // permiso, pero si pushManager.subscribe() o el POST fallaban (ej.
        // VAPID mal configurado, o ya estaba suscripto con otra llave), la
        // excepción sin atrapar cortaba la función a mitad de camino — quien
        // llamaba nunca llegaba a refrescar el estado, así que el aviso de
        // "Activar notificaciones" se quedaba pegado en pantalla para
        // siempre, aunque el permiso ya estuviera concedido de verdad.
        console.warn('No se pudo completar la suscripción a notificaciones push.', error);
        return false;
    }
}
