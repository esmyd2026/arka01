<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { confirmDialog } from '@/Utils/confirmDialog';

const props = defineProps({
    trip: { type: Object, required: true },
    isOwner: { type: Boolean, required: true },
    seatsAvailable: { type: Number, required: true },
    myReservation: { type: Object, default: null },
});

const STATUS_LABEL = { open: 'Abierto a reservas', cancelled: 'Cancelado' };

const form = useForm({ seats_reserved: 1 });

function reserve() {
    form.post(route('van-trip-reservations.store', props.trip.id), { preserveScroll: true });
}

async function cancelReservation() {
    if (!(await confirmDialog('¿Cancelar su reserva?', { danger: true }))) return;
    router.post(route('van-trip-reservations.cancel', props.myReservation.id), {}, { preserveScroll: true });
}

async function cancelTrip() {
    if (!(await confirmDialog('¿Cancelar este viaje? Se les va a avisar a quienes ya reservaron.', { danger: true }))) return;
    router.post(route('van-trips.cancel', props.trip.id), {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`${trip.origin_city.name} → ${trip.destination_city.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <Link :href="isOwner ? route('van-trips.index') : route('van-trips.browse')" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                        &larr; Rutas y Turismo
                    </Link>
                    <h2 class="font-semibold text-xl text-arka-text leading-tight">
                        {{ trip.origin_city.name }} &rarr; {{ trip.destination_city.name }}
                    </h2>
                </div>
                <span class="text-sm text-arka-text-muted">{{ STATUS_LABEL[trip.status] }}</span>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div v-if="trip.photos.length" class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    <img v-for="photo in trip.photos" :key="photo.id" :src="photo.photo_url" alt="" class="h-28 w-full object-cover rounded-arka" />
                </div>

                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-2">
                    <div class="flex items-center gap-2">
                        <UserAvatar :user="trip.driver" size-class="h-8 w-8 text-xs shrink-0" />
                        <Link :href="route('profiles.show', trip.driver.id)" class="text-arka-text hover:text-arka-primary-bright">
                            {{ trip.driver.name }}
                        </Link>
                    </div>
                    <p class="text-sm text-arka-text-muted">{{ trip.travel_date }} · sale {{ trip.departure_time }}</p>
                    <p class="text-arka-text font-medium">${{ trip.price_per_seat }}/asiento · {{ seatsAvailable }} de {{ trip.total_seats }} libres</p>
                    <p v-if="trip.luggage_allowance" class="text-sm text-arka-text-muted">Equipaje: {{ trip.luggage_allowance }}</p>
                    <p v-if="trip.description" class="text-sm text-arka-text-muted pt-2 border-t border-arka-text-muted/10">{{ trip.description }}</p>

                    <div v-if="trip.included_services?.length" class="flex flex-wrap gap-1.5 pt-2">
                        <span v-for="(service, i) in trip.included_services" :key="i" class="px-2 py-0.5 rounded-full text-xs bg-arka-primary/10 text-arka-primary-bright">
                            {{ service }}
                        </span>
                    </div>

                    <DangerButton v-if="isOwner && trip.status === 'open'" class="mt-3" @click="cancelTrip">Cancelar viaje</DangerButton>
                </div>

                <!-- Reservar (lado cliente) -->
                <div v-if="!isOwner && trip.status === 'open'" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <div v-if="myReservation && myReservation.status === 'confirmed'" class="flex items-center justify-between gap-4">
                        <p class="text-arka-text">Reservó {{ myReservation.seats_reserved }} asiento(s).</p>
                        <SecondaryButton @click="cancelReservation">Cancelar reserva</SecondaryButton>
                    </div>
                    <form v-else @submit.prevent="reserve" class="space-y-3">
                        <div>
                            <InputLabel value="Cantidad de asientos" />
                            <TextInput type="number" min="1" :max="seatsAvailable" class="mt-1 block w-32" v-model.number="form.seats_reserved" />
                            <InputError class="mt-1" :message="form.errors.seats_reserved" />
                        </div>
                        <InputError :message="form.errors.trip" />
                        <PrimaryButton :disabled="form.processing || seatsAvailable < 1">
                            {{ seatsAvailable < 1 ? 'Sin cupo' : 'Reservar' }}
                        </PrimaryButton>
                    </form>
                </div>

                <!-- Reservas confirmadas (lado conductor) -->
                <div v-if="isOwner && trip.reservations.length" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-4">Pasajeros que reservaron</h3>
                    <ul class="divide-y divide-arka-text-muted/10">
                        <li v-for="r in trip.reservations" :key="r.id" class="py-2.5 flex items-center justify-between text-sm">
                            <span class="text-arka-text">{{ r.client.name }}</span>
                            <span class="text-arka-text-muted">{{ r.seats_reserved }} asiento(s)</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
