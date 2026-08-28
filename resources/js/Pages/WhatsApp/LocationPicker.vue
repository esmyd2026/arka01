<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import LeafletFleetMap from '@/Components/LeafletFleetMap.vue';

const props = defineProps({
    step: { type: String, required: true }, // 'origin' | 'destination'
    valid: { type: Boolean, required: true },
    center: { type: Object, default: null },
    submitted: { type: Boolean, required: true },
});

const title = props.step === 'origin' ? '¿Desde dónde le recogemos?' : '¿A dónde vamos?';
const defaultCenter = { lat: -0.1807, lng: -78.4678 }; // Quito, mismo criterio que LeafletFleetMap

const query = ref('');
const searching = ref(false);
const results = ref([]);
const point = ref(null); // { lat, lng, address }
const sending = ref(false);
const errorMessage = ref('');
let searchTimer = null;

const mapCenter = computed(() => point.value ?? props.center ?? defaultCenter);
// id: 'origin'/'destination' (no un id genérico) reusa a propósito la lógica
// de LeafletFleetMap que desactiva el clic sobre el propio pin en mapas
// clickeables — si no, tocar encima del pin para reubicarlo un poco no
// llegaría nunca a pickFromMap().
const markers = computed(() => (point.value ? [{ id: props.step, lat: point.value.lat, lng: point.value.lng, label: point.value.address, color: '#34d399' }] : []));

// Geocodificación gratis, sin API key (OpenStreetMap Nominatim — mismo
// criterio que el resto de la app, ver Ride/Request.vue): funciona igual sin
// depender de que VITE_GOOGLE_MAPS_API_KEY esté configurada, algo que este
// enlace de WhatsApp no puede darse el lujo de necesitar.
async function reverseGeocode(lat, lng) {
    try {
        const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`);
        const data = await response.json();
        return data?.display_name ?? null;
    } catch {
        return null;
    }
}

function onQueryInput() {
    window.clearTimeout(searchTimer);
    const value = query.value.trim();
    if (value.length < 3) {
        results.value = [];
        return;
    }
    searchTimer = window.setTimeout(() => runSearch(value), 400);
}

async function runSearch(value) {
    searching.value = true;
    try {
        const params = new URLSearchParams({ format: 'jsonv2', q: value, limit: '5' });
        const response = await fetch(`https://nominatim.openstreetmap.org/search?${params}`);
        results.value = await response.json();
    } catch {
        results.value = [];
    } finally {
        searching.value = false;
    }
}

function pickResult(result) {
    point.value = { lat: Number(result.lat), lng: Number(result.lon), address: result.display_name };
    results.value = [];
    query.value = '';
}

async function pickFromMap({ lat, lng }) {
    point.value = { lat, lng, address: 'Ubicación seleccionada en el mapa' };
    const address = await reverseGeocode(lat, lng);
    if (address && point.value?.lat === lat && point.value?.lng === lng) {
        point.value.address = address;
    }
}

function useCurrentLocation() {
    if (!navigator.geolocation) {
        errorMessage.value = 'Este navegador no permite compartir su ubicación actual.';
        return;
    }
    navigator.geolocation.getCurrentPosition(
        (position) => pickFromMap({ lat: position.coords.latitude, lng: position.coords.longitude }),
        () => { errorMessage.value = 'No se pudo obtener su ubicación actual. Búsquela o toque el mapa.'; },
    );
}

function send() {
    if (!point.value || sending.value) return;
    sending.value = true;
    errorMessage.value = '';
    router.post(window.location.pathname + window.location.search, {
        lat: point.value.lat,
        lng: point.value.lng,
        address: point.value.address,
    }, {
        preserveScroll: true,
        onError: (errors) => { errorMessage.value = Object.values(errors)[0] || 'No se pudo enviar la ubicación.'; },
        onFinish: () => { sending.value = false; },
    });
}
</script>

<template>
    <Head :title="title" />

    <main class="arka-app-background flex min-h-screen items-center justify-center px-4 py-6">
        <section class="w-full max-w-lg rounded-arka border border-arka-border bg-arka-card p-6 text-center shadow-2xl">
            <div class="mb-5 inline-flex"><ApplicationLogo size="h-9" /></div>

            <template v-if="submitted">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-arka-primary">Ubicación enviada</p>
                <h1 class="mt-2 text-xl font-bold text-arka-text">¡Listo!</h1>
                <p class="mt-3 text-sm text-arka-text-muted">Ya recibimos el punto en Arka01. Vuelva a WhatsApp para continuar con su carrera.</p>
            </template>

            <template v-else-if="!valid">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-arka-warning">Enlace vencido</p>
                <h1 class="mt-2 text-xl font-bold text-arka-text">Este enlace ya no está activo</h1>
                <p class="mt-3 text-sm text-arka-text-muted">Vuelva a WhatsApp y escriba "pedir carrera" para empezar de nuevo.</p>
            </template>

            <template v-else>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-arka-primary">Arka01 · WhatsApp</p>
                <h1 class="mt-2 text-xl font-bold text-arka-text">{{ title }}</h1>
                <p class="mt-2 text-sm text-arka-text-muted">Busque la dirección, use su ubicación actual, o toque el mapa para marcarla.</p>

                <div class="mt-4 flex gap-2">
                    <input
                        v-model="query"
                        type="text"
                        placeholder="Buscar dirección, sector, lugar..."
                        class="flex-1 rounded-arka border border-arka-border bg-arka-surface px-3 py-2 text-sm text-arka-text placeholder:text-arka-text-muted"
                        @input="onQueryInput"
                    >
                    <button type="button" class="shrink-0 rounded-arka border border-arka-primary/40 px-3 py-2 text-xs font-semibold text-arka-primary" @click="useCurrentLocation">Mi ubicación</button>
                </div>

                <ul v-if="results.length" class="mt-2 max-h-40 overflow-y-auto rounded-arka border border-arka-border bg-arka-surface text-left">
                    <li v-for="result in results" :key="result.place_id">
                        <button type="button" class="w-full px-3 py-2 text-left text-xs text-arka-text hover:bg-arka-base" @click="pickResult(result)">{{ result.display_name }}</button>
                    </li>
                </ul>
                <p v-if="searching" class="mt-2 text-xs text-arka-text-muted">Buscando...</p>

                <div class="mt-4 overflow-hidden rounded-arka">
                    <LeafletFleetMap :center="mapCenter" :markers="markers" :clickable="true" height="280px" @map-click="pickFromMap" />
                </div>

                <div v-if="point" class="mt-4 rounded-arka bg-arka-surface p-3 text-left text-sm text-arka-text">
                    📍 {{ point.address }}
                </div>

                <p v-if="errorMessage" class="mt-3 text-sm text-arka-danger">{{ errorMessage }}</p>

                <button
                    type="button"
                    class="mt-5 w-full rounded-arka bg-arka-primary px-4 py-3 text-sm font-semibold text-arka-card disabled:opacity-50"
                    :disabled="!point || sending"
                    @click="send"
                >
                    {{ sending ? 'Enviando...' : 'Enviar esta ubicación' }}
                </button>
            </template>
        </section>
    </main>
</template>
