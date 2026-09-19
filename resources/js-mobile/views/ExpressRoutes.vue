<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import {
    fetchMyExpressRoutes,
    fetchAvailableExpressRoutes,
    publishExpressRoute,
    pauseExpressRoute,
    resumeExpressRoute,
    cancelExpressRoute,
    applyToExpressRoute,
    acceptExpressApplication,
    rejectExpressApplication,
    fetchExpressRoute,
} from '../services/express';
import { getStoredUser } from '../services/auth';
import MobileShell from '../components/MobileShell.vue';
import AddressAutocomplete from '../components/AddressAutocomplete.vue';

const router = useRouter();
const user = ref(null);
const loading = ref(true);
const error = ref(null);
const notice = ref(null);
const isDriver = ref(false);

// Lado cliente
const myRoutes = ref([]);
const referenceRate = ref(null);
const minimumFare = ref(null);
const expandedRouteId = ref(null);
const routeDetails = ref({});

const showForm = ref(false);
const form = ref({ name: '', departure_time: '', is_round_trip: false, return_time: '', offered_price: '', share_enabled: false, max_companions: '' });
const originAddress = ref('');
const origin = ref(null);
const destinationAddress = ref('');
const destination = ref(null);
const daysOfWeek = ref([]);
const DAY_LABELS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
const saving = ref(false);
const formError = ref(null);

// Lado conductor
const availableRoutes = ref([]);
const myApplications = ref({});
const assignedRoutes = ref([]);
const canApply = ref(true);
const workingId = ref(null);
const proposedPriceById = ref({});

async function loadClient() {
    const data = await fetchMyExpressRoutes();
    myRoutes.value = data.routes;
    referenceRate.value = data.reference_rate_per_km;
    minimumFare.value = data.minimum_fare;
}

async function loadDriver() {
    const data = await fetchAvailableExpressRoutes();
    availableRoutes.value = data.routes;
    myApplications.value = data.my_applications || {};
    assignedRoutes.value = data.assigned_routes;
    canApply.value = data.can_apply;
}

onMounted(async () => {
    user.value = await getStoredUser();
    isDriver.value = user.value?.role === 'conductor';
    try {
        if (isDriver.value) await loadDriver();
        else await loadClient();
    } catch (e) {
        error.value = e.message || 'No se pudieron cargar los Expresos.';
    } finally {
        loading.value = false;
    }
});

function toggleDay(day) {
    const index = daysOfWeek.value.indexOf(day);
    if (index === -1) daysOfWeek.value.push(day);
    else daysOfWeek.value.splice(index, 1);
}

function openForm() {
    form.value = { name: '', departure_time: '', is_round_trip: false, return_time: '', offered_price: '', share_enabled: false, max_companions: '' };
    originAddress.value = '';
    origin.value = null;
    destinationAddress.value = '';
    destination.value = null;
    daysOfWeek.value = [];
    formError.value = null;
    showForm.value = true;
}

async function publish() {
    if (!origin.value || !destination.value) {
        formError.value = 'Elige el origen y el destino de la lista de sugerencias.';
        return;
    }
    if (!daysOfWeek.value.length) {
        formError.value = 'Elige al menos un día de la semana.';
        return;
    }

    saving.value = true;
    formError.value = null;
    try {
        await publishExpressRoute({
            name: form.value.name,
            origin_lat: origin.value.lat,
            origin_lng: origin.value.lng,
            origin_address: origin.value.address,
            destination_lat: destination.value.lat,
            destination_lng: destination.value.lng,
            destination_address: destination.value.address,
            days_of_week: daysOfWeek.value,
            departure_time: form.value.departure_time,
            is_round_trip: form.value.is_round_trip,
            return_time: form.value.is_round_trip ? form.value.return_time : null,
            offered_price: form.value.offered_price,
            share_enabled: form.value.share_enabled,
            max_companions: form.value.max_companions || null,
        });
        showForm.value = false;
        await loadClient();
    } catch (e) {
        formError.value = e.message || 'No se pudo publicar el Expreso.';
    } finally {
        saving.value = false;
    }
}

async function loadRouteDetail(routeId) {
    try {
        const data = await fetchExpressRoute(routeId);
        routeDetails.value = { ...routeDetails.value, [routeId]: data.route };
    } catch (e) {
        error.value = e.message || 'No se pudo cargar el detalle.';
    }
}

async function toggleExpand(route) {
    if (expandedRouteId.value === route.id) {
        expandedRouteId.value = null;
        return;
    }
    expandedRouteId.value = route.id;
    if (!routeDetails.value[route.id]) {
        await loadRouteDetail(route.id);
    }
}

