<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Geolocation } from '@capacitor/geolocation';
import { fetchFleet, searchDrivers } from '../services/fleet';
import { createRideRequest, fetchRideRequestCooperatives } from '../services/rides';
import { fetchDirectory } from '../services/directory';
import AddressAutocomplete from '../components/AddressAutocomplete.vue';
import MobileShell from '../components/MobileShell.vue';
import MobileMap from '../components/MobileMap.vue';

const router = useRouter();
const route = useRoute();

// Origen: se detecta solo (roadmap Hito 5) — nada que escribir la mayoría
// de las veces, que es justo el punto de pedir la carrera desde el
// teléfono en vez de la web.
const locating = ref(true);
const locationError = ref(null);
const origin = ref(null); // { lat, lng }
const originAddress = ref('Tu ubicación actual');

const fleetId = ref(null);
const destinationAddress = ref('');
const destination = ref(null); // { lat, lng, address }
const stops = ref([]);
const editingRoute = ref(true);
const paymentMethod = ref('efectivo');
const passengerCount = ref(1);
const needsTrunk = ref(false);
const roundTrip = ref(false);
const notes = ref('');
const isScheduled = ref(false);
const scheduledDate = ref('');
const scheduledTime = ref('');

const driverQuery = ref('');
const driverResults = ref(null);
const searchingDrivers = ref(false);
const chosenDriver = ref(null); // { user_id, name } o null = toda la flota

// Cooperativa como alternativa a "mi flota"/directorio (pedido explícito
// del usuario: "si el conductor pertenece a una cooperativa etc") — mismas
// opciones que Ride/Request.vue (web), vía
// App\Services\Ride\RideRequestCooperativeOptions.
const provider = ref('driver'); // 'driver' | 'cooperative'
const dispatchPool = ref('fleet'); // misma categoría inicial recomendada que la web
const cooperatives = ref([]);
const chosenCooperative = ref(null);
const publicDrivers = ref([]);
const publicDriversLoading = ref(false);

const submitting = ref(false);
const error = ref(null);
const hasRoute = computed(() => Boolean(origin.value && destination.value));
const routeReady = computed(() => hasRoute.value && !editingRoute.value);
const hasUnresolvedStop = computed(() => stops.value.some((stop) => stop.lat == null || stop.lng == null));
const canConfirmRoute = computed(() => hasRoute.value && !hasUnresolvedStop.value);
const canSubmit = computed(() => Boolean(
    origin.value
    && destination.value
    && !hasUnresolvedStop.value
    && (!isScheduled.value || (scheduledDate.value && scheduledTime.value))
));

function numberFromQuery(value) {
    const parsed = Number(Array.isArray(value) ? value[0] : value);
    return Number.isFinite(parsed) ? parsed : null;
}

async function detectLocation() {
    locating.value = true;
    locationError.value = null;
    try {
        const permission = await Geolocation.checkPermissions();
        if (permission.location !== 'granted' && permission.coarseLocation !== 'granted') {
            const requested = await Geolocation.requestPermissions();
            if (requested.location !== 'granted' && requested.coarseLocation !== 'granted') {
                locationError.value = 'Sin permiso de ubicación no se puede detectar el origen. Activalo en Ajustes.';
                locating.value = false;
                return;
            }
        }

        const position = await Geolocation.getCurrentPosition({ enableHighAccuracy: true, timeout: 15000 });
        origin.value = { lat: position.coords.latitude, lng: position.coords.longitude };
        originAddress.value = 'Tu ubicación actual';
    } catch (e) {
        locationError.value = 'No se pudo detectar tu ubicación. Revisá el GPS e intentá de nuevo.';
    } finally {
        locating.value = false;
    }
}

