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
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

function applicationServerKeysMatch(subscription, expectedKey) {
    const currentKey = subscription.options?.applicationServerKey;
    if (!currentKey) return false;

    const currentBytes = new Uint8Array(currentKey);
    return currentBytes.length === expectedKey.length
        && currentBytes.every((byte, index) => byte === expectedKey[index]);
}

// El permiso del navegador y una suscripción push activa son dos cosas
// distintas. Un permiso "granted" sin endpoint (por una llave VAPID rotada,
// datos borrados o un POST fallido) no puede mostrarse como "todo listo".
export async function pushSubscriptionStatus() {
    if (!pushSupported()) return 'unsupported';
    if (Notification.permission !== 'granted') return Notification.permission;

    try {
        const registration = await navigator.serviceWorker.getRegistration();
        if (!registration) return 'unsubscribed';

        return (await registration.pushManager.getSubscription()) ? 'subscribed' : 'unsubscribed';
    } catch (error) {
        console.warn('No se pudo comprobar la suscripción push.', error);
        return 'unsubscribed';
    }
}

export async function subscribeToPush(vapidPublicKey) {
    if (!pushSupported() || !vapidPublicKey) return false;

    let subscription = null;

    try {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') return false;

        const registration = await navigator.serviceWorker.ready;
        const applicationServerKey = urlBase64ToUint8Array(vapidPublicKey);
        subscription = await registration.pushManager.getSubscription();

        // Si se rotaron las llaves VAPID, el navegador conserva el endpoint
        // anterior y subscribe() lanza InvalidStateError. Se reemplaza solo
        // cuando la llave realmente cambió; si sigue igual, se reutiliza y se
        // vuelve a sincronizar con el backend por si la BD fue restaurada.
        if (subscription && !applicationServerKeysMatch(subscription, applicationServerKey)) {
            await subscription.unsubscribe();
            subscription = null;
        }

        subscription ??= await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey,
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
        // Un endpoint que no pudo sincronizarse con el backend no recibirá
        // avisos. Se elimina para que el estado sea honesto y el siguiente
        // intento pueda crear y guardar uno nuevo limpiamente.
        await subscription?.unsubscribe().catch(() => {});
        return false;
    }
}
