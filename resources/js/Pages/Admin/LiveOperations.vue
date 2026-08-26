<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FleetMap from '@/Components/FleetMap.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { Head, Link, router } from '@inertiajs/vue3';

// Pedido explícito del usuario: "ver las transaciones que se estan
// ejecutando ahorita... cliente esperando conductor de tal lado a tal lado
// por tanto y que salga las unidades cercanas... y las demas transaciones
// tambien... la idea es ver y muestra en el mapa tambien." Cada solicitud
// en espera y cada carrera en curso es su propia tarjeta con detalle real,
// no un agregado — distinto de /admin/operaciones (demanda histórica).
const props = defineProps({
    waitingRequests: { type: Array, required: true },
    activeRides: { type: Array, required: true },
    stats: { type: Object, required: true },
});

// Sin canal de Echo para esto (no existe uno admin para carreras, ver
// routes/channels.php) — mismo criterio ya probado en
// Cooperative/Dashboard.vue: polling de 15s, pausado si la pestaña está en
// segundo plano, más refresco inmediato al volver.
const clock = ref(Date.now());
let refreshTimer;
let clockTimer;
const refresh = () => {
    if (document.hidden) return;
    router.reload({ only: ['waitingRequests', 'activeRides', 'stats'], preserveScroll: true });
};
onMounted(() => {
    refreshTimer = window.setInterval(refresh, 15000);
    clockTimer = window.setInterval(() => { clock.value = Date.now(); }, 1000);
    document.addEventListener('visibilitychange', refresh);
});
onBeforeUnmount(() => {
    window.clearInterval(refreshTimer);
    window.clearInterval(clockTimer);
    document.removeEventListener('visibilitychange', refresh);
});