async function pause(route) {
    workingId.value = route.id;
    try {
        await pauseExpressRoute(route.id);
        await loadClient();
    } catch (e) {
        error.value = e.message;
    } finally {
        workingId.value = null;
    }
}

async function resume(route) {
    workingId.value = route.id;
    try {
        await resumeExpressRoute(route.id);
        await loadClient();
    } catch (e) {
        error.value = e.message;
    } finally {
        workingId.value = null;
    }
}

async function cancel(route) {
    workingId.value = route.id;
    try {
        await cancelExpressRoute(route.id);
        await loadClient();
    } catch (e) {
        error.value = e.message;
    } finally {
        workingId.value = null;
    }
}

async function decideApplication(application, decision) {
    workingId.value = application.id;
    error.value = null;
    try {
        if (decision === 'accept') await acceptExpressApplication(application.id);
        else await rejectExpressApplication(application.id);
        await loadClient();
        await loadRouteDetail(expandedRouteId.value);
    } catch (e) {
        error.value = e.message;
    } finally {
        workingId.value = null;
    }
}

async function apply(route) {
    workingId.value = route.id;
    error.value = null;
    try {
        await applyToExpressRoute(route.id, proposedPriceById.value[route.id] || null);
        notice.value = 'Postulación enviada.';
        await loadDriver();
    } catch (e) {
        error.value = e.message || 'No se pudo postular.';
    } finally {
        workingId.value = null;
    }
}
</script>

