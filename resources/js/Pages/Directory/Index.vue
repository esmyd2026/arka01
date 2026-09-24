<script setup>
import { ref, computed, onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import RatingStars from '@/Components/RatingStars.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import TrustScoreBadge from '@/Components/TrustScoreBadge.vue';
import DriverCategoryBadge from '@/Components/DriverCategoryBadge.vue';
import FleetMap from '@/Components/FleetMap.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { tierColorClass, tierLabel } from '@/Utils/tierBadge';

// Quito, de respaldo hasta que el navegador entregue la ubicación real o el
// cliente toque el mapa (mismo centro por defecto que el resto de la app).
const FALLBACK_CENTER = { lat: -0.1807, lng: -78.4678 };
const DEFAULT_RADIUS_KM = 2;

const loading = ref(true);
const error = ref(null);
const drivers = ref([]);
const targetFleetId = ref(null);
const invitingId = ref(null);

const radiusKm = ref(DEFAULT_RADIUS_KM);
const searchCenter = ref(null);
// Distingue "estoy mostrando tu ubicación real" de "elegiste otro punto en
// el mapa" (pedido explícito del usuario: "puedo también cambiar en el mapa
// y me recomienda") — solo para poder ofrecer el botón de volver a la
// ubicación real.
const usingCurrentLocation = ref(true);
let fetchToken = 0;

const isClient = usePage().props.auth.isClient;

async function fetchNearby() {
    if (!searchCenter.value) return;

    const token = ++fetchToken;
    loading.value = true;
    error.value = null;

    try {
        const { data } = await window.axios.get(route('directory.nearby'), {
            params: { lat: searchCenter.value.lat, lng: searchCenter.value.lng, radius_km: radiusKm.value },
        });
        // Descarta una respuesta vieja si el radio/centro cambió de nuevo
        // mientras esta petición todavía estaba en camino.
        if (token !== fetchToken) return;
        drivers.value = data.drivers;
        targetFleetId.value = data.targetFleetId;
    } catch (e) {
        if (token !== fetchToken) return;
        error.value = e.response?.data?.message || 'No se pudo cargar el mapa de conductores.';
    } finally {
        if (token === fetchToken) loading.value = false;
    }
}

// Debounce simple: mover el slider de radio dispara varios "input" seguidos,
// no hace falta pedir al servidor en cada pixel de arrastre.
let radiusDebounce = null;
function onRadiusChange() {
    clearTimeout(radiusDebounce);
    radiusDebounce = setTimeout(fetchNearby, 300);
}

function useMyLocation() {
    if (!navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition((position) => {
        searchCenter.value = { lat: position.coords.latitude, lng: position.coords.longitude };
        usingCurrentLocation.value = true;
        fetchNearby();
    });
}

// Pedido explícito del usuario: "puedo también cambiar en el mapa y me
// recomienda" — tocar el mapa reubica el centro de búsqueda ahí.
function onMapClick({ lat, lng }) {
    searchCenter.value = { lat, lng };
    usingCurrentLocation.value = false;
    fetchNearby();
}

onMounted(() => {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            (position) => {
                searchCenter.value = { lat: position.coords.latitude, lng: position.coords.longitude };
                fetchNearby();
            },
            () => {
                // Sin permiso de ubicación: arranca en Quito, el cliente
                // puede tocar el mapa para buscar en otro lado igual.
                searchCenter.value = { ...FALLBACK_CENTER };
                usingCurrentLocation.value = false;
                loading.value = false;
                error.value = 'No pudimos acceder a tu ubicación. Tocá el mapa para buscar conductores cerca de otro punto.';
            }
        );
    } else {
        searchCenter.value = { ...FALLBACK_CENTER };
        usingCurrentLocation.value = false;
        loading.value = false;
    }
});

