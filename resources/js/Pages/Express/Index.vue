<script setup>
import { computed, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import FleetMap from '@/Components/FleetMap.vue';
import AddressAutocomplete from '@/Components/AddressAutocomplete.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { fetchOsrmRoute } from '@/Utils/osrmRoute';
import { distanceKm } from '@/Utils/haversine';

const props = defineProps({
    routes: { type: Array, required: true },
    // Tarifa de referencia (promedio de sus conductores) y tarifa mínima de
    // la plataforma — de acá sale el costo aproximado que se sugiere antes
    // de fijar el precio por carrera (pedido explícito del usuario).
    referenceRatePerKm: { type: [Number, String], default: 0 },
    minimumFare: { type: [Number, String], default: 0 },
    // Pedido explícito del usuario: el precio no tiene que calzar con el
    // estimado, pero tampoco puede irse por debajo de esta fracción — mismo
    // número que ya aplica el backend en store()/update().
    minimumPriceFactor: { type: [Number, String], default: 0.5 },
    // Ciudad ya registrada del cliente (pedido explícito del usuario: el mapa
    // arrancaba siempre en Quito) — respaldo si el navegador no da
    // geolocalización a tiempo.
    clientCity: { type: Object, default: null },
});

const DAYS = [
    { value: 1, label: 'Lun' },
    { value: 2, label: 'Mar' },
    { value: 3, label: 'Mié' },
    { value: 4, label: 'Jue' },
    { value: 5, label: 'Vie' },
    { value: 6, label: 'Sáb' },
    { value: 0, label: 'Dom' },
];

const STATUS_LABELS = {
    open: 'Abierto a postulaciones',
    active: 'Activo',
    paused: 'Pausado',
    cancelled: 'Cancelado',
};

const showCreateForm = ref(false);

const form = useForm({
    name: '',
    origin_lat: null,
    origin_lng: null,
    origin_address: '',
    destination_lat: null,
    destination_lng: null,
    destination_address: '',
    days_of_week: [],
    departure_time: '07:00',
    // Ida y vuelta (pedido explícito del usuario): además de la hora de
    // salida DEL origen, la hora de salida DEL destino para la vuelta.
    is_round_trip: false,
    return_time: '17:00',
    offered_price: null,
    conditions: [],
    // Pedido explícito del usuario: que otras personas con ruta parecida se
    // puedan sumar y repartir el costo, para que al conductor sí le convenga.
    share_enabled: false,
    max_companions: 1,
});

// Centro inicial del mapa (pedido explícito del usuario: "no se posiciona
// donde está el cliente... por lo menos en la ciudad que se encuentra"):
// arranca en la ubicación real si el navegador la da, si no en la ciudad que
// ya tiene registrada — sin esto, FleetMap.vue cae en su propio default fijo
// (Quito), sin importar dónde esté el cliente de verdad.
const mapCenter = ref(null);

function fallbackToClientCity() {
    if (props.clientCity) {
        mapCenter.value = { lat: props.clientCity.lat, lng: props.clientCity.lng };
    }
}

if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
        (position) => {
            mapCenter.value = { lat: position.coords.latitude, lng: position.coords.longitude };
        },
        fallbackToClientCity
    );
} else {
    fallbackToClientCity();
}

// Toggle simple: "marcando origen" o "marcando destino" en el mismo mapa,
// para no necesitar dos mapas separados.
const pickingPoint = ref('origin');

function pickPoint({ lat, lng }) {
    if (pickingPoint.value === 'origin') {
        form.origin_lat = lat;
        form.origin_lng = lng;
    } else {
        form.destination_lat = lat;
        form.destination_lng = lng;
    }
}

// Mismo efecto que marcar en el mapa, solo que el punto viene resuelto por
// una sugerencia de Google Places (decisión explícita del usuario: Google
// solo para autocompletar direcciones, ver Components/AddressAutocomplete.vue).
function pickOriginFromAddress({ lat, lng }) {
    form.origin_lat = lat;
    form.origin_lng = lng;
}

function pickDestinationFromAddress({ lat, lng }) {
    form.destination_lat = lat;
    form.destination_lng = lng;
}

const mapMarkers = computed(() => {
    const markers = [];
    if (form.origin_lat != null) markers.push({ id: 'origin', lat: form.origin_lat, lng: form.origin_lng, label: 'Origen' });
    if (form.destination_lat != null) markers.push({ id: 'destination', lat: form.destination_lat, lng: form.destination_lng, label: 'Destino' });
    return markers;
});

// Trazado real del recorrido entre los dos puntos marcados (bug reportado
// por el usuario: no se trazaba nada acá) — mismo mecanismo que "Pedir
// carrera", ver Utils/osrmRoute.js.
const routeCoords = ref([]);

