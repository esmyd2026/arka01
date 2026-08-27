<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    ride: { type: Object, required: true },
    reviews: { type: Array, required: true },
});

// Mismo criterio de etiquetas/colores que Admin/Rides.vue, para que se lea
// igual en las dos pantallas.
const STATUS_LABEL = {
    scheduled: 'Programada',
    in_progress: 'En curso',
    completed: 'Completada',
    cancelled: 'Cancelada',
};
const STATUS_CLASS = {
    scheduled: 'text-arka-lime',
    in_progress: 'text-arka-primary-bright',
    completed: 'text-arka-text-muted',
    cancelled: 'text-arka-danger',
};

const STOP_STATUS_LABEL = {
    pending: 'Pendiente',
    completed: 'Completada',
    cancelled: 'Cancelada',
};

const PAYMENT_METHOD_LABEL = {
    cash: 'Efectivo',
    card: 'Tarjeta',
    transfer: 'Transferencia',
};

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('es-EC', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function money(value) {
    return `$${Number(value ?? 0).toFixed(2)}`;
}

// Línea de tiempo de la carrera: solo se muestran los pasos que de verdad
// ocurrieron (una cancelada nunca tuvo "recogido", por ejemplo).
const timeline = [
    { label: 'Solicitada', value: props.ride.requested_at },
    { label: 'Creada (aceptada)', value: props.ride.created_at },
    { label: 'Conductor en camino', value: props.ride.heading_to_passenger_at },
    { label: 'Conductor llegó', value: props.ride.arrived_at },
    { label: 'Iniciada', value: props.ride.started_at },
    { label: 'Pasajero recogido', value: props.ride.picked_up_at },
    { label: 'Completada', value: props.ride.completed_at },
    { label: 'Cancelada', value: props.ride.cancelled_at },
].filter((step) => step.value);
</script>

<template>
    <Head :title="`Admin · Carrera #${ride.id}`" />

    <AdminLayout :title="`Carrera #${ride.id}`">
        <div class="py-12">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <Link :href="route('admin.rides.index')" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                    &larr; Volver a Carreras
                </Link>

                <!-- Encabezado -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-1">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <h3 class="text-lg font-medium text-arka-text">Carrera #{{ ride.id }}</h3>
                        <span class="font-medium" :class="STATUS_CLASS[ride.status]">{{ STATUS_LABEL[ride.status] ?? ride.status }}</span>
                    </div>
                    <p v-if="ride.is_scheduled" class="text-sm text-arka-text-muted">
                        Programada para el {{ formatDate(ride.scheduled_at) }}
                    </p>
                    <p class="text-sm text-arka-text-muted">Creada el {{ formatDate(ride.created_at) }}</p>
                </div>

                <!-- Cliente y conductor -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-1">
                        <h4 class="text-sm font-medium text-arka-text-muted uppercase tracking-wide">Cliente</h4>
                        <p class="text-arka-text">{{ ride.client?.name ?? 'Cuenta eliminada' }}</p>
                        <p class="text-sm text-arka-text-muted">{{ ride.client?.phone ?? '—' }}</p>
                        <Link
                            v-if="ride.client"
                            :href="route('admin.users.show', ride.client.id)"
                            class="inline-block text-sm text-arka-primary hover:text-arka-primary-bright"
                        >
                            Ver perfil &rarr;
                        </Link>
                    </div>
                    <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-1">
                        <h4 class="text-sm font-medium text-arka-text-muted uppercase tracking-wide">Conductor</h4>
                        <p class="text-arka-text">{{ ride.driver?.name ?? 'Cuenta eliminada' }}</p>
                        <p class="text-sm text-arka-text-muted">{{ ride.driver?.phone ?? '—' }}</p>
                        <Link
                            v-if="ride.driver"
                            :href="route('admin.users.show', ride.driver.id)"
                            class="inline-block text-sm text-arka-primary hover:text-arka-primary-bright"
                        >
                            Ver perfil &rarr;
                        </Link>
                    </div>
                </div>

                <!-- Recorrido -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-2">
                    <h4 class="text-sm font-medium text-arka-text-muted uppercase tracking-wide">Recorrido</h4>
                    <p class="text-arka-text">
                        <span class="text-arka-text-muted">Desde:</span> {{ ride.origin_address ?? '—' }}
                        <span v-if="ride.origin_sector" class="text-arka-text-muted">({{ ride.origin_sector }})</span>
                    </p>
                    <p class="text-arka-text">
                        <span class="text-arka-text-muted">Hasta:</span> {{ ride.destination_address ?? '—' }}
                        <span v-if="ride.destination_sector" class="text-arka-text-muted">({{ ride.destination_sector }})</span>
                    </p>
                    <p class="text-sm text-arka-text-muted">
                        {{ ride.distance_km }} km · {{ ride.round_trip ? 'Ida y vuelta' : 'Solo ida' }}
                        <span v-if="ride.fleet_name"> · Flota: {{ ride.fleet_name }}</span>
                        <span v-if="ride.cooperative_name"> · Cooperativa: {{ ride.cooperative_name }}</span>
                    </p>
                    <p v-if="ride.notes" class="text-sm text-arka-text-muted">Notas: {{ ride.notes }}</p>

                    <div v-if="ride.stops.length" class="pt-2 border-t border-arka-text-muted/10">
                        <p class="text-sm font-medium text-arka-text-muted mb-1">Paradas</p>
                        <ul class="space-y-1">
                            <li v-for="stop in ride.stops" :key="stop.id" class="text-sm text-arka-text">
                                {{ stop.sequence }}. {{ stop.address }}
                                <span v-if="stop.sector" class="text-arka-text-muted">({{ stop.sector }})</span>
                                — {{ money(stop.leg_price) }}
                                <span class="text-arka-text-muted">[{{ STOP_STATUS_LABEL[stop.status] ?? stop.status }}]</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Pago -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-1">
                    <h4 class="text-sm font-medium text-arka-text-muted uppercase tracking-wide">Pago</h4>
                    <p class="text-arka-text">
                        Precio: {{ money(ride.price) }}
                        <span v-if="ride.stops_price > 0" class="text-arka-text-muted"> (+ {{ money(ride.stops_price) }} de paradas)</span>
                    </p>
                    <p v-if="ride.settled_price !== null" class="text-sm text-arka-text-muted">
                        Precio final liquidado: {{ money(ride.settled_price) }}
                    </p>
                    <p class="text-sm text-arka-text-muted">
                        Tarifa/km usada: {{ money(ride.rate_per_km_snapshot) }} ·
                        Método: {{ PAYMENT_METHOD_LABEL[ride.payment_method] ?? ride.payment_method ?? '—' }}
                    </p>
                    <p v-if="ride.points_earned" class="text-sm text-arka-text-muted">Puntos ganados: {{ ride.points_earned }}</p>
                </div>

                <!-- Cancelación / reprogramación, solo si aplica -->
                <div
                    v-if="ride.cancelled_at || ride.pending_reschedule_at"
                    class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-1"
                >
                    <h4 class="text-sm font-medium text-arka-danger uppercase tracking-wide">
                        {{ ride.cancelled_at ? 'Cancelación' : 'Reprogramación pendiente' }}
                    </h4>
                    <p v-if="ride.cancelled_at" class="text-sm text-arka-text">
                        {{ ride.cancelled_by_label ?? 'Alguien' }} canceló el {{ formatDate(ride.cancelled_at) }}.
                        <span v-if="ride.cancellation_reason">Motivo: {{ ride.cancellation_reason }}.</span>
                    </p>
                    <p v-if="ride.cancellation_note" class="text-sm text-arka-text-muted">{{ ride.cancellation_note }}</p>
                    <p v-if="ride.pending_reschedule_at" class="text-sm text-arka-text-muted">
                        Hay una propuesta de nuevo horario esperando confirmación desde el {{ formatDate(ride.pending_reschedule_at) }}.
                    </p>
                </div>

                <!-- Cómo se completó, solo si aplica -->
                <div v-if="ride.completion_reason || ride.completion_note" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-1">
                    <h4 class="text-sm font-medium text-arka-text-muted uppercase tracking-wide">Cómo terminó</h4>
                    <p v-if="ride.completion_reason" class="text-sm text-arka-text">{{ ride.completion_reason }}</p>
                    <p v-if="ride.completion_note" class="text-sm text-arka-text-muted">{{ ride.completion_note }}</p>
                </div>

                <!-- Línea de tiempo -->
                <div v-if="timeline.length" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h4 class="text-sm font-medium text-arka-text-muted uppercase tracking-wide mb-2">Cronología</h4>
                    <ul class="space-y-1">
                        <li v-for="step in timeline" :key="step.label" class="text-sm text-arka-text flex justify-between">
                            <span>{{ step.label }}</span>
                            <span class="text-arka-text-muted">{{ formatDate(step.value) }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Reseñas -->
                <div v-if="reviews.length" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h4 class="text-sm font-medium text-arka-text-muted uppercase tracking-wide mb-2">Reseñas</h4>
                    <ul class="space-y-3">
                        <li v-for="review in reviews" :key="review.id" class="text-sm border-b border-arka-text-muted/10 pb-2 last:border-0 last:pb-0">
                            <p class="text-arka-text">
                                {{ review.reviewer_name }} calificó a {{ review.reviewee_name }} con {{ review.rating }}/5
                            </p>
                            <p v-if="review.rating_reason" class="text-arka-text-muted">{{ review.rating_reason }}</p>
                            <p v-if="review.comment" class="text-arka-text-muted italic">"{{ review.comment }}"</p>
                            <p class="text-xs text-arka-text-muted">{{ formatDate(review.created_at) }}</p>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
