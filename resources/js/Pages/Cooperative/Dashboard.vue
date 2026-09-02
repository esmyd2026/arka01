<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import FleetMap from '@/Components/FleetMap.vue';
import TrustScoreBadge from '@/Components/TrustScoreBadge.vue';
import { isAudioUnlocked, playIncomingRideAlert, unlockAudioContext } from '@/Utils/liveAlert';

const props = defineProps({ cooperative: { type: Object, required: true }, stats: { type: Object, required: true }, requests: { type: Array, required: true }, drivers: { type: Array, required: true } });
const page = usePage();
const selectedDrivers = reactive({});
const activeView = ref('dispatch');
const expandedRequests = reactive({});
const openDispatchCards = reactive({});
const assigningRequests = reactive({});
const clock = ref(Date.now());
const dispatchHelpOpen = ref(false);
const incomingAlert = ref(null);
let incomingAlertTimer = null;
const knownRequestIds = new Set(props.requests.map((request) => Number(request.id)));

// Bug reportado por el usuario ("no está llegando el sonido al panel de la
// cooperativa cuando le llega una carrera"): la solicitud SÍ llega (la lista
// y el aviso visual se actualizan), pero si en toda la sesión todavía no
// hubo ni un clic/toque real, el navegador mantiene el audio bloqueado por
// su política de autoplay y el sonido falla en silencio — nada en pantalla
// avisa que quedó así. Un operador de despacho suele solo dejar esta
// pantalla abierta esperando, sin tocar nada hasta que le toque asignar, así
// que acá se avisa de entrada en vez de que se entere recién cuando ya se le
// pasó una carrera. Se revisa cada 2s solo mientras siga bloqueado —
// resume() de audioCtx es asíncrono, por eso no alcanza con revisar una vez.
const soundUnlocked = ref(isAudioUnlocked());
let soundCheckTimer = null;
function enableSound() {
    unlockAudioContext();
    soundUnlocked.value = isAudioUnlocked();
}

function alertIncomingRequest(requestId) {
    const id = Number(requestId);
    if (!id || knownRequestIds.has(id)) return;

    knownRequestIds.add(id);
    activeView.value = 'dispatch';
    playIncomingRideAlert();
    incomingAlert.value = `Nueva solicitud #${id} para asignar`;
    window.clearTimeout(incomingAlertTimer);
    incomingAlertTimer = window.setTimeout(() => { incomingAlert.value = null; }, 10000);
}

function testDispatchSound() {
    // Este clic es un gesto válido para la política de reproducción del
    // navegador; deja el audio habilitado y reproduce el mismo aviso real.
    unlockAudioContext();
    playIncomingRideAlert();
}

// Respaldo del WebSocket: si Reverb no entregó el evento pero el refresco de
// 15 segundos sí trajo una solicitud nueva, la central también debe sonar.
watch(
    () => props.requests.map((request) => Number(request.id)),
    (ids) => ids.forEach(alertIncomingRequest),
);

function assign(request) {
    const driverUserId = selectedDrivers[request.id];
    if (!driverUserId) return;
    assigningRequests[request.id] = true;
    router.post(route('cooperative.rides.assign', request.id), { driver_user_id: driverUserId }, {
        preserveScroll: true,
        onFinish: () => { assigningRequests[request.id] = false; },
    });
}

const eligibleDrivers = (request) => request.available_drivers.filter((driver) => driver.available && driver.verified);
const recommendedDriver = (request) => eligibleDrivers(request)[0] ?? null;
const visibleDrivers = (request) => expandedRequests[request.id] ? eligibleDrivers(request) : eligibleDrivers(request).slice(0, 3);
const selectedDriver = (request) => request.available_drivers.find((driver) => driver.user_id === selectedDrivers[request.id]);
const initials = (name) => name.split(' ').slice(0, 2).map((part) => part.charAt(0)).join('').toUpperCase();
function operationalOrigin(request) {
    const address = request.origin_address?.trim();
    if (address && !/^mi ubicaci[oó]n(?: actual)?$/i.test(address)) return address;
    return `Punto GPS ${Number(request.origin_lat).toFixed(5)}, ${Number(request.origin_lng).toFixed(5)}`;
}
function toggleDispatchCard(requestId) {
    const willOpen = !openDispatchCards[requestId];
    Object.keys(openDispatchCards).forEach((id) => { openDispatchCards[id] = false; });
    openDispatchCards[requestId] = willOpen;
}
function fallbackSeconds(request) {
    if (request.cooperative_assignment_status !== 'awaiting_operator' || !request.requested_at) return null;
    const deadline = new Date(request.requested_at).getTime() + Number(props.cooperative.manual_assignment_timeout_seconds ?? 30) * 1000;
    return Math.max(0, Math.ceil((deadline - clock.value) / 1000));
}

