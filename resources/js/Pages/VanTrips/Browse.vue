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
    <Head title="Viajes VAN / turismo" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-arka-text leading-tight">Viajes VAN / turismo</h2>
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

                <p v-if="!trips.length" class="text-sm text-arka-text-muted">
                    No hay viajes disponibles con esos filtros por ahora.
                </p>

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
