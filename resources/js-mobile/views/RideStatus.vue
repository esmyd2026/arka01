<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { fetchRideRequest, cancelRideRequest } from '../services/rides';
import MobileShell from '../components/MobileShell.vue';
import MobileMap from '../components/MobileMap.vue';

const props = defineProps({ id: { type: [String, Number], required: true } });
const router = useRouter();

const rideRequest = ref(null);
const loading = ref(true);
const error = ref(null);
const cancelling = ref(false);

// Sin WebSocket todavía del lado móvil (pendiente en el roadmap) — el
// estado en vivo se resuelve con sondeo cada 3 segundos, igual que
// RideController::syncRequests() sirve de respaldo del lado web cuando
// Reverb se desconecta.
let pollTimer = null;

const ACTIVE_STATUSES = ['pending', 'negotiating', 'waiting'];

const STATUS_LABELS = {
    pending: 'Esperando respuesta del conductor…',
    negotiating: 'El conductor propuso otro precio.',
    waiting: 'Todos tus conductores están ocupados — en lista de espera.',
    accepted: '¡Aceptada! Tu conductor va en camino.',
    cancelled: 'Solicitud cancelada.',
    expired: 'Nadie respondió a tiempo.',
};

async function load() {
    try {
        rideRequest.value = await fetchRideRequest(props.id);
        // No se limpia error.value en éxito: el sondeo de fondo no debe
        // taparle al usuario el mensaje de una acción recién fallada.

        // Aceptada de verdad: ya existe una Ride real, pasa a esa pantalla
        // de seguimiento/acciones en vez de quedarse acá mostrando "en
        // camino" sin nada más que hacer.
        if (rideRequest.value.status === 'accepted' && rideRequest.value.ride_id) {
            router.replace({ name: 'active-ride', params: { id: rideRequest.value.ride_id } });
            return;
        }
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

function schedulePoll() {
    clearTimeout(pollTimer);
    if (rideRequest.value && ACTIVE_STATUSES.includes(rideRequest.value.status)) {
        pollTimer = setTimeout(async () => {
            await load();
            schedulePoll();
        }, 3000);
    }
}

onMounted(async () => {
    await load();
    schedulePoll();
});

onBeforeUnmount(() => clearTimeout(pollTimer));

async function cancel() {
    cancelling.value = true;
    try {
        await cancelRideRequest(props.id);
        await load();
    } catch (e) {
        error.value = e.message;
    } finally {
        cancelling.value = false;
    }
}
</script>

<template>
    <MobileShell role="cliente">
    <main class="mobile-page request-status-page">
        <header class="page-heading"><p class="mobile-eyebrow">Solicitud activa</p><h1 class="mobile-title">Buscando conductor</h1><p>Te avisaremos en cuanto alguien acepte.</p></header>

        <p v-if="loading" class="hint">Cargando…</p>
        <p v-else-if="error" class="error">{{ error }}</p>

        <template v-else-if="rideRequest">
            <section class="waiting-map mobile-card">
                <MobileMap
                    :center="{ lat: rideRequest.origin_lat, lng: rideRequest.origin_lng }"
                    :destination="{ lat: rideRequest.destination_lat, lng: rideRequest.destination_lng }"
                    :zoom="14"
                />
                <div class="searching-pill"><span class="mobile-spinner"></span>{{ STATUS_LABELS[rideRequest.status] ?? rideRequest.status }}</div>
            </section>
            <section class="status-card mobile-card" :class="rideRequest.status"><span class="status-icon"><svg viewBox="0 0 24 24"><path d="M5 16h14l-1.5-6a2 2 0 0 0-2-1.5h-7A2 2 0 0 0 6.5 10L5 16Zm2 0v2m10-2v2" /></svg></span><div><p class="mobile-eyebrow">Estado</p><strong>{{ STATUS_LABELS[rideRequest.status] ?? rideRequest.status }}</strong></div></section>

            <section class="details mobile-card">
                <div class="destination"><span></span><p><small>Destino</small><strong>{{ rideRequest.destination_address || '—' }}</strong></p></div>
                <div v-if="rideRequest.driver" class="detail-row"><span>Conductor</span><strong>{{ rideRequest.driver.name }}</strong></div>
                <div class="detail-row"><span>Oferta</span><strong class="price">${{ rideRequest.current_offered_price }}</strong></div>
                <div v-if="rideRequest.distance_km" class="detail-row"><span>Distancia estimada</span><strong>{{ rideRequest.distance_km }} km</strong></div>
            </section>

            <button
                v-if="['pending', 'negotiating', 'waiting'].includes(rideRequest.status)"
                class="cancel-btn"
                :disabled="cancelling"
                @click="cancel"
            >
                {{ cancelling ? 'Cancelando…' : 'Cancelar solicitud' }}
            </button>

            <button
                v-if="!['pending', 'negotiating', 'waiting'].includes(rideRequest.status)"
                class="mobile-button"
                @click="router.push({ name: 'request-ride' })"
            >
                Pedir otra carrera
            </button>
        </template>
    </main>
    </MobileShell>
</template>

<style scoped>
.request-status-page { display: grid; gap: 1rem; }.page-heading > p:last-child { margin: .5rem 0 0; color: var(--arka-muted); font-size: .82rem; }.hint { color: var(--arka-muted); }.error { color:var(--arka-danger); }.status-card { padding: 1rem; display: flex; align-items: center; gap: .8rem; border-color:color-mix(in srgb,var(--arka-primary) 30%,transparent);background:var(--arka-status-panel); }.status-card.cancelled, .status-card.expired { border-color:color-mix(in srgb,var(--arka-danger) 30%,transparent);background:color-mix(in srgb,var(--arka-danger) 8%,var(--arka-card)); }.status-icon { width: 2.7rem; height: 2.7rem; display: grid; place-items: center; border-radius: .9rem; background: var(--arka-primary); color:var(--arka-on-primary); }.status-icon svg { width: 1.35rem; height: 1.35rem; fill: none; stroke: currentColor; stroke-width: 1.8; }.status-card > div { display: grid; gap: .25rem; }.status-card strong { font-size: .88rem; }.details { padding: 1rem; }.destination { padding-bottom: .9rem; display: flex; gap: .7rem; border-bottom: 1px solid var(--arka-border); }.destination > span { width: .72rem; height: .72rem; margin-top: .25rem; flex: 0 0 auto; border-radius: .15rem; background: var(--arka-danger); transform: rotate(45deg); }.destination p { margin: 0; display: grid; gap: .2rem; }.destination small, .detail-row span { color: var(--arka-muted); font-size: .68rem; }.destination strong { font-size: .8rem; line-height: 1.4; }.detail-row { min-height: 2.9rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; border-bottom: 1px solid var(--arka-border); }.detail-row:last-child { border: 0; }.detail-row strong { font-size: .82rem; }.detail-row .price { color: var(--arka-primary); font-size: 1.05rem; }
.waiting-map{position:relative;height:45dvh;min-height:19rem;overflow:hidden}.searching-pill{position:absolute;left:.8rem;right:.8rem;bottom:.8rem;min-height:3rem;padding:.7rem .9rem;display:flex;align-items:center;gap:.65rem;border-radius:1rem;background:var(--arka-chrome);color:var(--arka-text);box-shadow:var(--arka-shadow);font-size:.76rem;font-weight:750}
.cancel-btn {
    width: 100%;
    padding: 0.75rem;
    min-height: 3rem;
    border: 1px solid rgba(248,113,113,.35);
    border-radius: .9rem;
    background: rgba(248,113,113,.06);
    color: var(--arka-danger);
    font-size: 0.95rem;
    margin-top: 1rem;
}
.cancel-btn:disabled {
    opacity: 0.6;
}
</style>
