<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    trips: { type: Array, required: true },
    cities: { type: Array, required: true },
    canPublish: { type: Boolean, required: true },
});

const cityOptions = computed(() => props.cities.map((c) => ({ value: c.id, label: c.name })));

const STATUS_LABEL = { open: 'Abierto', cancelled: 'Cancelado' };

const showForm = ref(false);

const form = useForm({
    origin_city_id: null,
    destination_city_id: null,
    travel_date: '',
    departure_time: '07:00',
    total_seats: 12,
    price_per_seat: null,
    description: '',
    included_services: [],
    luggage_allowance: '',
    photos: [],
});

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

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel value="Cantidad de asientos disponibles" />
                                <TextInput type="number" min="1" max="60" class="mt-1 block w-full" v-model.number="form.total_seats" required />
                                <InputError class="mt-1" :message="form.errors.total_seats" />
                            </div>
                            <div>
                                <InputLabel value="Precio por asiento (USD)" />
                                <TextInput type="number" step="0.01" min="0.01" class="mt-1 block w-full" v-model="form.price_per_seat" required />
                                <InputError class="mt-1" :message="form.errors.price_per_seat" />
                            </div>
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
