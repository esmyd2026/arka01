<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { Geolocation } from '@capacitor/geolocation';
import { fetchIncomingRideRequests, acceptRideRequest, rejectRideRequest } from '../services/rides';
import MobileShell from '../components/MobileShell.vue';
import MobileMap from '../components/MobileMap.vue';

const router = useRouter();

const requests = ref([]);
const loading = ref(true);
const error = ref(null);
const actingId = ref(null);
const selectedRequest = ref(null);
const driverPosition = ref(null);

let pollTimer = null;

async function load() {
    try {
        requests.value = await fetchIncomingRideRequests();
        if (!selectedRequest.value || !requests.value.some((item) => item.id === selectedRequest.value.id)) {
            selectedRequest.value = requests.value[0] ?? null;
        }
        // No se limpia error.value en éxito: accept()/reject() llaman a
        // load() de nuevo justo después de fallar, para refrescar la
        // lista — no debe taparles el mensaje de error que acaban de
        // mostrar.
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

function schedulePoll() {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(async () => {
        await load();
        schedulePoll();
    }, 5000);
}

onMounted(async () => {
    try {
        const permission = await Geolocation.checkPermissions();
        if (permission.location === 'granted' || permission.coarseLocation === 'granted') {
            const position = await Geolocation.getCurrentPosition({ enableHighAccuracy: true, timeout: 10000 });
            driverPosition.value = { lat: position.coords.latitude, lng: position.coords.longitude };
        }
    } catch (_) { /* La ruta usa el origen de la solicitud como respaldo. */ }
    await load();
    schedulePoll();
});

onBeforeUnmount(() => clearTimeout(pollTimer));

async function accept(request) {
    actingId.value = request.id;
    error.value = null;
    try {
        const ride = await acceptRideRequest(request.id);
        clearTimeout(pollTimer);
        router.push({ name: 'active-ride', params: { id: ride.id } });
    } catch (e) {
        error.value = e.message;
        await load();
    } finally {
        actingId.value = null;
    }
}

async function reject(request) {
    actingId.value = request.id;
    error.value = null;
    try {
        await rejectRideRequest(request.id);
        await load();
    } catch (e) {
        error.value = e.message;
    } finally {
        actingId.value = null;
    }
}
</script>

<template>
    <MobileShell role="conductor">
    <main class="mobile-page incoming-page">
        <header class="page-heading"><p class="mobile-eyebrow">Solicitudes para ti</p><h1 class="mobile-title">Carreras disponibles</h1><p>Revisa la ruta y responde antes de que la tome otro conductor.</p></header>

        <p v-if="loading" class="hint">Cargando…</p>
        <p v-if="error" class="error">{{ error }}</p>

        <template v-else>
            <section v-if="requests.length === 0" class="empty-state mobile-card"><span><svg viewBox="0 0 24 24"><path d="M5 16h14l-1.5-6a2 2 0 0 0-2-1.5h-7A2 2 0 0 0 6.5 10L5 16Z" /></svg></span><strong>Todo al día</strong><p>No tienes solicitudes esperando respuesta ahora mismo.</p></section>

            <section v-if="selectedRequest" class="incoming-map mobile-card">
                <MobileMap :center="driverPosition || { lat: selectedRequest.origin_lat, lng: selectedRequest.origin_lng }" :destination="{ lat: selectedRequest.origin_lat, lng: selectedRequest.origin_lng }" />
                <div class="pickup-pill"><small>RECOGER EN</small><strong>{{ selectedRequest.origin_address || 'Ubicación del cliente' }}</strong></div>
            </section>

            <ul class="requests">
                <li v-for="request in requests" :key="request.id" class="request mobile-card" :class="{ selected: selectedRequest?.id === request.id }" @click="selectedRequest = request">
                    <div class="request-label"><span>{{ request.is_directed ? 'Solicitud directa' : 'Carrera disponible' }}</span><strong>${{ request.current_offered_price }}</strong></div>
                    <div class="request-main">
                        <span class="client-avatar">{{ request.client.name?.charAt(0) }}</span><p><strong>{{ request.client.name }}</strong><small v-if="request.client.rating">Calificación {{ request.client.rating }}</small><small v-else>Cliente sin calificar</small></p>
                    </div>
                    <div class="route-summary"><span></span><p><small>Destino</small><strong>{{ request.destination_address || 'Destino sin dirección' }}</strong></p></div>
                    <p v-if="request.distance_km" class="distance">{{ request.distance_km }} km estimados</p>

                    <div class="actions">
                        <button
                            class="accept-btn"
                            :disabled="actingId === request.id"
                            @click.stop="accept(request)"
                        >
                            {{ actingId === request.id ? 'Aceptando…' : 'Aceptar' }}
                        </button>
                        <button
                            v-if="request.is_directed"
                            class="reject-btn"
                            :disabled="actingId === request.id"
                            @click.stop="reject(request)"
                        >
                            Rechazar
                        </button>
                    </div>
                </li>
            </ul>
        </template>
    </main>
    </MobileShell>
</template>

<style scoped>
.incoming-page { display: grid; gap: 1rem; font-family: inherit; }
.incoming-map{position:relative;height:38dvh;min-height:17rem;overflow:hidden}.pickup-pill{position:absolute;left:.75rem;right:.75rem;bottom:.75rem;padding:.75rem .9rem;display:grid;gap:.18rem;border-radius:1rem;background:var(--arka-chrome);box-shadow:var(--arka-shadow)}.pickup-pill small{color:var(--arka-primary);font-size:.6rem;font-weight:850;letter-spacing:.12em}.pickup-pill strong{font-size:.78rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.page-heading > p:last-child { margin: .5rem 0 0; color: var(--arka-muted); font-size: .8rem; line-height: 1.45; }
.hint { color: var(--arka-muted); }.error { color: #fca5a5; font-size: .8rem; }
.empty-state { padding: 1.5rem 1rem; display: grid; justify-items: center; text-align: center; }
.empty-state > span { width: 3.2rem; height: 3.2rem; display: grid; place-items: center; border-radius: 1rem; background: var(--arka-primary-soft); color: var(--arka-primary); }
.empty-state svg { width: 1.5rem; fill: none; stroke: currentColor; stroke-width: 1.7; }.empty-state strong { margin-top: .8rem; }.empty-state p { margin: .35rem 0 0; color: var(--arka-muted); font-size: .75rem; }
.requests { list-style:none;padding:0;margin:0; }
.request { padding: 1rem; margin-bottom: .75rem; border:1px solid var(--arka-border);border-radius:1rem;background:var(--arka-card); }
.request.selected{border-color:var(--arka-primary);box-shadow:0 0 0 3px var(--arka-primary-soft)}
.request-label { display: flex; align-items: center; justify-content: space-between; }.request-label span { color: var(--arka-primary); font-size: .65rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }.request-label strong { color: var(--arka-primary); font-size: 1.2rem; }
.request-main { justify-content: flex-start; gap: .65rem; margin-top: .8rem; }.client-avatar { width: 2.25rem; height: 2.25rem; display: grid; place-items: center; border-radius: 50%; background: var(--arka-primary-soft); color: var(--arka-primary); font-weight: 800; }.request-main p { margin: 0; display: grid; gap: .1rem; }.request-main strong { font-size: .82rem; }.request-main small { color: var(--arka-muted); font-size: .66rem; }
.route-summary { margin-top: .8rem; padding: .75rem; display: flex; gap: .65rem; border-radius: .8rem; background:var(--arka-field); }.route-summary > span { width: .65rem; height: .65rem; margin-top: .25rem; flex: 0 0 auto; border-radius: .12rem; background: var(--arka-danger); transform: rotate(45deg); }.route-summary p { margin: 0; display: grid; gap: .18rem; }.route-summary small { color: var(--arka-muted); font-size: .62rem; text-transform: uppercase; }.route-summary strong { font-size: .72rem; line-height: 1.4; }.distance { margin: .5rem 0 0; color: var(--arka-muted); font-size: .67rem; }
.actions { margin-top: .9rem;display:flex;gap:.5rem; }.accept-btn,.reject-btn{min-height:2.75rem;padding:.6rem 1rem;font-weight:800}.accept-btn { flex:1;border:0;border-radius: .8rem; background: var(--arka-primary); color: var(--arka-on-primary); }.reject-btn { border:1px solid color-mix(in srgb,var(--arka-danger) 35%,transparent);border-radius: .8rem;background:transparent;color:var(--arka-danger); }.accept-btn:disabled,.reject-btn:disabled{opacity:.55}
</style>