// Zoom acorde al radio elegido: con 2km de por defecto conviene ver la
// cuadra, con 25km conviene ver media ciudad — fitBounds automático no
// sirve acá porque el radio, no los marcadores, es lo que define el
// encuadre (con 0 conductores el mapa igual tiene que mostrar el radio).
const mapZoom = computed(() => {
    if (radiusKm.value <= 1) return 15;
    if (radiusKm.value <= 3) return 14;
    if (radiusKm.value <= 6) return 12;
    if (radiusKm.value <= 12) return 11;
    return 10;
});

const mapMarkers = computed(() => {
    const markers = drivers.value
        .filter((driver) => driver.lat != null && driver.lng != null)
        .map((driver) => ({
            id: `driver-${driver.user_id}`,
            lat: driver.lat,
            lng: driver.lng,
            type: 'car',
            color: driver.is_available ? '#34d399' : '#9ca3af',
            label: driver.name,
        }));

    if (searchCenter.value) {
        markers.push({
            id: 'origin',
            lat: searchCenter.value.lat,
            lng: searchCenter.value.lng,
            type: 'origin',
        });
    }

    return markers;
});

function invite(driver) {
    router.post(
        route('fleet.invitations.store', targetFleetId.value),
        { driver_user_id: driver.user_id },
        {
            preserveScroll: true,
            onSuccess: () => {
                driver.status = 'pending';
            },
        }
    );
}
</script>