// Pedido explícito del usuario: "le faltaria la fecha visible de la carrera
// y si es programda o no" — antes solo se veía la hora ("06:35 p. m."), sin
// fecha ni si era de una vez o programada. Para una programada se muestra la
// fecha/hora PARA LA QUE está pedida (scheduled_at), no la fecha en la que
// se creó la solicitud — es lo que le importa al operador para decidir.
function requestDateTimeLabel(request) {
    const date = new Date(request.is_scheduled ? request.scheduled_at : request.requested_at);
    const day = date.toLocaleDateString('es-EC', { day: '2-digit', month: 'short' });
    const time = date.toLocaleTimeString('es-EC', { hour: '2-digit', minute: '2-digit' });
    return `${day}, ${time}`;
}

// Mismo pedido: diferenciar con color las inmediatas de las programadas —
// arka-warning para programada porque es el mismo color que ya usaba el
// aviso "Programada: ..." de la tarjeta expandida (más abajo), así queda
// consistente en toda la pantalla.
function scheduleBadge(request) {
    return request.is_scheduled
        ? { label: '🗓 Programada', classes: 'bg-arka-warning/10 text-arka-warning' }
        : { label: '● Inmediata', classes: 'bg-arka-primary/10 text-arka-primary' };
}

// Pedido explícito del usuario ("revisa si la carrera se vence o no"):
// cuando ya hay un conductor asignado y esperando respuesta, cuánto le
// queda antes de que el motor la pase al siguiente candidato de la bolsa
// (App\Services\RideDispatchAdvancer::advanceOrExpire()). Las programadas no
// vencen así — el backend no les pone current_offer_expires_at a propósito
// (CooperativeRideAssignmentController::assign()), el conductor las confirma
// cuando puede, sin la presión del despacho inmediato.
function offerSecondsLeft(request) {
    if (!request.driver || !request.current_offer_expires_at) return null;
    return Math.max(0, Math.ceil((new Date(request.current_offer_expires_at).getTime() - clock.value) / 1000));
}

const DISPATCH_OUTCOME_LABEL = { timeout: 'no respondió a tiempo', rejected: 'la rechazó' };

const mapMarkers = computed(() => {
    const drivers = props.drivers.filter((driver) => driver.lat != null && driver.lng != null).map((driver) => ({
    id: driver.user_id, type: 'car', lat: Number(driver.lat), lng: Number(driver.lng), label: driver.name,
    color: driver.operational_status === 'in_ride' ? '#38bdf8' : (driver.available ? '#34d399' : '#94a3b8'),
    }));
    if (props.cooperative.stand_lat != null && props.cooperative.stand_lng != null) drivers.unshift({
        id: `cooperative-base-${props.cooperative.id}`, lat: Number(props.cooperative.stand_lat), lng: Number(props.cooperative.stand_lng),
        type: 'base', label: `Base · ${props.cooperative.name}`, color: '#f59e0b',
    });
    return drivers;
});
const locatedDriverCount = computed(() => props.drivers.filter((driver) => driver.lat != null && driver.lng != null).length);
const driverStatus = {
    active: { label: 'Disponible', dot: 'bg-arka-primary', badge: 'bg-arka-primary/10 text-arka-primary' },
    in_ride: { label: 'En carrera', dot: 'bg-sky-400', badge: 'bg-sky-400/10 text-sky-300' },
    inactive: { label: 'Inactivo', dot: 'bg-arka-text-muted', badge: 'bg-arka-text-muted/10 text-arka-text-muted' },
};
const driverStatusCount = (status) => props.drivers.filter((driver) => driver.operational_status === status).length;
const lastLocation = (value) => {
    if (!value) return 'Sin ubicación reciente';
    const minutes = Math.max(0, Math.floor((Date.now() - new Date(value).getTime()) / 60000));
    if (minutes < 1) return 'Ubicación actualizada ahora';
    if (minutes < 60) return `Ubicación hace ${minutes} min`;
    return `Ubicación hace ${Math.floor(minutes / 60)} h`;
};
let refreshTimer;
let clockTimer;
let cooperativeChannel = null;
const refresh = () => {
    // Las pestañas en segundo plano no deben seguir consultando la BD cada
    // 15 segundos. Al volver, visibilitychange actualiza inmediatamente.
    if (document.hidden) return;
    router.reload({ only: ['stats', 'requests', 'drivers', 'cooperative'], preserveScroll: true });
};
const toggleAutomatic = () => router.patch(route('cooperative.dispatch-settings.update'), {
    automatic_assignment_enabled: !props.cooperative.automatic_assignment_enabled,
}, { preserveScroll: true });