watch(
    () => [form.origin_lat, form.origin_lng, form.destination_lat, form.destination_lng],
    async ([originLat, originLng, destLat, destLng]) => {
        if (originLat == null || destLat == null) {
            routeCoords.value = [];
            return;
        }

        routeCoords.value = await fetchOsrmRoute(originLat, originLng, destLat, destLng);
    }
);

function addCondition() {
    form.conditions.push('');
}

function removeCondition(index) {
    form.conditions.splice(index, 1);
}

// Costo estimado (pedido explícito del usuario: "falta el costo estimado...
// y el precio que coloque que no sea menor al estimado") — mismo cálculo que
// "Pedir carrera": distancia × tarifa de referencia, con la tarifa mínima de
// la plataforma como piso. Lo que realmente se termina exigiendo lo valida
// el backend con este mismo cálculo (PriceCalculator), esto es solo para
// orientar antes de mandar el formulario.
const estimatedDistanceKm = computed(() => {
    if (form.origin_lat == null || form.destination_lat == null) return null;
    return distanceKm(form.origin_lat, form.origin_lng, form.destination_lat, form.destination_lng);
});

const rawEstimatedPrice = computed(() => {
    if (estimatedDistanceKm.value == null) return null;
    return estimatedDistanceKm.value * Number(props.referenceRatePerKm || 0);
});

const isMinimumFareApplied = computed(() => rawEstimatedPrice.value != null && rawEstimatedPrice.value < Number(props.minimumFare));

const estimatedPrice = computed(() => {
    if (rawEstimatedPrice.value == null) return null;
    return Math.max(rawEstimatedPrice.value, Number(props.minimumFare));
});

// Pedido explícito del usuario: no tiene que calzar con el estimado, pero
// tampoco puede irse por debajo de esta fracción de ese estimado.
const minimumAllowedPrice = computed(() => {
    if (estimatedPrice.value == null) return null;
    return Math.round(estimatedPrice.value * Number(props.minimumPriceFactor) * 100) / 100;
});

// Ida y vuelta (pedido explícito del usuario: "debería duplicar el valor... y
// debemos indicarle el desglose"): "Precio por carrera" sigue siendo por
// trayecto — se cobra ese monto en cada una de las dos carreras que se
// generan por día (confirmado con el usuario) — esto es solo para que quede
// claro cuánto suma el día completo antes de fijar el precio.
const roundTripTotalEstimate = computed(() => {
    if (!form.is_round_trip || estimatedPrice.value == null) return null;
    return estimatedPrice.value * 2;
});

const canSubmit = computed(() =>
    form.origin_lat != null
    && form.destination_lat != null
    && form.days_of_week.length > 0
    && (!form.is_round_trip || form.return_time)
    && (minimumAllowedPrice.value == null || !form.offered_price || Number(form.offered_price) >= minimumAllowedPrice.value)
);

function submit() {
    form.post(route('express-routes.store'), {
        onSuccess: () => {
            form.reset();
            showCreateForm.value = false;
        },
    });
}
</script>

