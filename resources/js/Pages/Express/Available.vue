<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    routes: { type: Array, required: true },
    assignedRoutes: { type: Array, required: true },
    // { [express_route_id]: status }, de las postulaciones que ya hice.
    myApplications: { type: Object, required: true },
    // Pedido explícito del usuario: para poder explicar POR QUÉ la lista
    // está vacía, en vez de un genérico "no hay nada" — no es lo mismo no
    // pertenecer a ninguna flota que pertenecer a una sin Expresos abiertos.
    myFleetCount: { type: Number, required: true },
    // Módulo de Expresos habilitable por plan de conductor (pedido explícito
    // del usuario) — si el plan no lo incluye, se ve la lista igual pero no
    // se puede postular (mismo criterio que "Rutas y Turismo").
    canApply: { type: Boolean, required: true },
});

const DAY_LABELS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

function formatDateTime(value) {
    if (!value) return 'Sin próxima fecha';
    return new Date(value).toLocaleString('es-EC', {
        weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit',
    });
}

function driverAcceptCompanion(id) {
    router.post(route('express-companions.driver-accept', id), {}, { preserveScroll: true });
}

function driverRejectCompanion(id) {
    router.post(route('express-companions.driver-reject', id), {}, { preserveScroll: true });
}

const applyingTo = ref(null);
const proposedPrice = ref('');

function openApplyForm(routeId) {
    applyingTo.value = routeId;
    proposedPrice.value = '';
}

function submitApplication(routeId) {
    router.post(
        route('express-applications.store', routeId),
        { proposed_price: proposedPrice.value || null },
        { preserveScroll: true, onSuccess: () => (applyingTo.value = null) }
    );
}
</script>