onMounted(() => {
    refreshTimer = window.setInterval(refresh, 15000);
    clockTimer = window.setInterval(() => { clock.value = Date.now(); }, 1000);
    if (!soundUnlocked.value) {
        soundCheckTimer = window.setInterval(() => {
            soundUnlocked.value = isAudioUnlocked();
            if (soundUnlocked.value) window.clearInterval(soundCheckTimer);
        }, 2000);
    }
    const userId = page.props.auth?.user?.id;
    if (userId) {
        cooperativeChannel = `App.Models.User.${userId}`;
        window.Echo?.private(cooperativeChannel).listen('.cooperative-ride.updated', (event) => {
            if (event.action === 'created') alertIncomingRequest(event.ride_request_id);
            refresh();
        });
    }
    document.addEventListener('visibilitychange', refresh);
});
onBeforeUnmount(() => {
    window.clearInterval(refreshTimer);
    window.clearInterval(clockTimer);
    window.clearInterval(soundCheckTimer);
    document.removeEventListener('visibilitychange', refresh);
    window.clearTimeout(incomingAlertTimer);
    if (cooperativeChannel) window.Echo?.leave(cooperativeChannel);
});
</script>

<template>
    <Head title="Panel de cooperativa" />
    <AuthenticatedLayout>
        <template #header><h2 class="text-xl font-semibold text-arka-text">{{ cooperative.name || 'Panel de cooperativa' }}</h2></template>
        <div v-if="incomingAlert" class="fixed inset-x-4 top-4 z-50 mx-auto max-w-md rounded-2xl border border-arka-primary/40 bg-arka-card p-4 shadow-2xl sm:left-auto sm:right-4 sm:mx-0">
            <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-arka-primary">Atención de despacho</p>
            <div class="mt-1 flex items-center justify-between gap-3"><p class="font-semibold text-arka-text">{{ incomingAlert }}</p><button type="button" class="text-arka-text-muted" aria-label="Cerrar aviso" @click="incomingAlert = null">✕</button></div>
        </div>

        <div class="py-8 sm:py-12">
            <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6">
                <!-- Pedido explícito del usuario ("no está llegando el sonido al
                     panel de la cooperativa cuando le llega una carrera"): la
                     política de autoplay del navegador bloquea el audio hasta
                     que haya un clic real en la sesión — sin este aviso, un
                     operador que solo deja la pantalla abierta esperando nunca
                     se entera de que el aviso sonoro está apagado. -->
                <div v-if="!soundUnlocked" class="flex flex-wrap items-center justify-between gap-3 rounded-arka border border-arka-danger/30 bg-arka-danger/10 p-4 text-sm text-arka-danger">
                    <span>🔇 El sonido de "nueva solicitud" todavía no está activo en este navegador — hace falta un clic para habilitarlo.</span>
                    <button type="button" class="rounded-full bg-arka-danger px-4 py-1.5 font-semibold text-white" @click="enableSound">Activar sonido</button>
                </div>
                <div v-if="cooperative.status !== 'approved'" class="rounded-arka border border-arka-warning/30 bg-arka-warning/10 p-4 text-sm text-arka-warning">
                    La cooperativa aún no puede operar. Estado: <strong>{{ cooperative.status }}</strong>.
                    <Link :href="route('cooperative.profile.edit')" class="ml-1 underline">Revisar perfil y documentos</Link>
                </div>
                <nav class="grid grid-cols-3 gap-2 rounded-2xl border border-arka-text-muted/10 bg-arka-card p-2 shadow-lg">
                    <button type="button" class="relative rounded-xl px-4 py-3 text-left transition sm:px-5" :class="activeView === 'dispatch' ? 'bg-arka-primary text-arka-base shadow' : 'text-arka-text-muted hover:bg-arka-primary/5 hover:text-arka-text'" @click="activeView = 'dispatch'">
                        <span class="block text-xs font-semibold uppercase tracking-[0.12em]">Atención inmediata</span>
                        <span class="mt-0.5 block text-sm font-bold sm:text-base">Asignar carreras</span>
                        <span v-if="stats.pendingRequests" class="absolute right-3 top-3 grid h-6 min-w-6 place-items-center rounded-full px-1.5 text-xs font-bold" :class="activeView === 'dispatch' ? 'bg-arka-base text-arka-primary' : 'bg-arka-primary text-arka-base'">{{ stats.pendingRequests }}</span>
                    </button>
                    <button type="button" class="rounded-xl px-4 py-3 text-left transition sm:px-5" :class="activeView === 'operations' ? 'bg-arka-primary text-arka-base shadow' : 'text-arka-text-muted hover:bg-arka-primary/5 hover:text-arka-text'" @click="activeView = 'operations'">
                        <span class="block text-xs font-semibold uppercase tracking-[0.12em]">Supervisión</span>
                        <span class="mt-0.5 block text-sm font-bold sm:text-base">Mapa y operación</span>
                    </button>
                    <Link :href="route('cooperative.wallet')" class="relative rounded-xl px-4 py-3 text-left text-arka-text-muted transition hover:bg-arka-primary/5 hover:text-arka-text sm:px-5">
                        <span class="block text-xs font-semibold uppercase tracking-[0.12em]">Finanzas</span>
                        <span class="mt-0.5 block text-sm font-bold sm:text-base">Pagos y comprobantes</span>
                        <span v-if="stats.paymentsToReview" class="absolute right-3 top-3 grid h-6 min-w-6 place-items-center rounded-full bg-sky-400 px-1.5 text-xs font-bold text-arka-base">{{ stats.paymentsToReview }}</span>
                    </Link>
                </nav>

                <template v-if="activeView === 'operations'">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
                    <div v-for="(value, key) in stats" :key="key" class="rounded-arka bg-arka-card p-4 shadow-lg">
                        <p class="text-xs uppercase text-arka-text-muted">{{ { clients: 'Clientes vinculados', drivers: 'Conductores', available: 'Disponibles', pendingDrivers: 'Por aceptar', pendingRequests: 'Solicitudes', scheduledRequests: 'Programadas', activeRequests: 'Activas', paymentsToReview: 'Pagos por revisar' }[key] }}</p>
                        <p class="mt-2 text-2xl font-bold text-arka-text">{{ value }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-3">
                    <Link :href="route('cooperative.drivers.index')" class="rounded-full bg-arka-primary px-4 py-2 text-sm font-semibold text-arka-base">Administrar conductores</Link>
                    <!-- Pedido explícito del usuario: "donde la cooperativa
                         ve la trazabilidad de las carreras, cuánto hizo su
                         equipo y cuánto le deben o cuánto ella le debe a su
                         equipo" — antes solo se veía conductor por conductor. -->
                    <Link :href="route('cooperative.wallet')" class="rounded-full border border-arka-text-muted/20 px-4 py-2 text-sm text-arka-text">Billetera y trazabilidad</Link>
                    <!-- Pedido explícito del usuario: "quiero ver mis clientes
                         vinculados... la lista, cantidad de carreras,
                         puntuaccion y desvincular". -->
                    <Link :href="route('cooperative.clients.index')" class="rounded-full border border-arka-text-muted/20 px-4 py-2 text-sm text-arka-text">Clientes vinculados</Link>
                    <Link :href="route('cooperative.profile.edit')" class="rounded-full border border-arka-text-muted/20 px-4 py-2 text-sm text-arka-text">Perfil y documentos</Link>
                    <Link :href="route('cooperatives.show', cooperative.public_id)" class="rounded-full border border-arka-text-muted/20 px-4 py-2 text-sm text-arka-text">Perfil público</Link>
                </div>
                <section>
                    <div class="overflow-hidden rounded-arka bg-arka-card shadow-lg">
                        <div class="relative flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                            <div>
                                <div class="flex items-center gap-2"><h3 class="font-semibold text-arka-text">Conductores y ubicación</h3><span class="rounded-full bg-arka-primary/15 px-2.5 py-1 text-[11px] font-semibold text-arka-primary">{{ locatedDriverCount }} unidades</span></div>
                                <p class="mt-1 text-xs text-arka-text-muted">Supervisión en tiempo real de la operación</p>
                            </div>

                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    class="relative inline-flex min-h-[44px] flex-1 items-center gap-3 rounded-full border border-arka-text-muted/15 bg-arka-base/50 px-3 py-2 text-left transition hover:border-arka-primary/40 sm:flex-none"
                                    role="switch"
                                    :aria-checked="cooperative.automatic_assignment_enabled"
                                    @click="toggleAutomatic"
                                >
                                    <span class="relative h-6 w-11 shrink-0 rounded-full transition" :class="cooperative.automatic_assignment_enabled ? 'bg-arka-primary' : 'bg-arka-text-muted/30'">
                                        <span class="absolute top-1 grid h-4 w-4 place-items-center rounded-full bg-white text-[10px] font-bold text-arka-primary shadow transition-all" :class="cooperative.automatic_assignment_enabled ? 'left-6' : 'left-1'">{{ cooperative.automatic_assignment_enabled ? '✓' : '' }}</span>
                                    </span>
                                    <span><span class="block text-[10px] uppercase tracking-wider text-arka-text-muted">Asignación</span><span class="block text-xs font-bold" :class="cooperative.automatic_assignment_enabled ? 'text-arka-primary' : 'text-arka-text'">{{ cooperative.automatic_assignment_enabled ? 'Automática' : 'Manual' }}</span></span>
                                </button>
                                <button type="button" class="grid h-10 w-10 shrink-0 place-items-center rounded-full border border-arka-text-muted/20 text-sm font-bold text-arka-text-muted transition hover:border-arka-primary/50 hover:text-arka-primary" aria-label="¿Cómo funciona la asignación?" @click="dispatchHelpOpen = !dispatchHelpOpen">?</button>
                            </div>

                            <div v-if="dispatchHelpOpen" class="absolute right-4 top-[5.5rem] z-20 w-[min(22rem,calc(100%-2rem))] rounded-2xl border border-arka-primary/25 bg-arka-base p-4 text-xs leading-relaxed text-arka-text-muted shadow-2xl sm:right-5 sm:top-[4.75rem]">
                                <div class="flex items-start justify-between gap-3"><div><p class="font-semibold text-arka-text">Modo de despacho</p><p class="mt-1"><strong class="text-arka-primary">Automático inteligente:</strong> compara cercanía, respuesta, calificación, cumplimiento y tiempo sin carrera dentro de esta cooperativa.</p><p class="mt-2"><strong class="text-arka-text">Manual:</strong> la solicitud llega únicamente a esta central y espera hasta que un operador elija la unidad. Ningún conductor la recibe antes.</p></div><button type="button" class="text-arka-text-muted hover:text-arka-text" aria-label="Cerrar ayuda" @click="dispatchHelpOpen = false">✕</button></div>
                            </div>
                        </div>
                        <div v-if="cooperative.stand_lat == null || cooperative.stand_lng == null" class="mx-5 mb-3 rounded-arka border border-arka-warning/30 bg-arka-warning/10 p-3 text-xs text-arka-warning">Falta ubicar la base. <Link :href="route('cooperative.profile.edit')" class="font-semibold underline">Marcar ahora</Link></div>
                        <!-- Halo de color por estado (pedido explícito del usuario: "que se
                             distinga el color del vehículo de acuerdo a su estado") — mismo
                             `color` que ya arma `mapMarkers` (in_ride/available/inactive),
                             ahora también visible en el propio ícono del vehículo, no solo
                             en la lista de abajo. -->
                        <FleetMap :markers="mapMarkers" :dark="false" :minimal-style="true" vehicle-status-ring height="360px" />
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-t border-arka-text-muted/10 px-4 py-2.5 text-[11px] text-arka-text-muted sm:px-5">
                            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background:#34d399"></span>Disponible</span>
                            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background:#38bdf8"></span>En carrera</span>
                            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background:#94a3b8"></span>Inactivo</span>
                        </div>
                        <div class="border-t border-arka-text-muted/10 p-4 sm:p-5">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div><h4 class="font-semibold text-arka-text">Estado de las unidades</h4><p class="text-xs text-arka-text-muted">Actualización automática cada 15 segundos</p></div>
                                <div class="flex flex-wrap gap-1.5 text-[11px] font-semibold">
                                    <span class="rounded-full bg-arka-primary/10 px-2.5 py-1 text-arka-primary">{{ driverStatusCount('active') }} disponibles</span>
                                    <span class="rounded-full bg-sky-400/10 px-2.5 py-1 text-sky-300">{{ driverStatusCount('in_ride') }} en carrera</span>
                                    <span class="rounded-full bg-arka-text-muted/10 px-2.5 py-1 text-arka-text-muted">{{ driverStatusCount('inactive') }} inactivos</span>
                                </div>
                            </div>

                            <p v-if="!drivers.length" class="mt-4 rounded-xl bg-arka-base/50 p-4 text-sm text-arka-text-muted">Todavía no hay conductores afiliados.</p>
                            <div v-else class="mt-4 grid gap-2 sm:grid-cols-2">
                                <Link
                                    v-for="driver in drivers"
                                    :key="driver.user_id"
                                    :href="route('cooperative.drivers.show', driver.membership_id)"
                                    class="group flex min-w-0 items-center gap-3 rounded-2xl border border-arka-text-muted/10 bg-arka-base/35 p-3 transition hover:border-arka-primary/35 hover:bg-arka-primary/5"
                                >
                                    <img v-if="driver.avatar_url" :src="driver.avatar_url" :alt="driver.name" class="h-11 w-11 shrink-0 rounded-full object-cover" />
                                    <span v-else class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-arka-primary/15 text-xs font-bold text-arka-primary">{{ initials(driver.name) }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-center gap-2"><span class="truncate text-sm font-semibold text-arka-text">{{ driver.name }}</span><span v-if="driver.verified" class="text-xs text-arka-primary" title="Conductor verificado">✓</span></span>
                                        <span class="mt-0.5 block truncate text-xs text-arka-text-muted">{{ driver.vehicle || 'Unidad sin detalle' }}{{ driver.plate ? ` · ${driver.plate}` : '' }}</span>
                                        <span v-if="driver.active_ride" class="mt-1 block truncate text-[11px] text-sky-300">Carrera #{{ driver.active_ride.id }} · {{ driver.active_ride.client_name || 'Cliente' }}</span>
                                        <span v-else class="mt-1 block text-[11px] text-arka-text-muted">{{ lastLocation(driver.location_updated_at) }}</span>
                                    </span>
                                    <span class="shrink-0 text-right">
                                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold" :class="driverStatus[driver.operational_status].badge"><span class="h-1.5 w-1.5 rounded-full" :class="driverStatus[driver.operational_status].dot"></span>{{ driverStatus[driver.operational_status].label }}</span>
                                        <span class="mt-2 block text-xs text-arka-text-muted transition group-hover:text-arka-primary">Ver detalle →</span>
                                    </span>
                                </Link>
                            </div>
                        </div>
                    </div>
                </section>
                </template>

                <section v-if="activeView === 'dispatch'" class="rounded-arka bg-arka-card shadow-lg">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-arka-text-muted/10 p-5">
                        <div><p class="text-xs font-semibold uppercase tracking-[0.15em] text-arka-primary">Central de despacho</p><h3 class="mt-1 text-lg font-semibold text-arka-text">Solicitudes pendientes</h3></div>
                        <div class="flex items-center gap-2">
                            <button type="button" class="rounded-full border border-arka-primary/30 px-3 py-1.5 text-xs font-semibold text-arka-primary transition hover:bg-arka-primary/10" @click="testDispatchSound">Probar sonido</button>
                            <span class="rounded-full bg-arka-primary/10 px-3 py-1 text-xs font-semibold text-arka-primary">{{ stats.pendingRequests }} por atender</span>
                        </div>
                    </div>
                    <p v-if="!requests.length" class="p-6 text-sm text-arka-text-muted">No hay solicitudes pendientes.</p>
                    <div v-else class="space-y-4 p-3 sm:p-5">
                        <article v-for="request in requests" :key="request.id" class="overflow-hidden rounded-2xl border border-arka-text-muted/10 bg-arka-base/45">
                            <div class="p-3.5 sm:p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0"><p class="font-semibold text-arka-text">{{ request.client.name }}</p><p class="mt-1 text-xs text-arka-text-muted">Solicitud #{{ request.id }} · {{ requestDateTimeLabel(request) }}</p><div class="mt-2 flex flex-wrap gap-1.5 text-[11px]"><span class="rounded-full px-2 py-1 font-semibold" :class="scheduleBadge(request).classes">{{ scheduleBadge(request).label }}</span><TrustScoreBadge :trust="request.client_stats.trust" compact /><span class="rounded-full bg-arka-primary/10 px-2 py-1 font-semibold text-arka-primary">{{ request.client_stats.completed_rides }} carreras completadas</span><span v-if="request.client_stats.review_count" class="rounded-full bg-arka-warning/10 px-2 py-1 text-arka-warning">★ {{ request.client_stats.average_rating }} · {{ request.client_stats.review_count }} opiniones</span><span v-if="request.client_stats.cancelled_rides" class="rounded-full bg-rose-400/10 px-2 py-1 text-rose-300">{{ request.client_stats.cancelled_rides }} canceladas</span><Link :href="route('profiles.show', request.client.public_id)" class="rounded-full border border-arka-text-muted/20 px-2 py-1 text-arka-text-muted hover:text-arka-primary">Ver perfil →</Link></div></div>
                                    <div class="flex shrink-0 items-center gap-2"><span v-if="fallbackSeconds(request) !== null" class="rounded-full px-2.5 py-1 text-xs font-bold" :class="fallbackSeconds(request) <= 10 ? 'bg-arka-warning/15 text-arka-warning' : 'bg-arka-primary/10 text-arka-primary'">Auto {{ fallbackSeconds(request) }}s</span><button v-if="!request.driver" type="button" class="rounded-full border border-arka-primary/40 px-3 py-1 text-xs font-semibold text-arka-primary" @click="toggleDispatchCard(request.id)">{{ openDispatchCards[request.id] ? 'Cerrar ↑' : 'Asignar →' }}</button></div>
                                </div>

                                <div v-if="!openDispatchCards[request.id]" class="mt-3 grid gap-2 lg:grid-cols-[minmax(0,1fr)_auto_auto] lg:items-center">
                                    <p class="truncate text-sm text-arka-text"><span class="text-arka-primary">●</span> {{ operationalOrigin(request) }} <span class="px-1 text-arka-text-muted">→</span> {{ request.destination_address || 'Destino sin dirección' }}</p>
                                    <div class="flex flex-wrap items-center gap-1.5 text-xs"><span class="rounded-full bg-arka-primary/10 px-2.5 py-1 font-bold text-arka-primary">${{ Number(request.current_offered_price ?? 0).toFixed(2) }}</span><span class="rounded-full bg-arka-card px-2.5 py-1 text-arka-text-muted">{{ Number(request.distance_km ?? 0).toFixed(1) }} km</span><span class="rounded-full bg-arka-card px-2.5 py-1 text-arka-text-muted">~{{ request.trip_eta_minutes }} min</span></div>
                                    <p class="truncate text-xs text-arka-text-muted"><template v-if="recommendedDriver(request)"><span class="font-semibold text-arka-text">Recomendado:</span> {{ recommendedDriver(request).name }} · ~{{ recommendedDriver(request).eta_minutes ?? '—' }} min</template><template v-else>Sin unidades disponibles</template></p>
                                </div>

                                <div v-if="openDispatchCards[request.id]" class="mt-4 grid gap-2 text-sm">
                                    <div class="flex gap-3"><span class="mt-1 h-3 w-3 shrink-0 rounded-full border-[3px] border-arka-primary"></span><div class="min-w-0 flex-1"><p class="text-[11px] uppercase tracking-wide text-arka-text-muted">Recoger en</p><p class="break-words text-arka-text">{{ operationalOrigin(request) }}</p><a :href="`https://www.google.com/maps?q=${request.origin_lat},${request.origin_lng}`" target="_blank" rel="noopener" class="mt-1 inline-block text-xs font-semibold text-arka-primary hover:underline">Abrir punto en el mapa ↗</a></div></div>
                                    <div class="ml-[5px] h-3 border-l border-dashed border-arka-text-muted/40"></div>
                                    <div class="flex gap-3"><span class="mt-1 h-3 w-3 shrink-0 rounded-sm bg-arka-warning"></span><div class="min-w-0"><p class="text-[11px] uppercase tracking-wide text-arka-text-muted">Destino</p><p class="truncate text-arka-text">{{ request.destination_address || 'Destino sin dirección' }}</p></div></div>
                                </div>
                                <div v-if="openDispatchCards[request.id]" class="mt-4 grid grid-cols-3 gap-2">
                                    <div class="rounded-xl border border-arka-text-muted/10 bg-arka-card px-3 py-2.5">
                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-arka-text-muted">Valor</p>
                                        <p class="mt-0.5 text-base font-bold text-arka-primary">${{ Number(request.current_offered_price ?? 0).toFixed(2) }}</p>
                                    </div>
                                    <div class="rounded-xl border border-arka-text-muted/10 bg-arka-card px-3 py-2.5">
                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-arka-text-muted">Distancia</p>
                                        <p class="mt-0.5 text-base font-bold text-arka-text">{{ Number(request.distance_km ?? 0).toFixed(1) }} km</p>
                                    </div>
                                    <div class="rounded-xl border border-arka-text-muted/10 bg-arka-card px-3 py-2.5">
                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-arka-text-muted">Duración est.</p>
                                        <p class="mt-0.5 text-base font-bold text-arka-text">~{{ request.trip_eta_minutes }} min</p>
                                    </div>
                                </div>
                                <p v-if="request.is_scheduled && openDispatchCards[request.id]" class="mt-3 rounded-xl bg-arka-warning/10 px-3 py-2 text-xs text-arka-warning">Programada: {{ new Date(request.scheduled_at).toLocaleString('es-EC') }}</p>
                            </div>

                            <div v-if="request.status === 'pending' && !request.driver && openDispatchCards[request.id]" class="border-t border-arka-text-muted/10 p-4 sm:p-5">
                                <div class="mb-3 flex items-center justify-between"><div><h4 class="text-sm font-semibold text-arka-text">Elija una unidad</h4><p class="text-xs text-arka-text-muted">Ordenadas por cercanía al punto de origen</p></div><span class="text-xs text-arka-text-muted">{{ eligibleDrivers(request).length }} disponibles</span></div>
                                <p v-if="!eligibleDrivers(request).length" class="rounded-xl border border-arka-warning/30 bg-arka-warning/10 p-3 text-sm text-arka-warning">No hay unidades disponibles. El sistema seguirá verificando automáticamente.</p>
                                <div v-else class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    <button v-for="(driver, index) in visibleDrivers(request)" :key="driver.user_id" type="button" class="relative flex min-w-0 items-center gap-3 rounded-xl border p-3 text-left transition" :class="selectedDrivers[request.id] === driver.user_id ? 'border-arka-primary bg-arka-primary/10 ring-1 ring-arka-primary' : 'border-arka-text-muted/15 bg-arka-card hover:border-arka-primary/50'" @click="selectedDrivers[request.id] = driver.user_id">
                                        <span v-if="index === 0" class="absolute -right-1 -top-2 rounded-full bg-arka-primary px-2 py-0.5 text-[9px] font-bold uppercase text-arka-base">Recomendado</span>
                                        <img v-if="driver.avatar_url" :src="driver.avatar_url" :alt="driver.name" class="h-10 w-10 shrink-0 rounded-full object-cover" /><span v-else class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-arka-primary/15 text-xs font-bold text-arka-primary">{{ initials(driver.name) }}</span>
                                        <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold text-arka-text">{{ driver.name }}</span><span class="block truncate text-[11px] text-arka-text-muted">{{ driver.vehicle || 'Unidad registrada' }}{{ driver.plate ? ` · ${driver.plate}` : '' }}</span><span class="mt-1 block text-xs font-semibold text-arka-primary">{{ driver.distance_km != null ? `${driver.distance_km} km · ~${driver.eta_minutes} min` : 'Sin GPS reciente' }}</span></span>
                                        <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full border" :class="selectedDrivers[request.id] === driver.user_id ? 'border-arka-primary bg-arka-primary text-arka-base' : 'border-arka-text-muted/30'"><span v-if="selectedDrivers[request.id] === driver.user_id">✓</span></span>
                                    </button>
                                </div>
                                <button v-if="eligibleDrivers(request).length > 3" type="button" class="mt-3 text-xs font-semibold text-arka-primary" @click="expandedRequests[request.id] = !expandedRequests[request.id]">{{ expandedRequests[request.id] ? 'Ver menos unidades' : `Ver las ${eligibleDrivers(request).length} unidades` }}</button>

                                <div class="mt-4 flex flex-col gap-3 rounded-xl bg-arka-card p-3 sm:flex-row sm:items-center sm:justify-between">
                                    <p class="text-xs text-arka-text-muted"><template v-if="selectedDriver(request)"><strong class="text-arka-text">{{ selectedDriver(request).name }}</strong> llegará en aproximadamente {{ selectedDriver(request).eta_minutes ?? '—' }} min.</template><template v-else>Seleccione una unidad para continuar.</template></p>
                                    <button type="button" class="w-full shrink-0 rounded-full bg-arka-primary px-5 py-2.5 text-sm font-bold text-arka-base disabled:cursor-not-allowed disabled:opacity-40 sm:w-auto" :disabled="!selectedDrivers[request.id] || assigningRequests[request.id]" @click="assign(request)">{{ assigningRequests[request.id] ? 'Asignando…' : 'Confirmar asignación →' }}</button>
                                </div>
                            </div>
                            <div v-else-if="request.driver" class="border-t border-arka-text-muted/10 bg-arka-primary/5 p-4">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-sm text-arka-text">Esperando respuesta de <strong>{{ request.driver.name }}</strong></p>
                                        <p v-if="request.smart_dispatch_recommendation" class="mt-1 flex items-center gap-1.5 text-xs text-arka-primary">
                                            <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m13.2 2-8 11h6l-.4 9 8-12h-6l.4-8Z" /></svg>
                                            Selección inteligente · {{ request.smart_dispatch_recommendation.reason }}
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        <!-- Pedido explícito del usuario: cuánto le queda antes de que el
                                             motor la pase al siguiente conductor de la cooperativa. Las
                                             programadas no tienen vencimiento (el conductor confirma cuando
                                             puede), por eso offerSecondsLeft() da null y no se muestra nada. -->
                                        <span v-if="offerSecondsLeft(request) !== null" class="rounded-full px-2.5 py-1 text-xs font-bold" :class="offerSecondsLeft(request) <= 10 ? 'bg-arka-warning/15 text-arka-warning' : 'bg-arka-primary/10 text-arka-primary'">
                                            Vence en {{ offerSecondsLeft(request) }}s
                                        </span>
                                        <span class="h-2.5 w-2.5 shrink-0 animate-pulse rounded-full bg-arka-primary"></span>
                                    </div>
                                </div>
                                <!-- Pedido explícito del usuario ("le dice a la de la cooperativa a
                                     quien se la asignaron y la cancelo?"): antes esto no quedaba
                                     visible en ningún lado — antes de esta unidad se le ofreció a
                                     otra(s) que no respondieron o la rechazaron. -->
                                <ul v-if="request.cooperative_dispatch_log?.length" class="mt-3 space-y-1 border-t border-arka-text-muted/10 pt-2">
                                    <li v-for="(attempt, index) in request.cooperative_dispatch_log" :key="index" class="text-xs text-arka-text-muted">
                                        Antes se le ofreció a <strong class="text-arka-text">{{ attempt.driver_name ?? 'un conductor que ya no está en la cooperativa' }}</strong> y {{ DISPATCH_OUTCOME_LABEL[attempt.outcome] ?? 'no respondió' }}.
                                    </li>
                                </ul>
                            </div>
                        </article>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
