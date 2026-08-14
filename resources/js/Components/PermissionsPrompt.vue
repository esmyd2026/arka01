<script setup>
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { pushSubscriptionStatus, subscribeToPush } from '@/push.js';
import { unlockAudioContext } from '@/Utils/liveAlert';

// Aviso pedido explícito del usuario: tanto clientes como conductores deben
// ver un recordatorio para activar ubicación y notificaciones si no las
// tienen prendidas — sin esto, un cliente no sabe por qué el mapa no
// encuentra conductores cerca, y un conductor no entiende por qué su
// disponibilidad se ve "desconectada" (ver el aviso de DriverAvailabilityToggle
// en Dashboard.vue, que ataca el mismo problema pero solo para quien ya
// activó el switch). Se muestra en AuthenticatedLayout.vue, o sea en toda la
// app ya logueada — a propósito NO en Welcome.vue (pedido explícito).
const DISMISS_KEY = 'arka01-permissions-prompt-dismissed-until';
const DISMISS_DAYS = 7;

// 'granted' | 'denied' | 'prompt' | 'unsupported'
const geoStatus = ref('unsupported');
const notifStatus = ref('unsupported');
const dismissed = ref(true);

function readDismissed() {
    const until = Number(localStorage.getItem(DISMISS_KEY) ?? 0);
    dismissed.value = Date.now() < until;
}

async function refreshGeoStatus() {
    if (!navigator.geolocation) {
        geoStatus.value = 'unsupported';
        return;
    }
    if (!navigator.permissions?.query) {
        // Sin la Permissions API no hay forma de saber el estado sin
        // disparar el diálogo nativo — se asume "prompt" (mejor ofrecer el
        // botón de más que quedarse callado sin poder saber si hace falta).
        geoStatus.value = 'prompt';
        return;
    }
    try {
        const status = await navigator.permissions.query({ name: 'geolocation' });
        geoStatus.value = status.state;
        status.onchange = () => (geoStatus.value = status.state);
    } catch {
        geoStatus.value = 'prompt';
    }
}

async function refreshNotifStatus() {
    notifStatus.value = await pushSubscriptionStatus();
}

onMounted(async () => {
    readDismissed();
    refreshGeoStatus();
    await refreshNotifStatus();
});

const needsGeo = computed(() => geoStatus.value === 'prompt' || geoStatus.value === 'denied');
const needsNotif = computed(() => ['default', 'denied', 'unsubscribed'].includes(notifStatus.value));
const visible = computed(() => !dismissed.value && (needsGeo.value || needsNotif.value));

function enableLocation() {
    // Igual que en cualquier otro punto de la app: el pedido de permiso
    // nativo solo se dispara con un clic explícito, nunca solo (mismo
    // criterio ya documentado para las notificaciones push más abajo).
    navigator.geolocation.getCurrentPosition(
        () => (geoStatus.value = 'granted'),
        (e) => (geoStatus.value = e.code === e.PERMISSION_DENIED ? 'denied' : 'prompt')
    );
}

async function enableNotifications() {
    // Pedido explícito del usuario: este clic es un gesto real, el momento
    // justo para desbloquear el sonido del resto de los avisos en vivo de
    // la app (sin esto, dependían de que cada aviso individual tuviera la
    // suerte de un gesto fresco detrás, algo que casi nunca pasa).
    unlockAudioContext();

    let ok = false;
    try {
        ok = await subscribeToPush(usePage().props.vapidPublicKey);
    } finally {
        // Bug real reportado por el usuario: si algo de acá arriba fallaba
        // sin que subscribeToPush() lo atrapara, el estado nunca se
        // refrescaba y el aviso quedaba pegado en pantalla aunque el
        // navegador ya hubiera concedido el permiso — este refresco corre
        // pase lo que pase.
        await refreshNotifStatus();
    }

    if (!ok) notifStatus.value = 'unsubscribed';
}

function dismiss() {
    localStorage.setItem(DISMISS_KEY, String(Date.now() + DISMISS_DAYS * 24 * 60 * 60 * 1000));
    dismissed.value = true;
}
</script>

<template>
    <div v-if="visible" class="p-3 rounded-arka bg-arka-warning/10 border border-arka-warning/30 text-sm space-y-2">
        <div class="flex items-start justify-between gap-3">
            <p class="text-arka-text font-medium">🔔 Active ubicación y notificaciones para no perderse nada</p>
            <button type="button" class="text-arka-text-muted hover:text-arka-text shrink-0" @click="dismiss">✕</button>
        </div>

        <p v-if="needsGeo" class="text-arka-text-muted flex items-center flex-wrap gap-x-1.5">
            📍 Sin ubicación activa, el mapa no puede ubicarlo ni mostrarle conductores cerca.
            <button
                v-if="geoStatus === 'prompt'"
                type="button"
                class="text-arka-warning hover:opacity-80 font-medium underline"
                @click="enableLocation"
            >
                Activar ubicación
            </button>
            <span v-else>Está bloqueada en su navegador — revise los permisos del sitio para habilitarla.</span>
        </p>

        <p v-if="needsNotif" class="text-arka-text-muted flex items-center flex-wrap gap-x-1.5">
            🔔 Sin notificaciones activas, puede no enterarse de solicitudes o avisos aunque no tenga la app abierta.
            <button
                v-if="notifStatus === 'default' || notifStatus === 'unsubscribed'"
                type="button"
                class="text-arka-warning hover:opacity-80 font-medium underline"
                @click="enableNotifications"
            >
                Activar notificaciones
            </button>
            <span v-else-if="notifStatus === 'denied'">Están bloqueadas en su navegador — revise los permisos del sitio para habilitarlas.</span>
            <span v-else>No se pudo crear la suscripción en este navegador. Revise la conexión e inténtelo otra vez.</span>
        </p>
    </div>
</template>