<template>
    <Head title="Conductores cerca de mí" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-arka-text leading-tight">Conductores cerca de mí</h2>
        </template>

        <div class="py-6 sm:py-10">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
                <p class="text-sm text-arka-text-muted">
                    Conductores con visibilidad pública ubicados cerca del punto marcado en el mapa — ya sea tu
                    ubicación actual o uno que elijas tocando el mapa. Si la experiencia es buena, invítelo a su
                    flota de confianza.
                </p>

                <!-- Barra de radio (pedido explícito del usuario: "una barra arriba
                     que indique el radio de 2km por defecto y que le suba si quiere"). -->
                <div class="rounded-arka border border-arka-border bg-arka-card p-3 sm:p-4 space-y-2">
                    <div class="flex items-center justify-between gap-3">
                        <label for="radius_km" class="text-sm font-medium text-arka-text">
                            Radio de búsqueda: <span class="text-arka-primary">{{ radiusKm }} km</span>
                        </label>
                        <button
                            v-if="!usingCurrentLocation"
                            type="button"
                            class="text-xs text-arka-primary hover:text-arka-primary-bright font-medium shrink-0"
                            @click="useMyLocation"
                        >
                            Usar mi ubicación
                        </button>
                    </div>
                    <input
                        id="radius_km"
                        type="range"
                        min="0.5"
                        max="25"
                        step="0.5"
                        v-model.number="radiusKm"
                        class="w-full accent-arka-primary"
                        @input="onRadiusChange"
                    />
                </div>

                <!-- Mapa casi a pantalla completa (pedido explícito del usuario) —
                     tocar el mapa reubica el punto de búsqueda ahí mismo. -->
                <FleetMap
                    :markers="mapMarkers"
                    :center="searchCenter ?? FALLBACK_CENTER"
                    :zoom="mapZoom"
                    height="60vh"
                    clickable
                    :auto-fit="false"
                    :dark="false"
                    :minimal-style="true"
                    origin-marker-style="dot"
                    @map-click="onMapClick"
                />

                <p v-if="error" class="p-3 rounded-arka bg-red-500/10 border border-red-500/40 text-sm text-red-600 font-medium">{{ error }}</p>

                <div v-if="loading" class="p-4 text-center text-sm text-arka-text-muted">Buscando conductores cerca…</div>
                <p v-else-if="!drivers.length" class="p-6 bg-arka-card shadow rounded-arka text-center text-arka-text-muted">
                    No hay conductores públicos dentro de este radio. Probá subir el radio o mover el mapa a otra zona.
                </p>
                <p v-else class="text-sm text-arka-text-muted">
                    {{ drivers.length }} conductor{{ drivers.length === 1 ? '' : 'es' }} dentro de {{ radiusKm }} km.
                </p>

                <div v-if="drivers.length" class="space-y-3">
                    <div v-for="driver in drivers" :key="driver.user_id" class="rounded-2xl border border-arka-border bg-arka-card p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-arka-primary/45 hover:shadow-md sm:p-6">
                        <div class="flex items-start justify-between gap-4">
                            <UserAvatar :user="driver" size-class="h-12 w-12 text-sm shrink-0" />
                            <div class="flex-1">
                                <Link
                                    :href="route('profiles.show', driver.public_id)"
                                    class="text-arka-text font-medium hover:text-arka-primary-bright"
                                >
                                    {{ driver.name }}
                                </Link>
                                <span v-if="driver.is_verified" class="ms-1 text-xs text-arka-primary-bright" title="Conductor verificado">✓</span>
                                <span
                                    v-if="driver.tier"
                                    class="ms-1.5 px-1.5 py-0.5 rounded text-[10px] font-medium"
                                    :class="tierColorClass(driver.tier.color_key)"
                                >
                                    {{ tierLabel(driver.tier) }}
                                </span>
                                <div class="mt-1">
                                    <RatingStars
                                        :rating="driver.average_rating ?? 0"
                                        :count="driver.review_count"
                                        readonly
                                    />
                                </div>
                                <TrustScoreBadge :trust="driver.trust" class="mt-2" />
                                <DriverCategoryBadge class="mt-1" :label="driver.public_category_label" />
                                <p v-if="driver.cooperative" class="mt-1 text-xs text-arka-text-muted">
                                    Afiliado a <Link :href="route('cooperatives.show', driver.cooperative.public_id)" class="text-arka-primary hover:underline">{{ driver.cooperative.name }}</Link>
                                </p>
                                <p class="mt-1 text-xs text-arka-text-muted">
                                    {{ driver.rides_count }} carrera{{ driver.rides_count === 1 ? '' : 's' }} completada{{ driver.rides_count === 1 ? '' : 's' }}
                                    · {{ driver.clients_count }} cliente{{ driver.clients_count === 1 ? '' : 's' }} lo tienen agregado
                                </p>
                                <!-- Zona declarada (pedido explícito del usuario: "debemos
                                     identificarlos también") — informativo, NO es lo que lo
                                     hizo aparecer acá: el criterio real es su ubicación en
                                     vivo dentro del radio, declare o no una zona. -->
                                <p v-if="driver.coverage_sectors?.length" class="mt-1 text-xs text-arka-text-muted">
                                    Declara trabajar en: {{ driver.coverage_sectors.map((s) => s.name).join(', ') }}
                                </p>
                                <p v-else class="mt-1 text-xs text-arka-text-muted/70">
                                    Zona de trabajo no declarada — aparece por su ubicación actual.
                                </p>
                                <p class="mt-1 text-sm text-arka-text-muted">
                                    ${{ driver.rate_per_km }}/km
                                    <span v-if="driver.vehicle_type"> · {{ driver.vehicle_type }}</span>
                                    <span v-if="driver.distance_km != null"> · a {{ driver.distance_km.toFixed(1) }} km</span>
                                    <span v-if="!driver.is_available"> · no disponible ahora</span>
                                </p>
                            </div>

                            <div class="flex flex-col items-end gap-1.5 shrink-0">
                                <Link
                                    v-if="isClient"
                                    :href="route('ride-requests.create', { flota: targetFleetId, conductor: driver.public_id })"
                                    class="text-xs text-arka-primary hover:text-arka-primary-bright font-medium"
                                >
                                    Pedir carrera
                                </Link>
                                <PrimaryButton v-if="driver.status === 'not_invited'" @click="invite(driver)">
                                    Agregar a mi flota
                                </PrimaryButton>
                                <span v-else-if="driver.status === 'pending'" class="text-sm text-arka-lime">
                                    Invitación enviada
                                </span>
                                <span v-else class="text-sm text-arka-text-muted">Ya está en su flota</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
