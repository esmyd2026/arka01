<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { confirmDialog } from '@/Utils/confirmDialog';

// La prop se llama "expressRoute" (no "route") a propósito: route() es el
// helper global de Ziggy para generar URLs, y una prop igual lo taparía
// dentro de este componente.
const props = defineProps({
    expressRoute: { type: Object, required: true },
    isOwner: { type: Boolean, required: true },
    myApplication: { type: Object, default: null },
    myCompanionRequest: { type: Object, default: null },
});

const COMPANION_STATUS_LABELS = {
    pending: 'Pendiente de respuesta',
    accepted: 'Aceptado',
    rejected: 'Rechazado',
    left: 'Se bajó',
};

function acceptCompanion(companionId) {
    router.post(route('express-companions.accept', companionId), {}, { preserveScroll: true });
}

function rejectCompanion(companionId) {
    router.post(route('express-companions.reject', companionId), {}, { preserveScroll: true });
}

async function leaveShared() {
    if (!(await confirmDialog('¿Bajarte de este Expreso compartido?', { danger: true }))) return;
    router.post(route('express-companions.leave', props.myCompanionRequest.id), {}, { preserveScroll: true });
}

const STATUS_LABELS = {
    open: 'Abierto a postulaciones',
    active: 'Activo',
    paused: 'Pausado',
    cancelled: 'Cancelado',
};

const DAY_LABELS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

function acceptApplication(applicationId) {
    router.post(route('express-applications.accept', applicationId), {}, { preserveScroll: true });
}

function rejectApplication(applicationId) {
    router.post(route('express-applications.reject', applicationId), {}, { preserveScroll: true });
}

async function withdrawApplication(applicationId) {
    if (!(await confirmDialog('¿Retirar su postulación?', { danger: true }))) return;
    router.post(route('express-applications.withdraw', applicationId), {}, { preserveScroll: true });
}

function pause() {
    router.post(route('express-routes.pause', props.expressRoute.id), {}, { preserveScroll: true });
}

function resume() {
    router.post(route('express-routes.resume', props.expressRoute.id), {}, { preserveScroll: true });
}

async function cancelRoute() {
    if (!(await confirmDialog('¿Cancelar este Expreso? Ya no se van a generar más carreras.', { danger: true }))) return;
    router.post(route('express-routes.cancel', props.expressRoute.id), {}, { preserveScroll: true });
}

// Reporte de incumplimiento (sección 4.3): se abre sobre una carrera puntual del historial.
const reportingRideId = ref(null);
const incidentConditionId = ref('');
const incidentDescription = ref('');

function openIncidentForm(rideId) {
    reportingRideId.value = rideId;
    incidentConditionId.value = '';
    incidentDescription.value = '';
}

function submitIncident() {
    router.post(
        route('express-incidents.store', props.expressRoute.id),
        {
            ride_id: reportingRideId.value,
            express_condition_id: incidentConditionId.value || null,
            description: incidentDescription.value,
        },
        { preserveScroll: true, onSuccess: () => (reportingRideId.value = null) }
    );
}
</script>