<template>
    <MobileShell v-if="user" :role="user.role" :user-name="user.name">
        <main class="mobile-page express-page">
            <button class="back-link" @click="router.back()">‹ Volver</button>

            <section class="welcome">
                <p class="mobile-eyebrow">Viajes recurrentes</p>
                <h1 class="mobile-title">Expresos</h1>
                <p class="welcome-copy">{{ isDriver ? 'Ofertas de rutas fijas publicadas por tus clientes de flota.' : 'Publica una ruta fija para que un conductor se postule.' }}</p>
            </section>

            <div v-if="loading" class="loading-row"><span class="mobile-spinner"></span><span>Cargando…</span></div>
            <p v-else-if="error" class="mobile-alert">{{ error }}</p>
            <p v-else-if="notice" class="notice-copy">{{ notice }}</p>

            <template v-if="!loading && !isDriver">
                <p v-if="!myRoutes.length" class="empty-copy">Todavía no publicas ningún Expreso.</p>

                <article v-for="route in myRoutes" :key="route.id" class="route-card mobile-card">
                    <button class="route-summary" @click="toggleExpand(route)">
                        <span class="route-info">
                            <strong>{{ route.name }}</strong>
                            <small>{{ route.origin_address }} → {{ route.destination_address }}</small>
                            <small>{{ route.departure_time }} · ${{ route.offered_price.toFixed(2) }}</small>
                        </span>
                        <span class="status-chip" :class="route.status">{{ route.status }}</span>
                    </button>

                    <div v-if="route.pending_applications_count" class="pending-note">{{ route.pending_applications_count }} postulación(es) pendiente(s)</div>

                    <div v-if="expandedRouteId === route.id" class="route-detail">
                        <div v-if="routeDetails[route.id]?.applications?.length" class="applications-list">
                            <p class="mobile-eyebrow">Postulaciones</p>
                            <div v-for="application in routeDetails[route.id].applications" :key="application.id" class="application-row">
                                <span><strong>{{ application.driver?.name }}</strong><small>{{ application.status }}</small></span>
                                <div v-if="application.status === 'pending'" class="actions">
                                    <button class="mini-button accept" :disabled="workingId === application.id" @click="decideApplication(application, 'accept')">Aceptar</button>
                                    <button class="mini-button reject" :disabled="workingId === application.id" @click="decideApplication(application, 'reject')">Rechazar</button>
                                </div>
                            </div>
                        </div>
                        <p v-else class="empty-copy">Sin postulaciones todavía.</p>

                        <div class="route-actions">
                            <button v-if="route.status === 'open' || route.status === 'active'" class="mini-button reject" :disabled="workingId === route.id" @click="pause(route)">Pausar</button>
                            <button v-if="route.status === 'paused'" class="mini-button accept" :disabled="workingId === route.id" @click="resume(route)">Reanudar</button>
                            <button v-if="!['cancelled'].includes(route.status)" class="mini-button reject" :disabled="workingId === route.id" @click="cancel(route)">Cancelar</button>
                        </div>
                    </div>
                </article>

                <button class="mobile-button add-button" @click="openForm">+ Publicar Expreso</button>
            </template>

            <template v-if="!loading && isDriver">
                <section v-if="assignedRoutes.length">
                    <div class="section-heading"><p class="mobile-eyebrow">Asignados</p><h2>Tus Expresos activos</h2></div>
                    <article v-for="route in assignedRoutes" :key="route.id" class="route-card mobile-card">
                        <span class="route-info">
                            <strong>{{ route.name }}</strong>
                            <small>{{ route.origin_address }} → {{ route.destination_address }}</small>
                        </span>
                        <span class="status-chip" :class="route.status">{{ route.status }}</span>
                    </article>
                </section>

                <p v-if="!availableRoutes.length" class="empty-copy">No hay Expresos abiertos de tus clientes por ahora.</p>

                <article v-for="route in availableRoutes" :key="route.id" class="route-card mobile-card">
                    <span class="route-info">
                        <strong>{{ route.name }}</strong>
                        <small>{{ route.origin_address }} → {{ route.destination_address }}</small>
                        <small>{{ route.client?.name }} · ${{ route.offered_price.toFixed(2) }}</small>
                    </span>

                    <template v-if="!myApplications[route.id]">
                        <div class="apply-row">
                            <input v-model="proposedPriceById[route.id]" type="number" step="0.01" min="0.01" class="price-input" placeholder="Tu precio (opcional)" />
                            <button class="mini-button accept" :disabled="!canApply || workingId === route.id" @click="apply(route)">Postular</button>
                        </div>
                    </template>
                    <span v-else class="status-chip">{{ myApplications[route.id] }}</span>
                </article>
                <p v-if="!canApply" class="empty-copy">Tu plan actual no permite postularte a Expresos.</p>
            </template>
        </main>

        <div v-if="showForm" class="sheet-backdrop" @click.self="showForm = false">
            <section class="add-sheet mobile-card">
                <span class="sheet-handle"></span>
                <h2>Nuevo Expreso</h2>
                <label class="mobile-field"><span>Nombre</span><input v-model="form.name" class="mobile-input" placeholder="Ej. Ida al trabajo" /></label>
                <label class="mobile-field">
                    <span>Origen</span>
                    <AddressAutocomplete v-model="originAddress" placeholder="Escribe el origen" @place-selected="origin = $event" @clear="origin = null" />
                </label>
                <label class="mobile-field">
                    <span>Destino</span>
                    <AddressAutocomplete v-model="destinationAddress" placeholder="Escribe el destino" @place-selected="destination = $event" @clear="destination = null" />
                </label>
                <div class="days-grid">
                    <button v-for="(label, day) in DAY_LABELS" :key="day" type="button" class="day-chip" :class="{ active: daysOfWeek.includes(day) }" @click="toggleDay(day)">{{ label }}</button>
                </div>
                <label class="mobile-field"><span>Hora de salida</span><input v-model="form.departure_time" type="time" class="mobile-input" /></label>
                <label class="checkbox-field"><input v-model="form.is_round_trip" type="checkbox" /><span>Ida y vuelta</span></label>
                <label v-if="form.is_round_trip" class="mobile-field"><span>Hora de regreso</span><input v-model="form.return_time" type="time" class="mobile-input" /></label>
                <label class="mobile-field"><span>Precio ofrecido ($)</span><input v-model="form.offered_price" type="number" step="0.01" min="0.01" class="mobile-input" /></label>
                <p v-if="referenceRate" class="hint-copy">Tarifa de referencia de tu flota: ${{ referenceRate.toFixed(2) }}/km. Mínimo general: ${{ minimumFare?.toFixed(2) }}.</p>
                <label class="checkbox-field"><input v-model="form.share_enabled" type="checkbox" /><span>Permitir que otros clientes se sumen a compartirlo</span></label>
                <label v-if="form.share_enabled" class="mobile-field"><span>Máximo de acompañantes</span><input v-model="form.max_companions" type="number" min="1" max="6" class="mobile-input" /></label>
                <p v-if="formError" class="mobile-alert">{{ formError }}</p>
                <button class="mobile-button" :disabled="saving" @click="publish">{{ saving ? 'Publicando…' : 'Publicar Expreso' }}</button>
                <button class="cancel-button" @click="showForm = false">Cancelar</button>
            </section>
        </div>
    </MobileShell>

    <main v-else-if="loading" class="loading-screen"><span class="mobile-spinner"></span><p>Cargando…</p></main>
</template>