<template>
    <Head title="Mis Expresos" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between flex-wrap gap-2">
                <h2 class="font-semibold text-xl text-arka-text leading-tight">Mis Expresos</h2>
                <div class="flex items-center gap-3 text-sm">
                    <Link :href="route('express-companions.discover')" class="text-arka-primary hover:text-arka-primary-bright">
                        Buscar Expresos para compartir &rarr;
                    </Link>
                    <Link :href="route('express-routes.available')" class="text-arka-primary hover:text-arka-primary-bright">
                        Postularme a uno &rarr;
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Una ruta fija y recurrente para gente con horario de entrada/salida fijo (sección 4): la publicás
                    una sola vez, un conductor se postula, y el sistema genera la solicitud de carrera automáticamente
                    cada día que corresponda.
                </p>

                <!-- Pedido explícito del usuario ("no sé a quién le aparece un
                     Expreso"): quién lo ve del otro lado no es obvio a
                     simple vista — aclararlo acá evita el caso real que
                     pasó (probar con conductores que nunca lo iban a ver
                     porque no eran miembros de ninguna flota tuya). -->
                <p class="text-xs text-arka-text-muted bg-arka-base/60 p-3 rounded-arka">
                    ℹ️ Solo lo van a ver los conductores que ya son <strong>miembros activos de alguna de sus
                    flotas</strong> — no es un aviso a todos los conductores de la plataforma. Si el conductor del que
                    espera postulaciones todavía no aceptó su invitación, vaya a "Mi Flota" primero.
                </p>

                <ul v-if="routes.length" class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <li v-for="r in routes" :key="r.id" class="p-4 sm:p-6">
                        <Link :href="route('express-routes.show', r.id)" class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-arka-text font-medium hover:text-arka-primary-bright">{{ r.name }}</p>
                                <p class="text-sm text-arka-text-muted">
                                    {{ STATUS_LABELS[r.status] }}
                                    <span v-if="r.assigned_driver"> · {{ r.assigned_driver.name }}</span>
                                    <span v-if="r.pending_applications_count"> · {{ r.pending_applications_count }} postulación(es) nueva(s) </span>
                                    <span v-if="r.share_enabled" class="text-arka-primary-bright"> · abierto a compartir</span>
                                </p>
                            </div>
                            <span class="text-sm text-arka-text-muted shrink-0">${{ r.offered_price }}/carrera</span>
                        </Link>
                    </li>
                </ul>
                <p v-else class="text-sm text-arka-text-muted">Todavía no publicaste ningún Expreso.</p>

                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <div v-if="!showCreateForm">
                        <PrimaryButton @click="showCreateForm = true">Publicar Expreso</PrimaryButton>
                    </div>

                    <form v-else @submit.prevent="submit" class="space-y-4">
                        <div>
                            <InputLabel value="Nombre (para identificarlo entre varios)" />
                            <TextInput class="mt-1 block w-full" v-model="form.name" placeholder="Ej: Turno mañana - Oficina Norte" required />
                            <InputError class="mt-1" :message="form.errors.name" />
                        </div>

                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <InputLabel value="Origen y destino" />
                                <div class="flex gap-2 text-xs">
                                    <button
                                        type="button"
                                        class="px-2 py-1 rounded-arka"
                                        :class="pickingPoint === 'origin' ? 'bg-arka-primary text-arka-base' : 'bg-arka-base text-arka-text-muted'"
                                        @click="pickingPoint = 'origin'"
                                    >
                                        Marcar origen
                                    </button>
                                    <button
                                        type="button"
                                        class="px-2 py-1 rounded-arka"
                                        :class="pickingPoint === 'destination' ? 'bg-arka-primary text-arka-base' : 'bg-arka-base text-arka-text-muted'"
                                        @click="pickingPoint = 'destination'"
                                    >
                                        Marcar destino
                                    </button>
                                </div>
                            </div>
                            <FleetMap
                                :markers="mapMarkers"
                                :center="mapCenter ?? undefined"
                                :route="routeCoords"
                                :clickable="true"
                                @map-click="pickPoint"
                            />
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel value="Referencia del origen (opcional)" />
                                <AddressAutocomplete
                                    class="mt-1"
                                    v-model="form.origin_address"
                                    @place-selected="pickOriginFromAddress"
                                />
                            </div>
                            <div>
                                <InputLabel value="Referencia del destino (opcional)" />
                                <AddressAutocomplete
                                    class="mt-1"
                                    v-model="form.destination_address"
                                    @place-selected="pickDestinationFromAddress"
                                />
                            </div>
                        </div>

                        <div>
                            <InputLabel value="Días de la semana" />
                            <div class="mt-2 flex flex-wrap gap-2">
                                <label
                                    v-for="day in DAYS"
                                    :key="day.value"
                                    class="px-3 py-1.5 rounded-arka border cursor-pointer text-sm"
                                    :class="form.days_of_week.includes(day.value) ? 'border-arka-primary bg-arka-primary/10 text-arka-text' : 'border-arka-text-muted/20 text-arka-text-muted'"
                                >
                                    <input type="checkbox" :value="day.value" v-model="form.days_of_week" class="hidden" />
                                    {{ day.label }}
                                </label>
                            </div>
                            <InputError class="mt-1" :message="form.errors.days_of_week" />
                        </div>

                        <!-- Ida y vuelta (pedido explícito del usuario): con esto
                             marcado, "Hora de salida" pasa a ser la del origen y
                             aparece una segunda hora para la vuelta desde el
                             destino — se genera una carrera para cada una. -->
                        <label class="flex items-center">
                            <Checkbox v-model:checked="form.is_round_trip" />
                            <span class="ms-2 text-sm text-arka-text">Es de ida y vuelta (vuelve el mismo día)</span>
                        </label>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel :value="form.is_round_trip ? 'Hora de salida (desde el origen)' : 'Hora de salida'" />
                                <TextInput type="time" class="mt-1 block w-full" v-model="form.departure_time" required />
                            </div>
                            <div v-if="form.is_round_trip">
                                <InputLabel value="Hora de vuelta (desde el destino)" />
                                <TextInput type="time" class="mt-1 block w-full" v-model="form.return_time" required />
                                <InputError class="mt-1" :message="form.errors.return_time" />
                            </div>
                        </div>

                        <!-- Costo estimado (pedido explícito del usuario), antes de
                             fijar el precio: distancia × su tarifa de referencia. Con
                             ida y vuelta, desglose de cuánto suma el día completo
                             (pedido explícito del usuario: "debemos indicarle el
                             desglose del precio") — "Precio por carrera" sigue siendo
                             por trayecto, se cobra en cada una de las dos carreras. El
                             precio no tiene que calzar con el estimado (pedido
                             explícito del usuario), pero no puede irse por debajo de
                             la mitad — ver `minimumAllowedPrice`. -->
                        <div v-if="estimatedPrice != null" class="p-4 rounded-arka border border-arka-text-muted/20 space-y-1">
                            <div class="flex items-center justify-between text-sm text-arka-text-muted">
                                <span v-if="isMinimumFareApplied">Tarifa mínima de la plataforma</span>
                                <span v-else>{{ estimatedDistanceKm.toFixed(1) }} km × ${{ Number(referenceRatePerKm).toFixed(2) }}/km</span>
                                <span class="text-arka-text font-medium">${{ estimatedPrice.toFixed(2) }} (estimado por trayecto)</span>
                            </div>
                            <div v-if="roundTripTotalEstimate != null" class="flex items-center justify-between text-sm text-arka-text-muted border-t border-arka-text-muted/10 pt-1">
                                <span>Ida y vuelta: ${{ estimatedPrice.toFixed(2) }} × 2 carreras/día</span>
                                <span class="text-arka-text font-medium">${{ roundTripTotalEstimate.toFixed(2) }}/día</span>
                            </div>
                            <p class="text-xs text-arka-text-muted">
                                El precio por carrera que fije puede ser menor al estimado por trayecto, pero no menos de
                                ${{ minimumAllowedPrice.toFixed(2) }} (mitad del estimado) — se cobra ese monto en cada
                                carrera<span v-if="form.is_round_trip">, ida y vuelta por separado</span>.
                            </p>
                        </div>

                        <div>
                            <InputLabel value="Precio por carrera (USD)" />
                            <TextInput
                                type="number"
                                step="0.01"
                                :min="minimumAllowedPrice ?? 0.01"
                                class="mt-1 block w-full sm:w-64"
                                v-model="form.offered_price"
                                required
                            />
                            <p
                                v-if="minimumAllowedPrice != null && form.offered_price && Number(form.offered_price) < minimumAllowedPrice"
                                class="mt-1 text-xs text-arka-danger"
                            >
                                No puede ser menor a ${{ minimumAllowedPrice.toFixed(2) }} (mitad del precio estimado).
                            </p>
                            <InputError class="mt-1" :message="form.errors.offered_price" />
                        </div>

                        <!-- Pedido explícito del usuario: hoy un conductor no le
                             conviene hacer un Expreso porque va vacío por una sola
                             persona — abrirlo a compartir reparte el costo entre más
                             gente con ruta parecida. -->
                        <div class="p-3 rounded-arka border border-arka-text-muted/20 space-y-2">
                            <label class="flex items-center">
                                <Checkbox v-model:checked="form.share_enabled" />
                                <span class="ms-2 text-sm text-arka-text">
                                    Abrir este Expreso a que otras personas de ruta parecida se sumen
                                </span>
                            </label>
                            <p class="text-xs text-arka-text-muted">
                                El precio total pactado con el conductor se reparte entre usted y quienes se sumen —
                                a más gente, menos le toca pagar a cada uno.
                            </p>
                            <div v-if="form.share_enabled">
                                <InputLabel value="Cupo de acompañantes (además de usted)" />
                                <TextInput type="number" min="1" max="6" class="mt-1 block w-32" v-model.number="form.max_companions" />
                            </div>
                        </div>

                        <div>
                            <InputLabel value="Condiciones pactadas (opcional)" />
                            <div v-for="(condition, index) in form.conditions" :key="index" class="flex gap-2 mt-2">
                                <TextInput class="block w-full" v-model="form.conditions[index]" placeholder="Ej: Aire acondicionado" />
                                <SecondaryButton type="button" @click="removeCondition(index)">Quitar</SecondaryButton>
                            </div>
                            <button type="button" class="mt-2 text-sm text-arka-primary hover:text-arka-primary-bright" @click="addCondition">
                                + Agregar condición
                            </button>
                        </div>

                        <div class="flex gap-2">
                            <PrimaryButton :disabled="!canSubmit || form.processing">Publicar</PrimaryButton>
                            <SecondaryButton type="button" @click="showCreateForm = false">Cancelar</SecondaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
