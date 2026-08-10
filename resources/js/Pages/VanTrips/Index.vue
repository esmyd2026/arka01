<script setup>
import { computed, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import FleetMap from '@/Components/FleetMap.vue';
import AddressAutocomplete from '@/Components/AddressAutocomplete.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { distanceKm } from '@/Utils/haversine';
import { fetchOsrmRoute } from '@/Utils/osrmRoute';

const props = defineProps({
    trips: { type: Array, required: true },
    cities: { type: Array, required: true },
    canPublish: { type: Boolean, required: true },
    // Tarifa/km del propio conductor (si ya tiene perfil activado): de acá
    // sale el costo aproximado que se sugiere antes de fijar el precio por
    // asiento — pedido explícito del usuario.
    driverRatePerKm: { type: [Number, String], default: null },
    // Ciudad ya registrada del conductor (pedido explícito del usuario: el
    // mapa arrancaba siempre en Quito) — respaldo si el navegador no da
    // geolocalización a tiempo.
    driverCity: { type: Object, default: null },
});

const cityOptions = computed(() => props.cities.map((c) => ({ value: c.id, label: c.name })));

const STATUS_LABEL = { open: 'Abierto', cancelled: 'Cancelado' };

const showForm = ref(false);

const form = useForm({
    origin_city_id: null,
    origin_lat: null,
    origin_lng: null,
    origin_address: '',
    destination_city_id: null,
    destination_lat: null,
    destination_lng: null,
    destination_address: '',
    travel_date: '',
    departure_time: '07:00',
    total_seats: 12,
    price_per_seat: null,
    description: '',
    included_services: [],
    luggage_allowance: '',
    photos: [],
});

// Centro inicial del mapa (pedido explícito del usuario: "no se posiciona
// donde está el cliente... por lo menos en la ciudad que se encuentra") —
// mismo mecanismo que Express/Index.vue.
const mapCenter = ref(null);

function fallbackToDriverCity() {
    if (props.driverCity) {
        mapCenter.value = { lat: props.driverCity.lat, lng: props.driverCity.lng };
    }
}

if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
        (position) => {
            mapCenter.value = { lat: position.coords.latitude, lng: position.coords.longitude };
        },
        fallbackToDriverCity
    );
} else {
    fallbackToDriverCity();
}

// Punto exacto de salida/llegada (pedido explícito del usuario): opcional,
// además de la ciudad — mismo mecanismo de "marcar en el mapa" que un
// Expreso (ver Express/Index.vue), toggle simple para no necesitar dos mapas.
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
    if (form.origin_lat != null) markers.push({ id: 'origin', lat: form.origin_lat, lng: form.origin_lng, label: 'Salida' });
    if (form.destination_lat != null) markers.push({ id: 'destination', lat: form.destination_lat, lng: form.destination_lng, label: 'Llegada' });
    return markers;
});

// Distancia y costo aproximado, antes de que el conductor ponga su precio
// por asiento (pedido explícito del usuario) — mismo cálculo del lado del
// navegador que ya usa "Pedir carrera", solo para orientar: lo que se guarda
// siempre lo recalcula el backend con los mismos puntos.
const estimatedDistanceKm = computed(() => {
    if (form.origin_lat == null || form.destination_lat == null) return null;
    return distanceKm(form.origin_lat, form.origin_lng, form.destination_lat, form.destination_lng);
});

const estimatedTotalCost = computed(() => {
    if (estimatedDistanceKm.value == null || !props.driverRatePerKm) return null;
    return estimatedDistanceKm.value * Number(props.driverRatePerKm);
});