function elapsedLabel(isoDate) {
    if (!isoDate) return '—';
    const seconds = Math.max(0, Math.floor((clock.value - new Date(isoDate).getTime()) / 1000));
    if (seconds < 60) return `${seconds}s`;
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes} min`;
    return `${Math.floor(minutes / 60)}h ${minutes % 60}min`;
}

function offerSecondsLeft(isoDate) {
    if (!isoDate) return null;
    return Math.max(0, Math.ceil((new Date(isoDate).getTime() - clock.value) / 1000));
}

const WAITING_PHASE_LABEL = {
    searching: 'Buscando conductor',
    awaiting_driver: 'Esperando respuesta del conductor',
};
const RIDE_PHASE_LABEL = {
    accepted: 'Carrera aceptada, por salir',
    heading_to_passenger: 'En camino a recoger al pasajero',
    arrived_waiting_pickup: 'Llegó, esperando al pasajero',
    en_route_to_destination: 'Llevando al pasajero al destino',
};
const RIDE_PHASE_COLOR = {
    accepted: 'bg-arka-text-muted/15 text-arka-text-muted',
    heading_to_passenger: 'bg-arka-warning/15 text-arka-warning',
    arrived_waiting_pickup: 'bg-arka-warning/15 text-arka-warning',
    en_route_to_destination: 'bg-sky-400/15 text-sky-300',
};

function initials(name) {
    return (name ?? '').split(' ').slice(0, 2).map((part) => part.charAt(0)).join('').toUpperCase();
}

// Mapa combinado (mismo criterio que Cooperative/Dashboard.vue): el backend
// manda datos crudos, acá se arman los marcadores con color/tipo — pin
// verde para dónde espera cada cliente, auto celeste para cada carrera en
// curso, auto gris para las unidades cercanas disponibles (sin repetir el
// mismo conductor si aparece cerca de más de una solicitud).
const mapMarkers = computed(() => {
    const markers = [];

    props.waitingRequests.forEach((request) => {
        markers.push({
            id: `request-${request.id}`,
            type: 'origin',
            lat: request.origin_lat,
            lng: request.origin_lng,
            label: `${request.client.name} · esperando`,
        });
    });

    props.activeRides.forEach((ride) => {
        if (ride.driver.lat != null && ride.driver.lng != null) {
            markers.push({
                id: `ride-driver-${ride.id}`,
                lat: ride.driver.lat,
                lng: ride.driver.lng,
                label: `${ride.driver.name} · en carrera`,
                color: '#38bdf8',
            });
        }
    });

    // Pedido explícito del usuario: "la idea es ver y muestra en el mapa
    // tambien" — las unidades cercanas de CADA solicitud, sin repetir el
    // mismo conductor si aparece cerca de más de una a la vez.
    const seenDriverIds = new Set();
    props.waitingRequests.forEach((request) => {
        request.nearby_drivers.forEach((driver) => {
            if (seenDriverIds.has(driver.user_id)) return;
            seenDriverIds.add(driver.user_id);
            markers.push({
                id: `nearby-${driver.user_id}`,
                lat: driver.lat,
                lng: driver.lng,
                label: `${driver.name} · disponible`,
                color: '#94a3b8',
            });
        });
    });

    return markers;
});

const hasAnyLocatedNearbyDriver = computed(() => props.waitingRequests.some((r) => r.nearby_drivers.length));
</script>

<template>
    <Head title="Admin · Operaciones en vivo" />

    <AdminLayout title="Operaciones en vivo">
        <div class="py-12">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="grid grid-cols-3 gap-4">
                    <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka text-center">
                        <p class="text-2xl font-semibold text-arka-warning">{{ stats.waiting }}</p>
                        <p class="text-xs text-arka-text-muted">Esperando conductor</p>
                    </div>
                    <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka text-center">
                        <p class="text-2xl font-semibold text-sky-300">{{ stats.in_progress }}</p>
                        <p class="text-xs text-arka-text-muted">Carreras en curso</p>
                    </div>
                    <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka text-center">
                        <p class="text-2xl font-semibold text-arka-primary">{{ stats.available_drivers }}</p>
                        <p class="text-xs text-arka-text-muted">Conductores disponibles</p>
                    </div>
                </div>

                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium text-arka-text">Mapa en vivo</h3>
                        <div class="flex flex-wrap items-center gap-3 text-[11px] text-arka-text-muted">
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-arka-primary"></span> Esperando</span>
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-sky-400"></span> En carrera</span>
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-slate-400"></span> Disponible cerca</span>
                        </div>
                    </div>
                    <p v-if="!mapMarkers.length" class="text-sm text-arka-text-muted">No hay transacciones activas en este momento.</p>
                    <FleetMap v-else :markers="mapMarkers" height="420px" />
                </div>

                <!-- "cliente esperando conductor de tal lado a tal lado por tanto,
                     que salga las unidades cercanas" (pedido explícito del usuario, casi textual) -->
                <section class="space-y-3">
                    <h2 class="text-lg font-semibold text-arka-text">Esperando conductor ({{ waitingRequests.length }})</h2>
                    <p v-if="!waitingRequests.length" class="text-sm text-arka-text-muted">No hay nadie esperando conductor ahora mismo.</p>

                    <article v-for="request in waitingRequests" :key="request.id" class="p-4 sm:p-5 bg-arka-card shadow rounded-arka space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <UserAvatar :user="request.client" size-class="h-10 w-10 text-sm shrink-0" />
                                <div class="min-w-0">
                                    <Link :href="route('profiles.show', request.client.id)" class="font-semibold text-arka-text hover:text-arka-primary truncate block">
                                        {{ request.client.name }}
                                    </Link>
                                    <p class="text-xs text-arka-text-muted">
                                        Solicitud #{{ request.id }} · esperando hace {{ elapsedLabel(request.requested_at) }}
                                        <span v-if="request.fleet_owner_name && request.fleet_owner_name !== request.client.name"> · flota de {{ request.fleet_owner_name }}</span>
                                    </p>
                                </div>
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold" :class="request.phase === 'awaiting_driver' ? 'bg-arka-primary/15 text-arka-primary-bright' : 'bg-arka-warning/15 text-arka-warning'">
                                {{ WAITING_PHASE_LABEL[request.phase] }}
                            </span>
                        </div>

                        <!-- "de tal lado a tal lado por tanto" -->
                        <p class="text-sm text-arka-text">
                            <span class="text-arka-primary">●</span> {{ request.origin_address || 'Origen sin dirección' }}
                            <span class="px-1 text-arka-text-muted">→</span>
                            {{ request.destination_address || 'Destino sin dirección' }}
                        </p>
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span v-if="request.price != null" class="rounded-full bg-arka-primary/10 px-2.5 py-1 font-bold text-arka-primary">${{ request.price.toFixed(2) }}</span>
                            <span v-if="request.distance_km != null" class="rounded-full bg-arka-base/60 px-2.5 py-1 text-arka-text-muted">{{ request.distance_km.toFixed(1) }} km</span>
                            <span v-if="request.is_scheduled" class="rounded-full bg-arka-warning/10 px-2.5 py-1 text-arka-warning">🗓 Programada</span>
                        </div>

                        <div v-if="request.phase === 'awaiting_driver'" class="flex items-center gap-2 rounded-arka bg-arka-primary/5 p-3">
                            <UserAvatar :user="request.assigned_driver" size-class="h-8 w-8 text-xs shrink-0" />
                            <p class="flex-1 text-sm text-arka-text">
                                Esperando respuesta de <strong>{{ request.assigned_driver?.name }}</strong>
                            </p>
                            <span v-if="offerSecondsLeft(request.offer_expires_at) !== null" class="rounded-full px-2.5 py-1 text-xs font-bold" :class="offerSecondsLeft(request.offer_expires_at) <= 10 ? 'bg-arka-warning/15 text-arka-warning' : 'bg-arka-primary/10 text-arka-primary'">
                                Vence en {{ offerSecondsLeft(request.offer_expires_at) }}s
                            </span>
                        </div>

                        <!-- "que salga las unidas cercadas" -->
                        <div>
                            <p class="text-xs font-medium text-arka-text-muted uppercase tracking-wide mb-1.5">Unidades cercanas</p>
                            <p v-if="!request.nearby_drivers.length" class="text-sm text-arka-warning">No hay conductores disponibles cerca de este punto.</p>
                            <div v-else class="flex flex-wrap gap-2">
                                <div v-for="driver in request.nearby_drivers" :key="driver.user_id" class="flex items-center gap-2 rounded-full border border-arka-text-muted/15 bg-arka-base/40 pl-1 pr-3 py-1">
                                    <img v-if="driver.avatar_url" :src="driver.avatar_url" :alt="driver.name" class="h-6 w-6 rounded-full object-cover" />
                                    <span v-else class="grid h-6 w-6 place-items-center rounded-full bg-arka-primary/15 text-[10px] font-bold text-arka-primary">{{ initials(driver.name) }}</span>
                                    <span class="text-xs text-arka-text">{{ driver.name }} · {{ driver.distance_km }} km (~{{ driver.eta_minutes }} min)</span>
                                </div>
                            </div>
                        </div>
                    </article>
                </section>

                <!-- "esta en curso una carrera" (pedido explícito del usuario) -->
                <section class="space-y-3">
                    <h2 class="text-lg font-semibold text-arka-text">Carreras en curso ({{ activeRides.length }})</h2>
                    <p v-if="!activeRides.length" class="text-sm text-arka-text-muted">No hay carreras en curso ahora mismo.</p>

                    <article v-for="ride in activeRides" :key="ride.id" class="p-4 sm:p-5 bg-arka-card shadow rounded-arka space-y-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <UserAvatar :user="ride.driver" size-class="h-10 w-10 text-sm shrink-0" />
                                <div class="min-w-0">
                                    <p class="font-semibold text-arka-text truncate">
                                        <Link :href="route('profiles.show', ride.driver.id)" class="hover:text-arka-primary">{{ ride.driver.name }}</Link>
                                        <span class="text-arka-text-muted font-normal"> lleva a </span>
                                        <Link :href="route('profiles.show', ride.client.id)" class="hover:text-arka-primary">{{ ride.client.name }}</Link>
                                    </p>
                                    <p class="text-xs text-arka-text-muted">Carrera #{{ ride.id }} · en curso hace {{ elapsedLabel(ride.started_at) }}</p>
                                </div>
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold" :class="RIDE_PHASE_COLOR[ride.phase]">
                                {{ RIDE_PHASE_LABEL[ride.phase] }}
                            </span>
                        </div>

                        <p class="text-sm text-arka-text">
                            <span class="text-arka-primary">●</span> {{ ride.origin_address || 'Origen sin dirección' }}
                            <span class="px-1 text-arka-text-muted">→</span>
                            {{ ride.destination_address || 'Destino sin dirección' }}
                        </p>
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <span v-if="ride.price != null" class="rounded-full bg-arka-primary/10 px-2.5 py-1 font-bold text-arka-primary">${{ ride.price.toFixed(2) }}</span>
                            <span v-if="ride.distance_km != null" class="rounded-full bg-arka-base/60 px-2.5 py-1 text-arka-text-muted">{{ ride.distance_km.toFixed(1) }} km</span>
                        </div>
                    </article>
                </section>

                <p v-if="!hasAnyLocatedNearbyDriver && waitingRequests.length" class="text-xs text-arka-text-muted">
                    Ningún conductor disponible tiene ubicación reciente cerca de las solicitudes en espera.
                </p>
            </div>
        </div>
    </AdminLayout>
</template>