onMounted(async () => {
    isScheduled.value = route.query.programar === '1';
    const originLat = numberFromQuery(route.query.origin_lat);
    const originLng = numberFromQuery(route.query.origin_lng);
    const destinationLat = numberFromQuery(route.query.destination_lat);
    const destinationLng = numberFromQuery(route.query.destination_lng);

    if (originLat != null && originLng != null) {
        origin.value = { lat: originLat, lng: originLng };
        originAddress.value = String(route.query.origin_address || 'Tu ubicación actual');
        locating.value = false;
    } else {
        detectLocation();
    }

    if (destinationLat != null && destinationLng != null) {
        destinationAddress.value = String(route.query.destination_address || 'Destino seleccionado');
        destination.value = { lat: destinationLat, lng: destinationLng, address: destinationAddress.value };
        editingRoute.value = false;
    }
    try {
        const data = await fetchFleet();
        fleetId.value = data.fleets[0]?.id ?? null;
        const preselected = sessionStorage.getItem('arka-preselected-driver');
        if (preselected) {
            try { chosenDriver.value = JSON.parse(preselected); }
            finally { sessionStorage.removeItem('arka-preselected-driver'); }
        }
    } catch (e) {
        // Sin flota disponible todavía no bloquea pedir "a toda la flota"
        // — el servidor la crea sola si hace falta (mismo respaldo que la
        // web, ver RideRequestCreator::resolveFleet()).
    }
    try { cooperatives.value = await fetchRideRequestCooperatives(origin.value?.lat, origin.value?.lng); }
    catch (e) { /* Sin cooperativas disponibles no bloquea el resto de la pantalla. */ }
    publicDriversLoading.value = true;
    try {
        const directory = await fetchDirectory(1, origin.value);
        publicDrivers.value = directory.drivers ?? [];
    } catch (e) { /* El despacho público sigue disponible aunque falle el listado. */ }
    finally { publicDriversLoading.value = false; }
});

function onDestinationSelected(place) {
    destination.value = { lat: place.lat, lng: place.lng, address: place.address };
}

function onDestinationCleared() {
    destination.value = null;
}

function onOriginSelected(place) {
    originAddress.value = place.address;
    origin.value = { lat: place.lat, lng: place.lng };
    locationError.value = null;
    locating.value = false;
}

function onOriginCleared() {
    origin.value = null;
}

function addStop() {
    if (stops.value.length >= 4) return;
    stops.value.push({ address: '', lat: null, lng: null });
}

function selectStop(index, place) {
    stops.value[index] = { address: place.address, lat: place.lat, lng: place.lng };
}

function clearStop(index) {
    stops.value[index] = { ...stops.value[index], lat: null, lng: null };
}

function removeStop(index) {
    stops.value.splice(index, 1);
}

function swapOriginDestination() {
    if (!origin.value || !destination.value) return;
    const previousOrigin = { ...origin.value };
    const previousOriginAddress = originAddress.value;
    origin.value = { lat: destination.value.lat, lng: destination.value.lng };
    originAddress.value = destination.value.address || destinationAddress.value;
    destination.value = { ...previousOrigin, address: previousOriginAddress };
    destinationAddress.value = previousOriginAddress;
    stops.value.reverse();
}

function confirmRoute() {
    if (!canConfirmRoute.value) {
        error.value = hasUnresolvedStop.value
            ? 'Completa cada parada eligiendo una sugerencia.'
            : 'Elige el origen y el destino de la lista de sugerencias.';
        return;
    }
    error.value = null;
    editingRoute.value = false;
}

// Asincrónico (pedido explícito del usuario): busca solo mientras se
// escribe, con debounce, sin botón "Buscar" — mismo patrón que
// components/AddressAutocomplete.vue y Fleet.vue.
let debounceTimer = null;
let searchRequestId = 0;

onBeforeUnmount(() => clearTimeout(debounceTimer));

function onDriverQueryInput() {
    clearTimeout(debounceTimer);

    const term = driverQuery.value.trim();
    if (term.length < 2) {
        driverResults.value = null;
        return;
    }

    debounceTimer = setTimeout(runDriverSearch, 300);
}

