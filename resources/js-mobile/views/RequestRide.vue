<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Geolocation } from '@capacitor/geolocation';
import { fetchFleet, searchDrivers } from '../services/fleet';
import { createRideRequest } from '../services/rides';
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

const fleetId = ref(null);
const destinationAddress = ref('');
const destination = ref(null); // { lat, lng, address }
const paymentMethod = ref('efectivo');
const passengerCount = ref(1);
const needsTrunk = ref(false);
const isScheduled = ref(false);
const scheduledDate = ref('');
const scheduledTime = ref('');

const driverQuery = ref('');
const driverResults = ref(null);
const searchingDrivers = ref(false);
const chosenDriver = ref(null); // { user_id, name } o null = toda la flota

const submitting = ref(false);
const error = ref(null);
const currentStep = ref(1);
const stepTitles = ['Ruta y detalles', 'Conductor y pago', 'Confirmar carrera'];
const canContinue = computed(() => currentStep.value !== 1 || Boolean(origin.value && destination.value));

function nextStep() {
    error.value = null;
    if (currentStep.value === 1 && !origin.value) { error.value = 'Todavía no se detectó tu ubicación de origen.'; return; }
    if (currentStep.value === 1 && !destination.value) { error.value = 'Elige un destino de la lista de sugerencias.'; return; }
    currentStep.value = Math.min(3, currentStep.value + 1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function backStep() {
    if (currentStep.value === 1) router.push({ name: 'home' });
    else { currentStep.value -= 1; window.scrollTo({ top: 0, behavior: 'smooth' }); }
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
    } catch (e) {
        locationError.value = 'No se pudo detectar tu ubicación. Revisá el GPS e intentá de nuevo.';
    } finally {
        locating.value = false;
    }
}

onMounted(async () => {
    isScheduled.value = route.query.programar === '1';
    detectLocation();
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
});

function onDestinationSelected(place) {
    destination.value = { lat: place.lat, lng: place.lng, address: place.address };
}

function onDestinationCleared() {
    destination.value = null;
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
    driverResults.value = null;
    driverQuery.value = '';
}

function clearChosenDriver() {
    chosenDriver.value = null;
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

    submitting.value = true;
    error.value = null;

    try {
        const payload = {
            origin_lat: origin.value.lat,
            origin_lng: origin.value.lng,
            destination_lat: destination.value.lat,
            destination_lng: destination.value.lng,
            destination_address: destination.value.address,
            payment_method: paymentMethod.value,
            passenger_count: passengerCount.value,
            needs_trunk: needsTrunk.value,
            is_scheduled: isScheduled.value,
        };

        if (isScheduled.value) {
            payload.scheduled_date = scheduledDate.value;
            payload.scheduled_time = scheduledTime.value;
        }

        if (chosenDriver.value) {
            payload.driver_user_id = chosenDriver.value.user_id;
        } else {
            payload.dispatch_pool = 'fleet';
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
            <header class="page-header"><button class="back-button" @click="backStep">‹</button><div><p class="mobile-eyebrow">Nueva solicitud · Paso {{ currentStep }} de 3</p><h1 class="mobile-title">{{ stepTitles[currentStep - 1] }}</h1></div></header>

            <div class="stepper" aria-label="Progreso de la solicitud"><span v-for="step in 3" :key="step" :class="{ active: step <= currentStep }"></span></div>

            <template v-if="currentStep === 1">
                <section class="route-map mobile-card"><MobileMap :center="origin" :destination="destination" :zoom="14" /></section>

                <section class="route-card mobile-card">
                    <div class="route-line"><span class="route-dot origin-dot"></span><i></i><span class="route-dot destination-dot"></span></div>
                    <div class="route-fields">
                        <div class="route-field"><label>Recoger en</label><div v-if="locating" class="location-state"><span class="mobile-spinner"></span>Detectando tu ubicación…</div><div v-else-if="locationError" class="location-state location-error">{{ locationError }} <button type="button" @click="detectLocation">Reintentar</button></div><div v-else class="location-state location-ready">Tu ubicación actual</div></div>
                        <div class="route-field"><label for="destination">Destino</label><AddressAutocomplete id="destination" v-model="destinationAddress" placeholder="Escribe tu destino" @place-selected="onDestinationSelected" @clear="onDestinationCleared" /></div>
                    </div>
                </section>

                <section class="trip-options mobile-card">
                    <div><small>PERSONAS</small><div class="counter"><button type="button" @click="passengerCount=Math.max(1,passengerCount-1)">−</button><strong>{{ passengerCount }}</strong><button type="button" @click="passengerCount=Math.min(8,passengerCount+1)">+</button></div></div>
                    <label class="trunk"><small>EQUIPAJE</small><span><input v-model="needsTrunk" type="checkbox"> Necesito cajuela</span></label>
                    <button type="button" class="schedule-toggle" :class="{active:isScheduled}" @click="isScheduled=!isScheduled">▣ {{ isScheduled ? 'Viaje programado' : 'Programar' }}</button>
                    <div v-if="isScheduled" class="schedule-fields"><input v-model="scheduledDate" class="mobile-input" type="date"><input v-model="scheduledTime" class="mobile-input" type="time"></div>
                </section>
            </template>

            <template v-else-if="currentStep === 2">
                <section class="route-compact mobile-card"><span class="route-dot origin-dot"></span><div><small>RECORRIDO</small><strong>{{ destination?.address || destinationAddress }}</strong></div><button @click="currentStep = 1">Cambiar</button></section>
                <section class="option-card mobile-card">
                    <div class="section-title"><div><p class="mobile-eyebrow">Quién te recoge</p><h2>Elige un conductor</h2></div><span>Opcional</span></div>
                    <button v-if="chosenDriver" type="button" class="selected-driver" @click="clearChosenDriver"><span class="driver-avatar">{{ chosenDriver.name?.charAt(0) }}</span><span><strong>{{ chosenDriver.name }}</strong><small>Conductor seleccionado</small></span><b>Quitar</b></button>
                    <template v-else><p class="helper">Si no eliges uno, enviaremos la solicitud a los conductores disponibles de tu flota.</p><button type="button" class="whole-fleet"><span>◎</span><div><strong>Toda mi flota</strong><small>Buscaremos al conductor disponible más cercano</small></div><b>✓</b></button><div class="search-form"><input v-model="driverQuery" class="mobile-input" type="text" placeholder="Buscar por nombre o código" @input="onDriverQueryInput"><span v-if="searchingDrivers" class="inline-loader">Buscando…</span></div><ul v-if="driverResults" class="driver-results"><li v-if="driverResults.length === 0" class="helper">No encontramos conductores.</li><li v-for="driver in driverResults" :key="driver.user_id"><button type="button" class="pick-driver" @click="pickDriver(driver)"><span class="driver-avatar">{{ driver.name?.charAt(0) }}</span><span><strong>{{ driver.name }}</strong><small v-if="driver.member_code">Socio #{{ driver.member_code }}</small></span><b>Elegir</b></button></li></ul></template>
                </section>

                <section class="option-card mobile-card"><div class="section-title"><div><p class="mobile-eyebrow">Pago</p><h2>Forma de pago</h2></div></div><div class="payment-options"><button type="button" :class="{ active: paymentMethod === 'efectivo' }" @click="paymentMethod = 'efectivo'"><span>$</span><strong>Efectivo</strong></button><button type="button" :class="{ active: paymentMethod === 'transferencia' }" @click="paymentMethod = 'transferencia'"><span>↗</span><strong>Transferencia</strong></button></div></section>
            </template>

            <template v-else>
                <section class="confirm-map mobile-card"><MobileMap :center="origin" :destination="destination" :zoom="14" /></section>
                <section class="confirmation mobile-card"><div><span class="route-dot origin-dot"></span><p><small>RECOGER EN</small><strong>Tu ubicación actual</strong></p></div><i></i><div><span class="route-dot destination-dot"></span><p><small>DESTINO</small><strong>{{ destination?.address || destinationAddress }}</strong></p></div></section>
                <section class="confirmation-details mobile-card"><div><span>Conductor</span><strong>{{ chosenDriver?.name || 'El primero disponible de tu flota' }}</strong></div><div><span>Forma de pago</span><strong>{{ paymentMethod === 'efectivo' ? 'Efectivo' : 'Transferencia' }}</strong></div><div><span>Personas</span><strong>{{ passengerCount }}</strong></div><div><span>Equipaje</span><strong>{{ needsTrunk ? 'Con cajuela' : 'Sin cajuela' }}</strong></div><div v-if="isScheduled"><span>Salida</span><strong>{{ scheduledDate }} · {{ scheduledTime }}</strong></div></section>
            </template>

            <p v-if="error" class="mobile-alert">{{ error }}</p>
            <div class="step-actions"><button v-if="currentStep > 1" class="step-back" @click="backStep">Atrás</button><button v-if="currentStep < 3" class="mobile-button" :disabled="!canContinue || locating" @click="nextStep">Continuar →</button><button v-else class="mobile-button" :disabled="submitting" @click="submit"><span v-if="submitting" class="mobile-spinner"></span>{{ submitting ? 'Buscando conductores…' : 'Confirmar y pedir carrera' }}</button></div>
        </main>
    </MobileShell>
</template>

<style scoped>
.ride-page { display: grid; gap: 1rem; }.page-header { display: flex; align-items: center; gap: .8rem; }.back-button { width: 2.55rem; height: 2.55rem; border: 1px solid var(--arka-border); border-radius: .85rem; background:var(--arka-card); color: var(--arka-text); font-size: 1.7rem; }.page-header h1 { margin-top: .2rem; }.stepper{display:grid;grid-template-columns:repeat(3,1fr);gap:.4rem}.stepper span{height:.24rem;border-radius:999px;background:var(--arka-border)}.stepper span.active{background:var(--arka-primary)}
.route-map { height: 34dvh; min-height: 14rem; overflow: hidden; }
:root:not(.dark) .back-button, :root:not(.dark) .payment-options button { background: var(--arka-card); }
.trip-options{padding:.9rem;display:grid;grid-template-columns:1fr 1fr;gap:.8rem}.trip-options>div:first-child,.trunk{display:grid;gap:.35rem}.trip-options small{color:var(--arka-muted);font-size:.6rem;font-weight:850;letter-spacing:.1em}.counter{display:flex;align-items:center;gap:.8rem}.counter button{width:2rem;height:2rem;border:1px solid var(--arka-border);border-radius:.65rem;background:var(--arka-field);color:var(--arka-primary);font-size:1.15rem}.trunk span{font-size:.75rem}.trunk input{accent-color:var(--arka-primary)}.schedule-toggle{grid-column:1/-1;min-height:2.7rem;border:1px solid rgba(23,139,98,.3);border-radius:.8rem;background:var(--arka-primary-soft);color:var(--arka-primary);font-weight:750}.schedule-toggle.active{background:var(--arka-primary);color:#fff}.schedule-fields{grid-column:1/-1;display:grid!important;grid-template-columns:1fr 1fr;gap:.55rem}
.route-card { padding: 1rem; display: grid; grid-template-columns: 1rem 1fr; gap: .7rem; }.route-line { padding: 1.75rem 0 1.5rem; display: grid; grid-template-rows: auto 1fr auto; justify-items: center; }.route-line i { width: 1px; min-height: 4rem; background: rgba(147,173,162,.3); }.route-dot { width: .7rem; height: .7rem; border-radius: 50%; }.origin-dot { background: var(--arka-primary); box-shadow: 0 0 0 4px rgba(52,211,153,.12); }.destination-dot { background: var(--arka-danger); transform: rotate(45deg); border-radius: .12rem; }.route-fields { display: grid; gap: .9rem; }.route-field + .route-field { padding-top: .85rem; border-top: 1px solid var(--arka-border); }.route-field label { display: block; margin-bottom: .4rem; color: var(--arka-muted); font-size: .65rem; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; }.location-state { min-height: 3.35rem; display: flex; align-items: center; gap: .55rem; color: var(--arka-muted); font-size: .8rem; }.location-ready { color: var(--arka-text); font-weight: 700; }.location-error { align-items: flex-start; color: #fca5a5; line-height: 1.4; }.location-error button { border: 0; padding: 0; background: transparent; color: var(--arka-primary); font-weight: 800; }
.option-card { padding: 1rem; }.section-title { display: flex; align-items: flex-start; justify-content: space-between; }.section-title h2 { margin: .25rem 0 0; font-size: 1rem; }.section-title > span { color: var(--arka-muted); font-size: .68rem; }.helper { margin: .7rem 0; color: var(--arka-muted); font-size: .74rem; line-height: 1.45; }.search-form { position: relative; margin-top:.75rem }.inline-loader { position: absolute; right: .8rem; top: 1.05rem; color: var(--arka-primary); font-size: .68rem; }.driver-results { margin: .55rem 0 0; padding: 0; list-style: none; }.pick-driver, .selected-driver,.whole-fleet { width: 100%; min-height: 3.7rem; padding: .55rem; display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: .65rem; border: 0; border-top: 1px solid var(--arka-border); background: transparent; color: var(--arka-text); text-align: left; }.selected-driver,.whole-fleet { margin-top: .75rem; border: 1px solid color-mix(in srgb,var(--arka-primary) 30%,transparent); border-radius: .9rem; background: var(--arka-primary-soft); }.driver-avatar,.whole-fleet>span { width: 2.25rem; height: 2.25rem; display: grid; place-items: center; border-radius: 50%; background:var(--arka-primary-soft); color: var(--arka-primary); font-weight: 800; }.pick-driver > span:nth-child(2), .selected-driver > span:nth-child(2),.whole-fleet>div { display: grid; gap: .12rem; }.pick-driver strong, .selected-driver strong,.whole-fleet strong { font-size: .82rem; }.pick-driver small, .selected-driver small,.whole-fleet small { color: var(--arka-muted); font-size: .66rem; }.pick-driver b, .selected-driver b,.whole-fleet b { color: var(--arka-primary); font-size: .68rem; }
.payment-options { margin-top: .8rem; display: grid; grid-template-columns: 1fr 1fr; gap: .55rem; }.payment-options button { min-height: 3.5rem; padding: .6rem; display: flex; align-items: center; gap: .55rem; border: 1px solid var(--arka-border); border-radius: .9rem; background:var(--arka-field); color: var(--arka-muted); }.payment-options button > span { width: 1.8rem; height: 1.8rem; display: grid; place-items: center; border-radius: .6rem; background:var(--arka-surface); }.payment-options button strong { font-size: .75rem; }.payment-options button.active { border-color:color-mix(in srgb,var(--arka-primary) 45%,transparent); background: var(--arka-primary-soft); color: var(--arka-primary); }.step-actions{position:sticky;z-index:10;bottom:calc(4.8rem + env(safe-area-inset-bottom));display:grid;grid-template-columns:auto 1fr;gap:.55rem;padding:.55rem;border:1px solid var(--arka-border);border-radius:1rem;background:var(--arka-chrome);box-shadow:var(--arka-shadow)}.step-actions>.mobile-button:only-child{grid-column:1/-1}.step-back{min-width:5.5rem;border:1px solid var(--arka-border);border-radius:.85rem;background:var(--arka-card);color:var(--arka-text);font-weight:750}.route-compact{padding:.85rem;display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:.7rem}.route-compact div{min-width:0;display:grid;gap:.18rem}.route-compact small,.confirmation small{color:var(--arka-primary);font-size:.58rem;font-weight:850;letter-spacing:.1em}.route-compact strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.82rem}.route-compact button{border:0;background:transparent;color:var(--arka-primary);font-size:.7rem;font-weight:800}.confirm-map{height:38dvh;min-height:16rem;overflow:hidden}.confirmation{padding:1rem;display:grid;gap:.4rem}.confirmation>div{display:grid;grid-template-columns:auto 1fr;align-items:start;gap:.7rem}.confirmation p{margin:0;display:grid;gap:.18rem}.confirmation strong{font-size:.82rem}.confirmation>i{width:1px;height:1.5rem;margin-left:.33rem;background:var(--arka-border)}.confirmation-details{padding:.3rem 1rem}.confirmation-details>div{min-height:3rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;border-bottom:1px solid var(--arka-border)}.confirmation-details>div:last-child{border:0}.confirmation-details span{color:var(--arka-muted);font-size:.72rem}.confirmation-details strong{font-size:.78rem;text-align:right}
</style>