<template>
    <Head title="Mis Expresos como conductor" />

    <AuthenticatedLayout>
        <template #header>
            <!-- Bug reportado por el usuario ("el conductor no solicita
                 servicios, recordá eso"): esta pantalla es 100% del lado
                 conductor (postularse a Expresos publicados por sus
                 clientes) — antes tenía un link a "Mis Expresos", que es la
                 pantalla para PUBLICAR uno, una acción exclusiva de cliente. -->
            <h2 class="font-semibold text-xl text-arka-text leading-tight">Mis Expresos como conductor</h2>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <section class="space-y-3">
                    <div>
                        <h3 class="text-lg font-semibold text-arka-text">Expresos que debe realizar</h3>
                        <p class="text-sm text-arka-text-muted">Agenda, carreras del día y acompañantes que necesitan su confirmación.</p>
                    </div>

                    <div v-if="!assignedRoutes.length" class="bg-arka-card rounded-arka p-4 text-sm text-arka-text-muted">
                        Todavía no tiene un Expreso asignado. Cuando un cliente acepte su postulación aparecerá aquí y recibirá una notificación.
                    </div>

                    <article v-for="r in assignedRoutes" :key="r.id" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-4">
                        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <Link :href="route('express-routes.show', r.id)" class="font-semibold text-arka-text hover:text-arka-primary-bright">
                                        {{ r.name }}
                                    </Link>
                                    <span class="text-xs px-2 py-0.5 rounded-full" :class="r.status === 'active' ? 'bg-arka-primary/15 text-arka-primary-bright' : 'bg-arka-warning/15 text-arka-warning'">
                                        {{ r.status === 'active' ? 'Activo' : 'Pausado' }}
                                    </span>
                                </div>
                                <p class="text-sm text-arka-text-muted">Cliente: {{ r.client.name }}</p>
                                <p class="text-sm text-arka-text-muted break-words">{{ r.origin_address || 'Origen' }} → {{ r.destination_address || 'Destino' }}</p>
                            </div>
                            <div class="sm:text-right shrink-0">
                                <p class="text-xs uppercase tracking-wide text-arka-text-muted">Próxima salida</p>
                                <p class="font-medium text-arka-warning">{{ formatDateTime(r.next_run_at) }}</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 text-sm">
                            <div class="p-3 rounded-arka bg-arka-base">
                                <p class="text-arka-text-muted">Días</p>
                                <p class="text-arka-text">{{ r.days_of_week.map((d) => DAY_LABELS[d]).join(', ') }}</p>
                            </div>
                            <div class="p-3 rounded-arka bg-arka-base">
                                <p class="text-arka-text-muted">Horario</p>
                                <p class="text-arka-text">{{ r.departure_time }}<span v-if="r.is_round_trip"> / {{ r.return_time }}</span></p>
                            </div>
                            <div class="p-3 rounded-arka bg-arka-base">
                                <p class="text-arka-text-muted">Pago pactado</p>
                                <p class="text-arka-text">${{ r.offered_price }} por carrera</p>
                            </div>
                        </div>

                        <div v-if="r.ride_requests.length" class="space-y-2">
                            <h4 class="text-sm font-medium text-arka-text">Carreras programadas generadas</h4>
                            <div v-for="request in r.ride_requests" :key="request.id" class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 p-3 rounded-arka bg-arka-base text-sm">
                                <div>
                                    <p class="text-arka-text">{{ formatDateTime(request.scheduled_at) }} · {{ request.client.name }}</p>
                                    <p class="text-arka-text-muted">Estado: {{ request.ride?.status ?? request.status }}</p>
                                </div>
                                <Link v-if="request.ride" :href="route('rides.show', request.ride.id)" class="text-arka-primary hover:text-arka-primary-bright">Abrir carrera</Link>
                                <Link v-else :href="route('rides.index')" class="text-arka-warning hover:opacity-80">Confirmar en Carreras</Link>
                            </div>
                        </div>

                        <div v-if="r.companions.some((c) => c.driver_approval_status === 'pending')" class="p-3 rounded-arka border border-arka-warning/40 bg-arka-warning/10 space-y-3">
                            <h4 class="font-medium text-arka-warning">Acompañantes por confirmar</h4>
                            <div v-for="c in r.companions.filter((c) => c.driver_approval_status === 'pending')" :key="c.id" class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                <div class="text-sm">
                                    <p class="text-arka-text font-medium">{{ c.passenger.name }}</p>
                                    <p class="text-arka-text-muted break-words">{{ c.origin_address || r.origin_address }} → {{ c.destination_address || r.destination_address }}</p>
                                </div>
                                <div class="flex gap-2 shrink-0">
                                    <PrimaryButton @click="driverAcceptCompanion(c.id)">Sí puedo</PrimaryButton>
                                    <SecondaryButton @click="driverRejectCompanion(c.id)">No puedo</SecondaryButton>
                                </div>
                            </div>
                        </div>
                    </article>
                </section>

                <hr class="border-arka-text-muted/15" />

                <p class="text-sm text-arka-text-muted">
                    Acá aparecen los Expresos que publicó un cliente <strong>de una flota a la que pertenecés</strong>
                    (no es un listado abierto a cualquiera) — postulate con el precio ofrecido o proponé otro monto.
                </p>

                <!-- Pedido explícito del usuario ("no sé a quién le aparece un
                     Expreso, me metí con todos los conductores y nada"): el
                     motivo más común de una lista vacía es no pertenecer
                     todavía a ninguna flota como conductor activo — eso se
                     distingue acá de "sí pertenezco, pero nadie publicó
                     nada" para no dejar la duda. -->
                <div v-if="!routes.length" class="bg-arka-card rounded-arka p-4 text-sm text-arka-text-muted">
                    <template v-if="myFleetCount === 0">
                        Todavía no es conductor activo de ninguna flota — un cliente tiene que agregarlo primero
                        (o usted aceptar su invitación) para que sus Expresos le empiecen a aparecer acá.
                    </template>
                    <template v-else>
                        Es conductor activo de {{ myFleetCount }} flota(s), pero por ahora ninguno de esos clientes
                        tiene un Expreso abierto a postulaciones.
                    </template>
                </div>

                <p v-if="!canApply" class="text-sm text-arka-warning bg-arka-warning/10 p-3 rounded-arka">
                    Su plan actual no incluye postularse a Expresos — puede ver la lista, pero hace falta un plan
                    superior para postularse.
                    <Link :href="route('driver.plan.edit')" class="underline hover:text-arka-primary-bright">Ver planes</Link>
                </p>

                <ul v-if="routes.length" class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <li v-for="r in routes" :key="r.id" class="p-4 sm:p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <Link :href="route('express-routes.show', r.id)" class="text-arka-text font-medium hover:text-arka-primary-bright">
                                    {{ r.name }}
                                </Link>
                                <p class="text-sm text-arka-text-muted">{{ r.client.name }}</p>
                                <p class="text-sm text-arka-text-muted">
                                    {{ r.days_of_week.map((d) => DAY_LABELS[d]).join(', ') }} · sale {{ r.departure_time }}
                                </p>
                                <p v-if="r.conditions.length" class="text-xs text-arka-text-muted">
                                    Condiciones: {{ r.conditions.map((c) => c.description).join(', ') }}
                                </p>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="text-arka-text font-semibold mb-2">${{ r.offered_price }}/carrera</p>
                                <span v-if="myApplications[r.id]" class="text-sm text-arka-lime">
                                    Postulación: {{ myApplications[r.id] }}
                                </span>
                                <PrimaryButton v-else-if="canApply" @click="openApplyForm(r.id)">Postularme</PrimaryButton>
                                <span v-else class="text-xs text-arka-text-muted">Su plan no incluye postularse</span>
                            </div>
                        </div>

                        <form v-if="applyingTo === r.id" @submit.prevent="submitApplication(r.id)" class="mt-3 flex gap-2">
                            <TextInput
                                type="number"
                                step="0.01"
                                min="0.01"
                                class="block w-full"
                                v-model="proposedPrice"
                                :placeholder="`Deje vacío para aceptar $${r.offered_price} tal cual`"
                            />
                            <PrimaryButton>Confirmar</PrimaryButton>
                            <SecondaryButton type="button" @click="applyingTo = null">Cancelar</SecondaryButton>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