const estimatedPerSeat = computed(() => {
    if (estimatedTotalCost.value == null || !form.total_seats) return null;
    return estimatedTotalCost.value / form.total_seats;
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

function addService() {
    form.included_services.push('');
}

function removeService(index) {
    form.included_services.splice(index, 1);
}

function onPhotosSelected(event) {
    form.photos = Array.from(event.target.files);
}

const todayDateString = new Date().toISOString().slice(0, 10);

const canSubmit = computed(() =>
    form.origin_city_id && form.destination_city_id && form.travel_date && form.total_seats > 0 && form.price_per_seat > 0
);

function submit() {
    form.post(route('van-trips.store'), {
        forceFormData: true,
        onSuccess: () => {
            form.reset();
            showForm.value = false;
        },
    });
}
</script>

<template>
    <Head title="Mis viajes VAN" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-arka-text leading-tight">Mis viajes VAN / turismo</h2>
                <Link :href="route('van-trips.browse')" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                    Ver como cliente &rarr;
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Publique salidas programadas para su vehículo tipo VAN, buseta, microbús o de turismo — los
                    clientes las exploran y reservan por asiento.
                </p>

                <p v-if="!canPublish" class="p-4 bg-arka-warning/10 text-arka-warning text-sm rounded-arka">
                    Este módulo es exclusivo de un plan superior — active su perfil de conductor y mejore su plan para
                    poder publicar viajes.
                    <Link :href="route('driver.plan.edit')" class="underline hover:text-arka-warning/80">Ver planes</Link>
                </p>

                <ul v-if="trips.length" class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <li v-for="trip in trips" :key="trip.id" class="p-4 sm:p-6">
                        <Link :href="route('van-trips.show', trip.id)" class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-arka-text font-medium hover:text-arka-primary-bright">
                                    {{ trip.origin_city.name }} &rarr; {{ trip.destination_city.name }}
                                </p>
                                <p class="text-sm text-arka-text-muted">
                                    {{ trip.travel_date }} · {{ trip.departure_time }} ·
                                    {{ STATUS_LABEL[trip.status] }} ·
                                    {{ trip.reserved_seats_count ?? 0 }}/{{ trip.total_seats }} asientos reservados
                                </p>
                            </div>
                            <span class="text-sm text-arka-text-muted shrink-0">${{ trip.price_per_seat }}/asiento</span>
                        </Link>
                    </li>
                </ul>
                <p v-else class="text-sm text-arka-text-muted">Todavía no publicaste ningún viaje.</p>

                <div v-if="canPublish" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <div v-if="!showForm">
                        <PrimaryButton @click="showForm = true">Publicar viaje</PrimaryButton>
                    </div>

                    <form v-else @submit.prevent="submit" class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel value="Ciudad de origen" />
                                <SearchableSelect class="mt-1" v-model="form.origin_city_id" :options="cityOptions" />
                                <InputError class="mt-1" :message="form.errors.origin_city_id" />
                            </div>
                            <div>
                                <InputLabel value="Ciudad de destino" />
                                <SearchableSelect class="mt-1" v-model="form.destination_city_id" :options="cityOptions" />
                                <InputError class="mt-1" :message="form.errors.destination_city_id" />
                            </div>
                        </div>

                        <!-- Punto exacto de salida/llegada (pedido explícito del
                             usuario), opcional: sirve para calcular la distancia
                             real y sugerir un costo aproximado más abajo. -->
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <InputLabel value="Punto exacto de salida y llegada (opcional)" />
                                <div class="flex gap-2 text-xs">
                                    <button
                                        type="button"
                                        class="px-2 py-1 rounded-arka"
                                        :class="pickingPoint === 'origin' ? 'bg-arka-primary text-arka-base' : 'bg-arka-base text-arka-text-muted'"
                                        @click="pickingPoint = 'origin'"
                                    >
                                        Marcar salida
                                    </button>
                                    <button
                                        type="button"
                                        class="px-2 py-1 rounded-arka"
                                        :class="pickingPoint === 'destination' ? 'bg-arka-primary text-arka-base' : 'bg-arka-base text-arka-text-muted'"
                                        @click="pickingPoint = 'destination'"
                                    >
                                        Marcar llegada
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

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel value="Fecha" />
                                <TextInput type="date" class="mt-1 block w-full" :min="todayDateString" v-model="form.travel_date" required />
                                <InputError class="mt-1" :message="form.errors.travel_date" />
                            </div>
                            <div>
                                <InputLabel value="Hora de salida" />
                                <TextInput type="time" class="mt-1 block w-full" v-model="form.departure_time" required />
                            </div>
                        </div>

                        <div>
                            <InputLabel value="Cantidad de asientos disponibles" />
                            <TextInput type="number" min="1" max="60" class="mt-1 block w-32" v-model.number="form.total_seats" required />
                            <InputError class="mt-1" :message="form.errors.total_seats" />
                        </div>

                        <!-- Costo aproximado (pedido explícito del usuario: "indicarle
                             los km y un costo aproximado, antes de que coloque su
                             precio") — solo aparece si marcó los dos puntos en el mapa
                             de arriba; si todavía no tiene tarifa/km configurada en su
                             perfil, se muestra igual la distancia, sin el monto. -->
                        <div v-if="estimatedDistanceKm != null" class="p-4 rounded-arka border border-arka-text-muted/20 space-y-1">
                            <p class="text-sm text-arka-text">
                                Distancia aproximada entre los puntos marcados: <strong>{{ estimatedDistanceKm.toFixed(1) }} km</strong>
                            </p>
                            <p v-if="estimatedTotalCost != null" class="text-sm text-arka-text-muted">
                                A su tarifa (${{ Number(driverRatePerKm).toFixed(2) }}/km): costo aproximado del viaje completo
                                <strong class="text-arka-text">${{ estimatedTotalCost.toFixed(2) }}</strong>
                                <span v-if="estimatedPerSeat != null"> — con {{ form.total_seats }} asiento(s), ~${{ estimatedPerSeat.toFixed(2) }}/asiento.</span>
                            </p>
                            <p v-else class="text-xs text-arka-text-muted">
                                Active su perfil de conductor con una tarifa por km para ver acá un costo aproximado sugerido.
                            </p>
                        </div>

                        <div>
                            <InputLabel value="Precio por asiento (USD)" />
                            <TextInput type="number" step="0.01" min="0.01" class="mt-1 block w-full sm:w-64" v-model="form.price_per_seat" required />
                            <InputError class="mt-1" :message="form.errors.price_per_seat" />
                        </div>

                        <div>
                            <InputLabel value="Descripción del viaje (opcional)" />
                            <textarea
                                v-model="form.description"
                                rows="3"
                                class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text"
                                placeholder="Ej: salida turística al feriado, paradas en el camino, punto de encuentro..."
                            ></textarea>
                        </div>

                        <div>
                            <InputLabel value="Equipaje permitido (opcional)" />
                            <TextInput class="mt-1 block w-full" v-model="form.luggage_allowance" placeholder="Ej: 1 maleta grande por pasajero" />
                        </div>

                        <div>
                            <InputLabel value="Servicios incluidos (opcional)" />
                            <div v-for="(service, index) in form.included_services" :key="index" class="flex gap-2 mt-2">
                                <TextInput class="block w-full" v-model="form.included_services[index]" placeholder="Ej: Aire acondicionado" />
                                <SecondaryButton type="button" @click="removeService(index)">Quitar</SecondaryButton>
                            </div>
                            <button type="button" class="mt-2 text-sm text-arka-primary hover:text-arka-primary-bright" @click="addService">
                                + Agregar servicio
                            </button>
                        </div>

                        <div>
                            <InputLabel value="Fotos del vehículo (opcional, hasta 6)" />
                            <input
                                type="file"
                                accept="image/*"
                                multiple
                                class="mt-1 block w-full text-sm text-arka-text-muted file:mr-3 file:py-1.5 file:px-3 file:rounded-arka file:border-0 file:bg-arka-primary file:text-arka-base"
                                @change="onPhotosSelected"
                            />
                            <InputError class="mt-1" :message="form.errors.photos" />
                        </div>

                        <div class="flex gap-2">
                            <PrimaryButton :disabled="!canSubmit || form.processing">Publicar</PrimaryButton>
                            <SecondaryButton type="button" @click="showForm = false">Cancelar</SecondaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