<style scoped>
.express-page { display: grid; gap: 1.15rem; }
.back-link { justify-self: start; border: 0; background: transparent; color: var(--arka-muted); font-size: .85rem; font-weight: 700; padding: .35rem 0; }
.welcome { padding: 0 .25rem; }
.welcome-copy { margin: .5rem 0 0; color: var(--arka-muted); font-size: .85rem; line-height: 1.5; }
.loading-row { display: flex; align-items: center; gap: .6rem; padding: 1rem .25rem; color: var(--arka-muted); font-size: .88rem; }
.empty-copy { margin: 0; padding: .25rem; color: var(--arka-muted); font-size: .85rem; }
.notice-copy { margin: 0; padding: .25rem; color: var(--arka-primary); font-size: .82rem; font-weight: 700; }
.route-card { margin-bottom: .7rem; padding: 0; overflow: hidden; }
.route-summary { width: 100%; padding: 1rem; display: flex; align-items: center; justify-content: space-between; gap: .7rem; background: transparent; border: 0; text-align: left; color: inherit; }
.route-info { display: grid; gap: .15rem; min-width: 0; }
.route-info strong { font-size: .88rem; }
.route-info small { color: var(--arka-muted); font-size: .74rem; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.status-chip { flex: none; padding: .18rem .55rem; border-radius: 999px; font-size: .65rem; font-weight: 800; text-transform: uppercase; background: rgba(147,173,162,.15); color: var(--arka-muted); }
.status-chip.open, .status-chip.active { background: var(--arka-primary-soft); color: var(--arka-primary); }
.status-chip.paused { background: rgba(251,191,36,.14); color: var(--arka-warning); }
.pending-note { padding: 0 1rem .7rem; color: var(--arka-primary); font-size: .75rem; font-weight: 700; }
.route-detail { padding: 0 1rem 1rem; display: grid; gap: .7rem; border-top: 1px solid rgba(147,173,162,.12); }
.applications-list { display: grid; gap: .5rem; padding-top: .7rem; }
.application-row { display: flex; align-items: center; justify-content: space-between; gap: .6rem; }
.application-row strong { display: block; font-size: .82rem; }
.application-row small { color: var(--arka-muted); font-size: .7rem; text-transform: uppercase; }
.actions { display: flex; gap: .4rem; flex: none; }
.route-actions { display: flex; gap: .5rem; }
.mini-button { min-height: 2.2rem; padding: 0 .7rem; border-radius: .6rem; font-size: .74rem; font-weight: 700; border: 1px solid transparent; }
.mini-button.accept { background: var(--arka-primary); color: #062016; }
.mini-button.reject { background: transparent; border-color: var(--arka-danger); color: var(--arka-danger); }
.add-button { margin-top: .2rem; }
.apply-row { padding: 0 1rem 1rem; display: flex; gap: .5rem; align-items: center; }
.price-input { flex: 1; min-height: 2.3rem; padding: 0 .6rem; border-radius: .6rem; border: 1px solid var(--arka-border); background:var(--arka-field); color: var(--arka-text); }
.section-heading { margin: .2rem .2rem .5rem; }
.section-heading h2 { margin: .25rem 0 0; font-size: 1.05rem; letter-spacing: -.02em; }
.sheet-backdrop { position: fixed; z-index: 100; inset: 0; display: flex; align-items: flex-end; background: rgba(0,0,0,.7); }
.add-sheet { width: 100%; max-width: 560px; margin: 0 auto; max-height: 88vh; overflow-y: auto; padding: .6rem 1rem calc(1rem + env(safe-area-inset-bottom)); border-radius: 1.5rem 1.5rem 0 0; display: grid; gap: .7rem; }
.sheet-handle { width: 2.5rem; height: .25rem; margin: 0 auto .3rem; display: block; border-radius: 1rem; background: rgba(147,173,162,.3); }
.add-sheet h2 { margin: 0; }
.days-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: .3rem; }
.day-chip { min-height: 2.4rem; border-radius: .6rem; border: 1px solid rgba(147,173,162,.25); background: transparent; color: var(--arka-muted); font-size: .72rem; font-weight: 700; }
.day-chip.active { background: var(--arka-primary); color: #062016; border-color: var(--arka-primary); }
.checkbox-field { display: flex; align-items: center; gap: .55rem; min-height: 2.4rem; font-size: .85rem; color: var(--arka-text); }
.checkbox-field input { width: 1.15rem; height: 1.15rem; accent-color: var(--arka-primary); }
.hint-copy { margin: 0; color: var(--arka-muted); font-size: .74rem; line-height: 1.4; }
.cancel-button { width: 100%; text-align: center; border: 0; background: transparent; color: var(--arka-muted); min-height: 2.5rem; }
.loading-screen { min-height: 100dvh; display: grid; place-content: center; justify-items: center; gap: .75rem; color: var(--arka-muted); }
</style>