<template>
    <Head :title="expressRoute.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <Link
                        :href="isOwner ? route('express-routes.index') : route('express-routes.available')"
                        class="text-sm text-arka-primary hover:text-arka-primary-bright"
                    >
                        &larr; Expresos
                    </Link>
                    <h2 class="font-semibold text-xl text-arka-text leading-tight">{{ expressRoute.name }}</h2>
                </div>
                <span class="text-sm text-arka-text-muted">{{ STATUS_LABELS[expressRoute.status] }}</span>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Resumen -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-2">
                    <p class="text-sm text-arka-text-muted">
                        {{ expressRoute.origin_address || 'Origen sin referencia' }} &rarr;
                        {{ expressRoute.destination_address || 'Destino sin referencia' }}
                    </p>
                    <p class="text-sm text-arka-text-muted">
                        {{ expressRoute.days_of_week.map((d) => DAY_LABELS[d]).join(', ') }} · sale {{ expressRoute.departure_time }}
                    </p>
                    <p class="text-arka-text font-medium">${{ expressRoute.offered_price }}/carrera</p>

                    <!-- Compartido (pedido explícito del usuario): el precio total
                         no cambia, se reparte entre el dueño y sus acompañantes. -->
                    <p v-if="expressRoute.share_enabled" class="text-sm text-arka-primary-bright">
                        Abierto a compartir · ${{ expressRoute.price_per_person }}/persona con
                        {{ expressRoute.companions.filter((c) => c.status === 'accepted').length }} acompañante(s) aceptado(s)
                        (cupo {{ expressRoute.max_companions ?? 1 }})
                    </p>

                    <div v-if="expressRoute.assigned_driver" class="pt-2 text-sm text-arka-text">
                        Conductor asignado:
                        <Link :href="route('profiles.show', expressRoute.assigned_driver.id)" class="text-arka-primary hover:text-arka-primary-bright">
                            {{ expressRoute.assigned_driver.name }}
                        </Link>
                    </div>

                    <div v-if="isOwner" class="pt-3 flex gap-2">
                        <SecondaryButton v-if="expressRoute.status === 'active'" @click="pause">Pausar</SecondaryButton>
                        <PrimaryButton v-if="expressRoute.status === 'paused'" @click="resume">Reanudar</PrimaryButton>
                        <DangerButton v-if="expressRoute.status !== 'cancelled'" @click="cancelRoute">Cancelar Expreso</DangerButton>
                    </div>
                </div>

                <!-- Condiciones pactadas -->
                <div v-if="expressRoute.conditions.length" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-2">Condiciones pactadas</h3>
                    <ul class="list-disc list-inside text-sm text-arka-text-muted">
                        <li v-for="c in expressRoute.conditions" :key="c.id">{{ c.description }}</li>
                    </ul>
                </div>

                <!-- Mi postulación (lado conductor) -->
                <div v-if="myApplication" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka flex items-center justify-between">
                    <div>
                        <p class="text-arka-text">Su postulación: <span class="font-medium">{{ myApplication.status }}</span></p>
                        <p v-if="myApplication.proposed_price" class="text-sm text-arka-text-muted">
                            Propuso ${{ myApplication.proposed_price }}
                        </p>
                    </div>
                    <SecondaryButton v-if="myApplication.status === 'pending'" @click="withdrawApplication(myApplication.id)">
                        Retirar
                    </SecondaryButton>
                </div>

                <!-- Mi pedido para compartir (lado acompañante, no dueño) -->
                <div v-if="myCompanionRequest" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka flex items-center justify-between gap-4">
                    <div>
                        <p class="text-arka-text">
                            Su pedido para compartir: <span class="font-medium">{{ COMPANION_STATUS_LABELS[myCompanionRequest.status] }}</span>
                        </p>
                        <p v-if="myCompanionRequest.status === 'accepted'" class="text-sm text-arka-text-muted">
                            Le toca pagar ${{ expressRoute.price_per_person }} por carrera.
                        </p>
                    </div>
                    <SecondaryButton v-if="['pending', 'accepted'].includes(myCompanionRequest.status)" @click="leaveShared">
                        Bajarme
                    </SecondaryButton>
                </div>

                <!-- Pedidos para compartir, pendientes de revisar (lado dueño) -->
                <div
                    v-if="isOwner && expressRoute.share_enabled && expressRoute.companions.some((c) => c.status === 'pending')"
                    class="p-4 sm:p-6 bg-arka-card shadow rounded-arka"
                >
                    <h3 class="text-lg font-medium text-arka-text mb-4">Pedidos para compartir su Expreso</h3>
                    <ul class="divide-y divide-arka-text-muted/10">
                        <li
                            v-for="c in expressRoute.companions.filter((c) => c.status === 'pending')"
                            :key="c.id"
                            class="py-3 flex items-center justify-between gap-4"
                        >
                            <p class="text-arka-text font-medium">{{ c.passenger.name }}</p>
                            <div class="flex gap-2 shrink-0">
                                <PrimaryButton @click="acceptCompanion(c.id)">Aceptar</PrimaryButton>
                                <SecondaryButton @click="rejectCompanion(c.id)">Rechazar</SecondaryButton>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Acompañantes ya aceptados (lado dueño) -->
                <div
                    v-if="isOwner && expressRoute.companions.some((c) => c.status === 'accepted')"
                    class="p-4 sm:p-6 bg-arka-card shadow rounded-arka"
                >
                    <h3 class="text-lg font-medium text-arka-text mb-2">Acompañantes sumados</h3>
                    <ul class="divide-y divide-arka-text-muted/10">
                        <li v-for="c in expressRoute.companions.filter((c) => c.status === 'accepted')" :key="c.id" class="py-2 text-sm text-arka-text">
                            {{ c.passenger.name }}
                        </li>
                    </ul>
                </div>

                <!-- Postulaciones pendientes (lado cliente) -->
                <div
                    v-if="isOwner && expressRoute.applications.some((a) => a.status === 'pending')"
                    class="p-4 sm:p-6 bg-arka-card shadow rounded-arka"
                >
                    <h3 class="text-lg font-medium text-arka-text mb-4">Postulaciones pendientes</h3>
                    <ul class="divide-y divide-arka-text-muted/10">
                        <li
                            v-for="a in expressRoute.applications.filter((a) => a.status === 'pending')"
                            :key="a.id"
                            class="py-3 flex items-center justify-between gap-4"
                        >
                            <div>
                                <p class="text-arka-text font-medium">{{ a.driver.name }}</p>
                                <p v-if="a.proposed_price" class="text-sm text-arka-text-muted">Propone ${{ a.proposed_price }}</p>
                                <p v-else class="text-sm text-arka-text-muted">Acepta el precio ofrecido</p>
                            </div>
                            <div class="flex gap-2 shrink-0">
                                <PrimaryButton @click="acceptApplication(a.id)">Aceptar</PrimaryButton>
                                <SecondaryButton @click="rejectApplication(a.id)">Rechazar</SecondaryButton>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Historial de carreras generadas -->
                <div v-if="expressRoute.ride_requests.length" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-4">Carreras generadas</h3>
                    <ul class="divide-y divide-arka-text-muted/10">
                        <li v-for="rr in expressRoute.ride_requests" :key="rr.id" class="py-3">
                            <div class="flex items-center justify-between gap-4">
                                <span class="text-sm text-arka-text-muted">{{ new Date(rr.requested_at).toLocaleDateString() }}</span>
                                <span class="text-sm text-arka-text">{{ rr.status }}</span>
                                <SecondaryButton
                                    v-if="isOwner && rr.ride && rr.ride.status === 'completed'"
                                    @click="openIncidentForm(rr.ride.id)"
                                >
                                    Reportar incumplimiento
                                </SecondaryButton>
                            </div>

                            <form v-if="reportingRideId === rr.ride?.id" @submit.prevent="submitIncident" class="mt-3 space-y-2">
                                <select
                                    v-if="expressRoute.conditions.length"
                                    v-model="incidentConditionId"
                                    class="block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text text-sm"
                                >
                                    <option value="">Queja general (no ligada a una condición puntual)</option>
                                    <option v-for="c in expressRoute.conditions" :key="c.id" :value="c.id">{{ c.description }}</option>
                                </select>
                                <TextInput class="block w-full" v-model="incidentDescription" placeholder="Qué pasó" required />
                                <div class="flex gap-2">
                                    <PrimaryButton>Reportar</PrimaryButton>
                                    <SecondaryButton type="button" @click="reportingRideId = null">Cancelar</SecondaryButton>
                                </div>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