async function runDriverSearch() {
    if (!fleetId.value) return;

    const requestId = ++searchRequestId;
    searchingDrivers.value = true;

    try {
        const data = await searchDrivers(fleetId.value, driverQuery.value.trim());
        if (requestId !== searchRequestId) return;
        driverResults.value = data;
    } catch (e) {
        if (requestId !== searchRequestId) return;
        error.value = e.message;
    } finally {
        if (requestId === searchRequestId) searchingDrivers.value = false;
    }
}

function pickDriver(driver) {
    chosenDriver.value = driver;
    chosenCooperative.value = null;
    driverResults.value = null;
    driverQuery.value = '';
}

function clearChosenDriver() {
    chosenDriver.value = null;
}

function pickCooperative(cooperative) {
    chosenCooperative.value = cooperative;
    chosenDriver.value = null;
}

function clearChosenCooperative() {
    chosenCooperative.value = null;
}

function selectProvider(value) {
    provider.value = value;
    if (value === 'driver') chosenCooperative.value = null;
    else chosenDriver.value = null;
}

function selectDriverPool(value) {
    provider.value = 'driver';
    dispatchPool.value = value;
    chosenCooperative.value = null;
    chosenDriver.value = null;
    driverResults.value = null;
    driverQuery.value = '';
}

