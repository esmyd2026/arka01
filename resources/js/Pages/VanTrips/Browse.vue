<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    trips: { type: Array, required: true },
    // Pedido explícito del usuario: si la búsqueda no encontró nada, se
    // guarda para proponérsela a los conductores (ver VanTripController::
    // browse()) y se muestran estos viajes de respaldo en su lugar.
    fallbackTrips: { type: Array, default: () => [] },
    searchSaved: { type: Boolean, default: false },
    cities: { type: Array, required: true },
    filters: { type: Object, required: true },
});

const cityOptions = computed(() => props.cities.map((c) => ({ value: c.id, label: c.name })));

const originCityId = ref(props.filters.origin_city_id ? Number(props.filters.origin_city_id) : null);
const destinationCityId = ref(props.filters.destination_city_id ? Number(props.filters.destination_city_id) : null);
const travelDate = ref(props.filters.travel_date ?? '');

function search() {
    router.get(route('van-trips.browse'), {
        origin_city_id: originCityId.value,
        destination_city_id: destinationCityId.value,
        travel_date: travelDate.value || null,
    }, { preserveState: true });
}
</script>

<template>
    <Head title="Rutas y Turismo" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-arka-text leading-tight">Rutas y Turismo</h2>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Salidas programadas de conductores con vehículo tipo VAN, buseta, microbús o de turismo —
                    reserve su asiento con precio fijo, sin negociar.
                </p>

                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <InputLabel value="Origen" />
                            <SearchableSelect class="mt-1" v-model="originCityId" :options="cityOptions" empty-label="Cualquiera" />
                        </div>
                        <div>
                            <InputLabel value="Destino" />
                            <SearchableSelect class="mt-1" v-model="destinationCityId" :options="cityOptions" empty-label="Cualquiera" />
                        </div>
                        <div>
                            <InputLabel value="Fecha" />
                            <TextInput type="date" class="mt-1 block w-full" v-model="travelDate" />
                        </div>
                    </div>
                    <PrimaryButton @click="search">Buscar</PrimaryButton>
                </div>

                <div v-if="!trips.length" class="space-y-4">
                    <p class="text-sm text-arka-text-muted">
                        No hay viajes disponibles con esos filtros por ahora.
                    </p>
                    <!-- Pedido explícito del usuario: avisarle que su búsqueda quedó
                         guardada para proponérsela a los conductores (ver
                         VanTripController::browse() y VanTrips/Index.vue). -->
                    <p v-if="searchSaved" class="text-sm text-arka-primary-bright">
                        📋 Guardamos su búsqueda — se la vamos a mostrar a los conductores que publican viajes, por
                        si arman uno para esa ruta.
                    </p>

                    <div v-if="fallbackTrips.length">
                        <p class="text-sm text-arka-text-muted font-medium mb-2">Mientras tanto, estos viajes ya están disponibles:</p>
                        <ul class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                            <li v-for="trip in fallbackTrips" :key="trip.id" class="p-4 sm:p-6">
                                <Link :href="route('van-trips.show', trip.id)" class="flex items-center gap-4">
                                    <img
                                        v-if="trip.photos.length"
                                        :src="trip.photos[0].photo_url"
                                        alt=""
                                        class="h-16 w-24 object-cover rounded-arka shrink-0"
                                    />
                                    <div class="flex-1 min-w-0">
                                        <p class="text-arka-text font-medium hover:text-arka-primary-bright flex items-center gap-2 flex-wrap">
                                            {{ trip.origin_city.name }} &rarr; {{ trip.destination_city.name }}
                                            <span v-if="trip.is_own_fleet" class="px-1.5 py-0.5 rounded-full text-[10px] bg-arka-primary/15 text-arka-primary-bright">
                                                De su flota
                                            </span>
                                        </p>
                                        <p class="text-sm text-arka-text-muted">
                                            {{ trip.travel_date }} · {{ trip.departure_time }} · {{ trip.driver.name }} ·
                                            {{ trip.total_seats - (trip.reserved_seats_count ?? 0) }} asiento(s) libre(s)
                                        </p>
                                    </div>
                                    <span class="text-sm text-arka-text-muted shrink-0">${{ trip.price_per_seat }}/asiento</span>
                                </Link>
                            </li>
                        </ul>
                    </div>
                </div>

                <ul v-else class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <li v-for="trip in trips" :key="trip.id" class="p-4 sm:p-6">
                        <Link :href="route('van-trips.show', trip.id)" class="flex items-center gap-4">
                            <img
                                v-if="trip.photos.length"
                                :src="trip.photos[0].photo_url"
                                alt=""
                                class="h-16 w-24 object-cover rounded-arka shrink-0"
                            />
                            <div class="flex-1 min-w-0">
                                <p class="text-arka-text font-medium hover:text-arka-primary-bright">
                                    {{ trip.origin_city.name }} &rarr; {{ trip.destination_city.name }}
                                </p>
                                <p class="text-sm text-arka-text-muted">
                                    {{ trip.travel_date }} · {{ trip.departure_time }} · {{ trip.driver.name }} ·
                                    {{ trip.total_seats - (trip.reserved_seats_count ?? 0) }} asiento(s) libre(s)
                                </p>
                            </div>
                            <span class="text-sm text-arka-text-muted shrink-0">${{ trip.price_per_seat }}/asiento</span>
                        </Link>
                    </li>
                </ul>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
