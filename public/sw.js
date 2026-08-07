// Service Worker de Arka01 (sección 9.2 y 9.9): instala la PWA, deja un
// respaldo offline mínimo, y muestra las notificaciones push (sección 9.5,
// "Notificaciones (push web y correo)"). No cachea las páginas de la app a
// propósito: son datos en vivo (flota, carreras, precios), cachearlas de más
// mostraría información vieja como si fuera actual.

const CACHE_NAME = 'arka01-shell-v1';
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll([OFFLINE_URL, '/icons/icon.svg']))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))
        )
    );
    self.clients.claim();
});

// Solo se interviene la navegación de páginas (no las llamadas a la API ni
// los assets de Vite): si falla por estar offline, se muestra la página de
// respaldo en vez del error de red del navegador.
self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') return;

    event.respondWith(
        fetch(event.request).catch(() => caches.match(OFFLINE_URL))
    );
});

// Notificaciones push (Web Push API con VAPID, sección 9.8): el payload lo
// arma App\Notifications con NotificationChannels\WebPush.
self.addEventListener('push', (event) => {
    if (!event.data) return;

    const payload = event.data.json();

    event.waitUntil(
        self.registration.showNotification(payload.title ?? 'Arka01', {
            body: payload.body ?? '',
            icon: '/icons/icon.svg',
            badge: '/icons/icon.svg',
            data: { url: payload.url ?? '/dashboard' },
            // Sonido del sistema + vibración (pedido explícito del usuario):
            // `silent: false` es el default, pero se deja explícito para que
            // quede claro que es intencional; el patrón de vibración es lo
            // único que la Push API permite controlar de verdad (no hay forma
            // estándar entre navegadores de elegir un sonido propio).
            silent: false,
            vibrate: [200, 100, 200],
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const targetUrl = event.notification.data?.url ?? '/dashboard';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientsList) => {
            const existing = clientsList.find((client) => client.url.includes(targetUrl));
            if (existing) return existing.focus();
            return self.clients.openWindow(targetUrl);
        })
    );
});