async function submit() {
    if (!origin.value) {
        error.value = 'Todavía no se detectó tu ubicación de origen.';
        return;
    }
    if (!destination.value) {
        error.value = 'Elegí un destino de la lista de sugerencias.';
        return;
    }
    if (provider.value === 'cooperative' && !chosenCooperative.value) {
        error.value = 'Elegí una cooperativa de la lista.';
        return;
    }

    submitting.value = true;
    error.value = null;

    try {
        const payload = {
            origin_lat: origin.value.lat,
            origin_lng: origin.value.lng,
            origin_address: originAddress.value || null,
            destination_lat: destination.value.lat,
            destination_lng: destination.value.lng,
            destination_address: destination.value.address,
            payment_method: paymentMethod.value,
            passenger_count: passengerCount.value,
            needs_trunk: needsTrunk.value,
            round_trip: roundTrip.value,
            notes: notes.value.trim() || null,
            is_scheduled: isScheduled.value,
            stops: stops.value.map((stop) => ({
                lat: stop.lat,
                lng: stop.lng,
                address: stop.address || null,
            })),
        };

        if (isScheduled.value) {
            payload.scheduled_date = scheduledDate.value;
            payload.scheduled_time = scheduledTime.value;
        }

        if (provider.value === 'cooperative' && chosenCooperative.value) {
            payload.provider_type = 'cooperative';
            payload.cooperative_id = chosenCooperative.value.id;
        } else if (chosenDriver.value) {
            payload.driver_user_id = chosenDriver.value.user_id;
        } else {
            payload.dispatch_pool = dispatchPool.value;
        }

        const rideRequest = await createRideRequest(payload);
        router.push({ name: 'ride-status', params: { id: rideRequest.id } });
    } catch (e) {
        error.value = e.message;
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <MobileShell role="cliente">
        <main class="mobile-page ride-page">
            <header class="page-header"><button class="back-button" @click="router.push({ name: 'home' })" aria-label="Volver">‹</button><div><p class="mobile-eyebrow">Nueva solicitud</p><h1 class="mobile-title">{{ editingRoute ? 'Ajusta tu recorrido' : 'Elige quién te lleva' }}</h1></div></header>

            <p class="request-intro">{{ editingRoute ? 'Cambia el origen, el destino o agrega paradas sin salir de esta pantalla.' : 'Tu ruta ya está lista. Elige un conductor o pide al más cercano.' }}</p>

            <section class="route-map mobile-card" :class="{ ready: hasRoute }"><MobileMap :center="origin" :destination="destination" :stops="stops" :zoom="14" /></section>

            <section v-if="editingRoute" class="route-editor mobile-card">
                <div class="route-field">
                    <label for="origin">Recoger en</label>
                    <AddressAutocomplete id="origin" v-model="originAddress" placeholder="Escribe el punto de partida" @place-selected="onOriginSelected" @clear="onOriginCleared" />
                    <div v-if="locating" class="location-state"><span class="mobile-spinner"></span>Detectando tu ubicación…</div>
                    <div v-else-if="locationError" class="location-state location-error">{{ locationError }} <button type="button" @click="detectLocation">Usar mi ubicación</button></div>
                </div>

                <div v-for="(stop, index) in stops" :key="index" class="route-field stop-field">
                    <label :for="`stop-${index}`">Parada {{ index + 1 }}</label>
                    <div class="stop-row">
                        <AddressAutocomplete :id="`stop-${index}`" v-model="stop.address" placeholder="Escribe la parada" @place-selected="selectStop(index, $event)" @clear="clearStop(index)" />
                        <button type="button" class="remove-stop" :aria-label="`Quitar parada ${index + 1}`" @click="removeStop(index)">×</button>
                    </div>
                </div>

                <div class="route-field">
                    <label for="destination">Destino</label>
                    <AddressAutocomplete id="destination" v-model="destinationAddress" placeholder="Escribe tu destino" @place-selected="onDestinationSelected" @clear="onDestinationCleared" />
                </div>

                <div class="route-editor-actions">
                    <button type="button" class="secondary-route-action" :disabled="stops.length >= 4" @click="addStop">+ Agregar parada</button>
                    <button type="button" class="secondary-route-action" :disabled="!hasRoute" @click="swapOriginDestination">⇅ Invertir ruta</button>
                </div>
                <p v-if="hasUnresolvedStop" class="route-warning">Completa cada parada eligiendo una sugerencia.</p>
                <button type="button" class="mobile-button" :disabled="!canConfirmRoute" @click="confirmRoute">Ver conductores</button>
            </section>

            <section v-else class="route-summary mobile-card">
                <div class="summary-route"><span class="route-dot origin-dot"></span><p><small>RECOGER EN</small><strong>{{ originAddress }}</strong></p><button type="button" @click="editingRoute = true">Cambiar</button></div>
                <template v-for="(stop, index) in stops" :key="`summary-${index}`"><i></i><div class="summary-route"><span class="route-dot stop-dot"></span><p><small>PARADA {{ index + 1 }}</small><strong>{{ stop.address }}</strong></p></div></template>
                <i></i>
                <div class="summary-route"><span class="route-dot destination-dot"></span><p><small>DESTINO</small><strong>{{ destination.address }}</strong></p></div>
            </section>

            <template v-if="routeReady">
            <details class="trip-details mobile-card" :open="isScheduled">
                <summary><span><small>OPCIONES DEL VIAJE</small><strong>{{ passengerCount }} persona{{ passengerCount > 1 ? 's' : '' }} · {{ needsTrunk ? 'Con equipaje' : 'Sin equipaje' }}{{ isScheduled ? ' · Programado' : '' }}</strong></span><b>Configurar</b></summary>
                <section class="trip-options">
                    <div><small>PERSONAS</small><div class="counter"><button type="button" @click="passengerCount=Math.max(1,passengerCount-1)">−</button><strong>{{ passengerCount }}</strong><button type="button" @click="passengerCount=Math.min(8,passengerCount+1)">+</button></div></div>
                    <label class="trunk"><small>EQUIPAJE</small><span><input v-model="needsTrunk" type="checkbox"> Necesito cajuela</span></label>
                    <label class="trunk"><small>REGRESO</small><span><input v-model="roundTrip" type="checkbox"> Ida y vuelta</span></label>
                    <button type="button" class="schedule-toggle" :class="{active:isScheduled}" @click="isScheduled=!isScheduled">▣ {{ isScheduled ? 'Viaje programado' : 'Programar' }}</button>
                    <div v-if="isScheduled" class="schedule-fields"><input v-model="scheduledDate" class="mobile-input" type="date"><input v-model="scheduledTime" class="mobile-input" type="time"></div>
                    <label class="trip-notes"><small>NOTAS PARA EL CONDUCTOR</small><textarea v-model="notes" class="mobile-input" maxlength="500" rows="2" placeholder="Ej. Llevo una maleta, entrar por la puerta lateral"></textarea></label>
                </section>
            </details>

            <section class="option-card mobile-card">
                <div class="section-title"><div><p class="mobile-eyebrow">Quién te recoge</p><h2>{{ provider === 'cooperative' ? 'Elige una cooperativa' : 'Elige un conductor' }}</h2></div><span>Opcional</span></div>

                <div class="provider-toggle">
                    <button type="button" :class="{ active: provider === 'driver' && dispatchPool === 'fleet' }" @click="selectDriverPool('fleet')">Mi flota</button>
                    <button v-if="cooperatives.length" type="button" :class="{ active: provider === 'cooperative' }" @click="selectProvider('cooperative')">Cooperativa</button>
                    <button type="button" :class="{ active: provider === 'driver' && dispatchPool === 'public' }" @click="selectDriverPool('public')">Red pública</button>
                </div>

                <template v-if="provider === 'cooperative'">
                    <button v-if="chosenCooperative" type="button" class="selected-driver" @click="clearChosenCooperative"><span class="driver-avatar">{{ chosenCooperative.name?.charAt(0) }}</span><span><strong>{{ chosenCooperative.name }}</strong><small>Cooperativa seleccionada</small></span><b>Quitar</b></button>
                    <ul v-else class="driver-results">
                        <li v-for="cooperative in cooperatives" :key="cooperative.id">
                            <button type="button" class="pick-driver" @click="pickCooperative(cooperative)">
                                <span class="driver-avatar">{{ cooperative.name?.charAt(0) }}</span>
                                <span><strong>{{ cooperative.name }}</strong><small>{{ cooperative.driver_count }} conductor(es){{ cooperative.distance_km != null ? ` · ${cooperative.distance_km} km` : '' }}</small></span>
                                <b>Elegir</b>
                            </button>
                        </li>
                    </ul>
                </template>

                <template v-else>
                    <button v-if="chosenDriver" type="button" class="selected-driver" @click="clearChosenDriver"><span class="driver-avatar">{{ chosenDriver.name?.charAt(0) }}</span><span><strong>{{ chosenDriver.name }}</strong><small>Conductor seleccionado</small></span><b>Quitar</b></button>
                    <template v-else-if="dispatchPool === 'fleet'"><p class="helper">Si no eliges uno, enviaremos la solicitud a los conductores disponibles de tu flota.</p><button type="button" class="whole-fleet"><span>◎</span><div><strong>Toda mi flota</strong><small>Buscaremos al conductor disponible más cercano</small></div><b>✓</b></button><div class="search-form"><input v-model="driverQuery" class="mobile-input" type="text" placeholder="Buscar por nombre o código" @input="onDriverQueryInput"><span v-if="searchingDrivers" class="inline-loader">Buscando…</span></div><ul v-if="driverResults" class="driver-results"><li v-if="driverResults.length === 0" class="helper">No encontramos conductores.</li><li v-for="driver in driverResults" :key="driver.user_id"><button type="button" class="pick-driver" @click="pickDriver(driver)"><span class="driver-avatar">{{ driver.name?.charAt(0) }}</span><span><strong>{{ driver.name }}</strong><small v-if="driver.member_code">Socio #{{ driver.member_code }}</small></span><b>Elegir</b></button></li></ul></template>
                    <template v-else>
                        <div class="whole-fleet public-pool"><span>⌖</span><div><strong>El más cercano disponible</strong><small>También puedes elegir un conductor público específico.</small></div><b>✓</b></div>
                        <p v-if="publicDriversLoading" class="helper">Buscando conductores cercanos…</p>
                        <ul v-else-if="publicDrivers.length" class="driver-results public-results">
                            <li v-for="driver in publicDrivers.slice(0, 6)" :key="driver.user_id"><button type="button" class="pick-driver" @click="pickDriver(driver)"><span class="driver-avatar">{{ driver.name?.charAt(0) }}</span><span><strong>{{ driver.name }}</strong><small>{{ driver.vehicle_type || 'Conductor verificado' }}<template v-if="driver.average_rating"> · ★ {{ driver.average_rating }}</template></small></span><b>Elegir</b></button></li>
                        </ul>
                        <p v-else class="helper">No hay perfiles públicos para mostrar ahora; igual puedes pedir al más cercano.</p>
                    </template>
                </template>
            </section>

            <section class="option-card mobile-card"><div class="section-title"><div><p class="mobile-eyebrow">Pago</p><h2>Forma de pago</h2></div></div><div class="payment-options"><button type="button" :class="{ active: paymentMethod === 'efectivo' }" @click="paymentMethod = 'efectivo'"><span>$</span><strong>Efectivo</strong></button><button type="button" :class="{ active: paymentMethod === 'transferencia' }" @click="paymentMethod = 'transferencia'"><span>↗</span><strong>Transferencia</strong></button></div></section>

            <p v-if="error" class="mobile-alert">{{ error }}</p>
            <div class="step-actions"><button class="mobile-button" :disabled="!canSubmit || locating || submitting" @click="submit"><span v-if="submitting" class="mobile-spinner"></span>{{ submitting ? 'Buscando conductores…' : isScheduled ? 'Programar carrera' : 'Pedir ahora' }}</button></div>
            </template>
            <p v-else-if="error" class="mobile-alert">{{ error }}</p>
        </main>
    </MobileShell>
</template>

<style scoped>
.ride-page { display: grid; gap: 1rem; }.page-header { display: flex; align-items: center; gap: .8rem; }.back-button { width: 2.55rem; height: 2.55rem; border: 1px solid var(--arka-border); border-radius: .85rem; background:var(--arka-card); color: var(--arka-text); font-size: 1.7rem; }.page-header h1 { margin-top: .2rem; }.stepper{display:grid;grid-template-columns:repeat(3,1fr);gap:.4rem}.stepper span{height:.24rem;border-radius:999px;background:var(--arka-border)}.stepper span.active{background:var(--arka-primary)}
.request-intro{margin:-.35rem 0 0;color:var(--arka-muted);font-size:.8rem;line-height:1.45}.route-map { height: 38dvh; min-height: 16rem; overflow: hidden; }.route-map.ready{height:31dvh;min-height:13.5rem}
:root:not(.dark) .back-button, :root:not(.dark) .payment-options button { background: var(--arka-card); }
.route-summary{padding:.8rem 1rem;display:grid;grid-template-columns:1fr;gap:.2rem}.summary-route{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:.7rem}.summary-route p{min-width:0;margin:0;display:grid;gap:.12rem}.summary-route small{color:var(--arka-primary);font-size:.56rem;font-weight:850;letter-spacing:.1em}.summary-route strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.78rem}.summary-route button{border:0;background:transparent;color:var(--arka-primary);font-size:.68rem;font-weight:800}.route-summary>i{width:1px;height:1rem;margin-left:.34rem;background:var(--arka-border)}.trip-details{overflow:hidden}.trip-details>summary{min-height:3.7rem;padding:.75rem 1rem;display:flex;align-items:center;justify-content:space-between;gap:.8rem;cursor:pointer;list-style:none}.trip-details>summary::-webkit-details-marker{display:none}.trip-details>summary span{min-width:0;display:grid;gap:.18rem}.trip-details>summary small{color:var(--arka-primary);font-size:.56rem;font-weight:850;letter-spacing:.1em}.trip-details>summary strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.72rem}.trip-details>summary b{color:var(--arka-primary);font-size:.68rem}.trip-details[open]>summary{border-bottom:1px solid var(--arka-border)}.trip-options{padding:.9rem;display:grid;grid-template-columns:1fr 1fr;gap:.8rem}.trip-options>div:first-child,.trunk{display:grid;gap:.35rem}.trip-options small{color:var(--arka-muted);font-size:.6rem;font-weight:850;letter-spacing:.1em}.counter{display:flex;align-items:center;gap:.8rem}.counter button{width:2rem;height:2rem;border:1px solid var(--arka-border);border-radius:.65rem;background:var(--arka-field);color:var(--arka-primary);font-size:1.15rem}.trunk span{font-size:.75rem}.trunk input{accent-color:var(--arka-primary)}.schedule-toggle{grid-column:1/-1;min-height:2.7rem;border:1px solid rgba(23,139,98,.3);border-radius:.8rem;background:var(--arka-primary-soft);color:var(--arka-primary);font-weight:750}.schedule-toggle.active{background:var(--arka-primary);color:#fff}.schedule-fields{grid-column:1/-1;display:grid!important;grid-template-columns:1fr 1fr;gap:.55rem}
.route-card { padding: 1rem; display: grid; grid-template-columns: 1rem 1fr; gap: .7rem; }.route-line { padding: 1.75rem 0 1.5rem; display: grid; grid-template-rows: auto 1fr auto; justify-items: center; }.route-line i { width: 1px; min-height: 4rem; background: rgba(147,173,162,.3); }.route-dot { width: .7rem; height: .7rem; border-radius: 50%; }.origin-dot { background: var(--arka-primary); box-shadow: 0 0 0 4px rgba(52,211,153,.12); }.destination-dot { background: var(--arka-danger); transform: rotate(45deg); border-radius: .12rem; }.route-fields { display: grid; gap: .9rem; }.route-field + .route-field { padding-top: .85rem; border-top: 1px solid var(--arka-border); }.route-field label { display: block; margin-bottom: .4rem; color: var(--arka-muted); font-size: .65rem; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; }.location-state { min-height: 3.35rem; display: flex; align-items: center; gap: .55rem; color: var(--arka-muted); font-size: .8rem; }.location-ready { color: var(--arka-text); font-weight: 700; }.location-error { align-items: flex-start; color: #fca5a5; line-height: 1.4; }.location-error button { border: 0; padding: 0; background: transparent; color: var(--arka-primary); font-weight: 800; }
.route-editor{padding:1rem;display:grid;gap:.85rem}.route-editor .route-field+.route-field{padding-top:.75rem}.route-editor-actions{display:grid;grid-template-columns:1fr 1fr;gap:.55rem}.secondary-route-action{min-height:2.7rem;padding:.55rem;border:1px solid var(--arka-border);border-radius:.8rem;background:var(--arka-field);color:var(--arka-text);font-size:.7rem;font-weight:800}.secondary-route-action:disabled{opacity:.45}.stop-row{display:grid;grid-template-columns:minmax(0,1fr) 2.8rem;gap:.45rem;align-items:center}.remove-stop{width:2.8rem;height:2.8rem;border:1px solid color-mix(in srgb,var(--arka-danger) 35%,transparent);border-radius:.8rem;background:color-mix(in srgb,var(--arka-danger) 10%,var(--arka-card));color:var(--arka-danger);font-size:1.25rem}.route-warning{margin:0;color:var(--arka-warning);font-size:.7rem}.stop-dot{background:#f59e0b;box-shadow:0 0 0 4px rgba(245,158,11,.12)}
.provider-toggle{margin-top:.75rem;display:grid;grid-template-columns:1fr 1fr;gap:.5rem}.provider-toggle button{min-height:2.6rem;border:1px solid var(--arka-border);border-radius:.75rem;background:var(--arka-field);color:var(--arka-muted);font-size:.75rem;font-weight:750}.provider-toggle button.active{border-color:color-mix(in srgb,var(--arka-primary) 45%,transparent);background:var(--arka-primary-soft);color:var(--arka-primary)}
.option-card { padding: 1rem; }.section-title { display: flex; align-items: flex-start; justify-content: space-between; }.section-title h2 { margin: .25rem 0 0; font-size: 1rem; }.section-title > span { color: var(--arka-muted); font-size: .68rem; }.helper { margin: .7rem 0; color: var(--arka-muted); font-size: .74rem; line-height: 1.45; }.search-form { position: relative; margin-top:.75rem }.inline-loader { position: absolute; right: .8rem; top: 1.05rem; color: var(--arka-primary); font-size: .68rem; }.driver-results { margin: .55rem 0 0; padding: 0; list-style: none; }.pick-driver, .selected-driver,.whole-fleet { width: 100%; min-height: 3.7rem; padding: .55rem; display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: .65rem; border: 0; border-top: 1px solid var(--arka-border); background: transparent; color: var(--arka-text); text-align: left; }.selected-driver,.whole-fleet { margin-top: .75rem; border: 1px solid color-mix(in srgb,var(--arka-primary) 30%,transparent); border-radius: .9rem; background: var(--arka-primary-soft); }.driver-avatar,.whole-fleet>span { width: 2.25rem; height: 2.25rem; display: grid; place-items: center; border-radius: 50%; background:var(--arka-primary-soft); color: var(--arka-primary); font-weight: 800; }.pick-driver > span:nth-child(2), .selected-driver > span:nth-child(2),.whole-fleet>div { display: grid; gap: .12rem; }.pick-driver strong, .selected-driver strong,.whole-fleet strong { font-size: .82rem; }.pick-driver small, .selected-driver small,.whole-fleet small { color: var(--arka-muted); font-size: .66rem; }.pick-driver b, .selected-driver b,.whole-fleet b { color: var(--arka-primary); font-size: .68rem; }
.payment-options { margin-top: .8rem; display: grid; grid-template-columns: 1fr 1fr; gap: .55rem; }.payment-options button { min-height: 3.5rem; padding: .6rem; display: flex; align-items: center; gap: .55rem; border: 1px solid var(--arka-border); border-radius: .9rem; background:var(--arka-field); color: var(--arka-muted); }.payment-options button > span { width: 1.8rem; height: 1.8rem; display: grid; place-items: center; border-radius: .6rem; background:var(--arka-surface); }.payment-options button strong { font-size: .75rem; }.payment-options button.active { border-color:color-mix(in srgb,var(--arka-primary) 45%,transparent); background: var(--arka-primary-soft); color: var(--arka-primary); }.step-actions{position:sticky;z-index:10;bottom:.65rem;display:grid;gap:.55rem;padding:.55rem;border:1px solid var(--arka-border);border-radius:1rem;background:var(--arka-chrome);box-shadow:var(--arka-shadow)}.step-actions>.mobile-button{grid-column:1/-1}.step-back{min-width:5.5rem;border:1px solid var(--arka-border);border-radius:.85rem;background:var(--arka-card);color:var(--arka-text);font-weight:750}.route-compact{padding:.85rem;display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:.7rem}.route-compact div{min-width:0;display:grid;gap:.18rem}.route-compact small,.confirmation small{color:var(--arka-primary);font-size:.58rem;font-weight:850;letter-spacing:.1em}.route-compact strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.82rem}.route-compact button{border:0;background:transparent;color:var(--arka-primary);font-size:.7rem;font-weight:800}.confirm-map{height:38dvh;min-height:16rem;overflow:hidden}.confirmation{padding:1rem;display:grid;gap:.4rem}.confirmation>div{display:grid;grid-template-columns:auto 1fr;align-items:start;gap:.7rem}.confirmation p{margin:0;display:grid;gap:.18rem}.confirmation strong{font-size:.82rem}.confirmation>i{width:1px;height:1.5rem;margin-left:.33rem;background:var(--arka-border)}.confirmation-details{padding:.3rem 1rem}.confirmation-details>div{min-height:3rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;border-bottom:1px solid var(--arka-border)}.confirmation-details>div:last-child{border:0}.confirmation-details span{color:var(--arka-muted);font-size:.72rem}.confirmation-details strong{font-size:.78rem;text-align:right}
.trip-notes{grid-column:1/-1;display:grid;gap:.35rem}.trip-notes textarea{min-height:4.5rem;resize:vertical}.provider-toggle{grid-template-columns:repeat(3,minmax(0,1fr));gap:.4rem}.provider-toggle button{min-width:0;min-height:2.7rem;padding:.45rem .3rem;font-size:.66rem}.public-pool{border-color:color-mix(in srgb,#60a5fa 35%,transparent);background:color-mix(in srgb,#60a5fa 10%,var(--arka-card))}
</style>
