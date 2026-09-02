<script setup>
import { computed, reactive } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DonutChart from '@/Components/charts/DonutChart.vue';
import BarChart from '@/Components/charts/BarChart.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { tierColorClass, tierLabel } from '@/Utils/tierBadge';

const props = defineProps({
    filters: { type: Object, required: true },
    totals: { type: Object, required: true },
    statusBreakdown: { type: Object, required: true },
    dailyEarnings: { type: Array, required: true },
    gamification: { type: Object, required: true },
    // Billetera cooperativa-conductor (pedido explícito del usuario: "el
    // conductor dónde ve cuánto le debe a la cooperativa o cuánto la
    // cooperativa le debe a él... debería estar en los indicadores del
    // conductor") — null si no pertenece a ninguna cooperativa, mismo campo
    // y mismo signo que ya usa Driver/Profile.vue.
    cooperativeWallet: { type: Object, default: null },
    // Trazabilidad de las carreras de cooperativa (pedido explícito del
    // usuario: "el conductor debería tener la trazabilidad de las carreras
    // de cooperativas en sus indicadores") — null si no pertenece a
    // ninguna cooperativa, mismas columnas que ya usa la cooperativa del
    // otro lado (Cooperative/Wallet.vue, Cooperative/DriverShow.vue).
    cooperativeRideHistory: { type: Object, default: null },
    history: { type: Object, required: true },
});

const form = reactive({
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    status: props.filters.status ?? '',
});

function applyFilters() {
    router.get(route('rides.stats'), form, { preserveState: true, preserveScroll: true, replace: true });
}

const STATUS_LABEL = {
    scheduled: 'Programada',
    in_progress: 'En curso',
    completed: 'Completada',
    cancelled: 'Cancelada',
};

const PAYMENT_LABEL = { efectivo: 'Efectivo', transferencia: 'Transferencia' };

function formatDate(iso) {
    return new Date(iso).toLocaleDateString('es-EC', { day: 'numeric', month: 'short', year: 'numeric' });
}

// Progreso hacia la próxima medalla (pedido explícito del usuario: "su meta
// con sus puntos y lo que va a lograr cuando recorra tantos puntos la
// próxima meta") — generaliza el mismo cálculo que ya existe en
// Driver/Profile.vue, ahí acotado a la próxima medalla PÚBLICA nomás.
const progressPercent = computed(() => {
    const next = props.gamification.next_tier;
    if (!next) return 100;
    return Math.min(100, Math.round((props.gamification.total_points / next.min_points) * 100));
});

const statusSegments = computed(() => [
    { label: 'Completadas', value: props.statusBreakdown.completed, color: '#34d399' },
    { label: 'Canceladas', value: props.statusBreakdown.cancelled, color: '#f87171' },
]);
</script>

<template>
    <Head title="Mis indicadores" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between flex-wrap gap-2">
                <h2 class="font-semibold text-xl text-arka-text leading-tight">Mis indicadores</h2>
                <Link :href="route('rides.index')" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                    &larr; Volver a Carreras
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Medallas (pedido explícito del usuario): estado de cuenta, no
                     depende de los filtros de fecha/estado de más abajo. -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <p class="text-sm text-arka-text">
                        Su medalla actual:
                        <span class="ms-1 px-1.5 py-0.5 rounded text-xs font-medium" :class="tierColorClass(gamification.tier.color_key)">
                            {{ tierLabel(gamification.tier) }}
                        </span>
                        <span class="text-xs text-arka-text-muted">
                            ({{ gamification.total_points }} punto{{ gamification.total_points === 1 ? '' : 's' }})
                        </span>
                    </p>

                    <template v-if="gamification.next_tier">
                        <p class="mt-2 text-xs text-arka-text-muted">
                            Le faltan
                            <strong class="text-arka-text">{{ gamification.next_tier.min_points - gamification.total_points }}</strong>
                            puntos para
                            <span class="px-1 rounded" :class="tierColorClass(gamification.next_tier.color_key)">
                                {{ tierLabel(gamification.next_tier) }}
                            </span>
                            <span v-if="gamification.next_tier.is_public_eligible"> — esa medalla lo habilita para aparecer en el directorio público.</span>
                        </p>
                        <div class="mt-2 h-2 rounded-full bg-arka-base/60 overflow-hidden">
                            <div class="h-full bg-arka-primary rounded-full transition-all" :style="{ width: `${progressPercent}%` }" />
                        </div>
                    </template>
                    <p v-else class="mt-2 text-xs text-arka-primary-bright">
                        🏆 Ya tiene la medalla más alta — ¡siga así!
                    </p>
                </div>

                <!-- Billetera cooperativa-conductor (pedido explícito del usuario), mismo
                     criterio que Driver/Profile.vue: con saldo 0 igual se avisa que existe
                     la billetera, en vez de desaparecer sin ningún mensaje. -->
                <div v-if="cooperativeWallet && cooperativeWallet.balance !== 0" class="p-4 sm:p-6 rounded-arka" :class="cooperativeWallet.balance > 0 ? 'bg-arka-warning/10' : 'bg-arka-primary/10'">
                    <p class="text-sm font-medium" :class="cooperativeWallet.balance > 0 ? 'text-arka-warning' : 'text-arka-primary'">
                        {{ cooperativeWallet.balance > 0
                            ? `Le debe $${cooperativeWallet.balance.toFixed(2)} a ${cooperativeWallet.cooperative_name}`
                            : `${cooperativeWallet.cooperative_name} le debe $${Math.abs(cooperativeWallet.balance).toFixed(2)}` }}
                    </p>
                    <p class="mt-1 text-xs text-arka-text-muted">
                        {{ cooperativeWallet.balance > 0
                            ? 'Por carreras en efectivo cuyo margen le correspondía a la cooperativa.'
                            : 'Por carreras por transferencia cuya parte le correspondía a usted.' }}
                    </p>
                    <!-- Pedido explícito del usuario: "en ese mensaje mándalo al
                         desglose... para que vea la trazabilidad" -->
                    <a href="#cooperativa-desglose" class="mt-2 inline-block text-xs font-semibold underline" :class="cooperativeWallet.balance > 0 ? 'text-arka-warning' : 'text-arka-primary'">
                        Ver desglose de sus carreras →
                    </a>
                </div>
                <div v-else-if="cooperativeWallet" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <p class="text-sm font-medium text-arka-text">Billetera con {{ cooperativeWallet.cooperative_name }}</p>
                    <p class="mt-1 text-xs text-arka-text-muted">Sin saldo pendiente por ahora.</p>
                    <a href="#cooperativa-desglose" class="mt-2 inline-block text-xs font-semibold text-arka-primary underline">
                        Ver desglose de sus carreras →
                    </a>
                </div>

                <!-- Filtros (pedido explícito del usuario). -->
                <form @submit.prevent="applyFilters" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka flex flex-wrap items-end gap-3">
                    <div>
                        <label class="block text-xs text-arka-text-muted mb-1">Desde</label>
                        <TextInput type="date" v-model="form.from" class="w-full" />
                    </div>
                    <div>
                        <label class="block text-xs text-arka-text-muted mb-1">Hasta</label>
                        <TextInput type="date" v-model="form.to" class="w-full" />
                    </div>
                    <div>
                        <label class="block text-xs text-arka-text-muted mb-1">Estado</label>
                        <select v-model="form.status" class="rounded-arka border-arka-text-muted/30 bg-arka-card text-arka-text text-sm">
                            <option value="">Todos</option>
                            <option value="completed">Completadas</option>
                            <option value="cancelled">Canceladas</option>
                        </select>
                    </div>
                    <PrimaryButton type="submit">Filtrar</PrimaryButton>
                </form>

                <!-- Tarjetas de indicadores (pedido explícito del usuario), respetan los filtros de arriba. -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                    <div class="p-4 bg-arka-card shadow rounded-arka">
                        <p class="text-xs text-arka-text-muted">Carreras</p>
                        <p class="text-xl font-semibold text-arka-text">{{ totals.total }}</p>
                    </div>
                    <div class="p-4 bg-arka-card shadow rounded-arka">
                        <p class="text-xs text-arka-text-muted">Completadas</p>
                        <p class="text-xl font-semibold text-arka-primary-bright">{{ totals.completed }}</p>
                    </div>
                    <div class="p-4 bg-arka-card shadow rounded-arka">
                        <p class="text-xs text-arka-text-muted">Canceladas</p>
                        <p class="text-xl font-semibold text-arka-danger">{{ totals.cancelled }}</p>
                    </div>
                    <div class="p-4 bg-arka-card shadow rounded-arka">
                        <p class="text-xs text-arka-text-muted">Ganado</p>
                        <p class="text-xl font-semibold text-arka-text">${{ totals.earnings.toFixed(2) }}</p>
                    </div>
                    <div class="p-4 bg-arka-card shadow rounded-arka">
                        <p class="text-xs text-arka-text-muted">Distancia</p>
                        <p class="text-xl font-semibold text-arka-text">{{ totals.distance_km.toFixed(1) }} km</p>
                    </div>
                    <div class="p-4 bg-arka-card shadow rounded-arka">
                        <p class="text-xs text-arka-text-muted">Calificación</p>
                        <p class="text-xl font-semibold text-arka-lime">
                            ★ {{ totals.average_rating || '—' }}
                            <span class="text-xs text-arka-text-muted font-normal">({{ totals.review_count }})</span>
                        </p>
                    </div>
                </div>

                <!-- Segmentación (pedido explícito del usuario: "barras o pizzas"). -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                        <h3 class="text-sm font-medium text-arka-text mb-4">Completadas vs. canceladas</h3>
                        <DonutChart :segments="statusSegments" center-label="carreras" />
                    </div>
                    <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                        <h3 class="text-sm font-medium text-arka-text mb-4">Ganancia por día</h3>
                        <BarChart :data="dailyEarnings" value-prefix="$" />
                    </div>
                </div>

                <!-- Trazabilidad con la cooperativa (pedido explícito del
                     usuario: "el conductor debería tener la trazabilidad de
                     las carreras de cooperativas en sus indicadores") —
                     mismas columnas que ve la cooperativa del otro lado, acá
                     enlazadas desde el mensaje de la billetera de arriba. -->
                <div v-if="cooperativeRideHistory" id="cooperativa-desglose" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka scroll-mt-4">
                    <h3 class="text-lg font-medium text-arka-text mb-3">
                        Trazabilidad con {{ cooperativeWallet?.cooperative_name }} ({{ cooperativeRideHistory.total }})
                    </h3>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[860px] border-collapse text-sm">
                            <thead>
                                <tr class="text-left text-arka-text-muted border-b border-arka-text-muted/10">
                                    <th class="py-2 pr-3">Fecha</th>
                                    <th class="py-2 pr-3">Cliente</th>
                                    <th class="py-2 pr-3">Origen → Destino</th>
                                    <th class="py-2 pr-3">Pago</th>
                                    <th class="py-2 pr-3 text-right">Cobrado al cliente</th>
                                    <th class="py-2 pr-3 text-right">Pagado a usted</th>
                                    <th class="py-2 pr-3 text-right">Usted debe</th>
                                    <th class="py-2 pr-3 text-right">Le deben</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-arka-text-muted/10 [font-variant-numeric:tabular-nums]">
                                <tr v-for="ride in cooperativeRideHistory.data" :key="ride.id">
                                    <td class="py-2 pr-3 text-arka-text-muted whitespace-nowrap">{{ formatDate(ride.date) }}</td>
                                    <td class="py-2 pr-3 text-arka-text">{{ ride.client }}</td>
                                    <td class="py-2 pr-3 text-arka-text-muted max-w-[220px] truncate" :title="`${ride.origin} → ${ride.destination}`">
                                        {{ ride.origin }} → {{ ride.destination }}
                                    </td>
                                    <td class="py-2 pr-3 text-arka-text-muted capitalize">{{ ride.payment_method }}</td>
                                    <td class="py-2 pr-3 text-right text-arka-text font-semibold">${{ Number(ride.price).toFixed(2) }}</td>
                                    <td class="py-2 pr-3 text-right text-arka-text">${{ Number(ride.driver_pay).toFixed(2) }}</td>
                                    <td class="py-2 pr-3 text-right" :class="ride.driver_owes > 0 ? 'font-semibold text-arka-warning' : 'text-arka-text-muted'">{{ ride.driver_owes > 0 ? `$${Number(ride.driver_owes).toFixed(2)}` : '—' }}</td>
                                    <td class="py-2 pr-3 text-right" :class="ride.cooperative_owes > 0 ? 'font-semibold text-arka-primary' : 'text-arka-text-muted'">{{ ride.cooperative_owes > 0 ? `$${Number(ride.cooperative_owes).toFixed(2)}` : '—' }}</td>
                                </tr>
                            </tbody>
                        </table>

                        <p v-if="!cooperativeRideHistory.data.length" class="py-6 text-center text-sm text-arka-text-muted">
                            Todavía no tiene carreras de esta cooperativa.
                        </p>
                    </div>

                    <div v-if="cooperativeRideHistory.prev_page_url || cooperativeRideHistory.next_page_url" class="flex justify-between pt-3">
                        <Link v-if="cooperativeRideHistory.prev_page_url" :href="cooperativeRideHistory.prev_page_url" preserve-state preserve-scroll class="text-sm text-arka-primary hover:text-arka-primary-bright">
                            &larr; Anterior
                        </Link>
                        <span v-else></span>

                        <Link v-if="cooperativeRideHistory.next_page_url" :href="cooperativeRideHistory.next_page_url" preserve-state preserve-scroll class="text-sm text-arka-primary hover:text-arka-primary-bright">
                            Siguiente &rarr;
                        </Link>
                    </div>
                </div>

                <!-- Historial filtrado y paginado (pedido explícito del usuario: "trazabilidad bien con todos los datos"). -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-3">Historial ({{ history.total }})</h3>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-arka-text-muted border-b border-arka-text-muted/10">
                                    <th class="py-2 pr-3">Fecha</th>
                                    <th class="py-2 pr-3">Cliente</th>
                                    <th class="py-2 pr-3">Origen → Destino</th>
                                    <th class="py-2 pr-3">Distancia</th>
                                    <th class="py-2 pr-3">Precio</th>
                                    <th class="py-2 pr-3">Pago</th>
                                    <th class="py-2 pr-3">Estado</th>
                                    <th class="py-2 pr-3">Puntos</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-arka-text-muted/10">
                                <tr v-for="ride in history.data" :key="ride.id">
                                    <td class="py-2 pr-3 text-arka-text-muted whitespace-nowrap">{{ formatDate(ride.date) }}</td>
                                    <td class="py-2 pr-3 text-arka-text">
                                        <Link :href="route('rides.show', ride.id)" class="hover:text-arka-primary-bright">{{ ride.client_name }}</Link>
                                    </td>
                                    <td class="py-2 pr-3 text-arka-text-muted max-w-[220px] truncate" :title="`${ride.origin_address} → ${ride.destination_address}`">
                                        {{ ride.origin_address }} → {{ ride.destination_address }}
                                    </td>
                                    <td class="py-2 pr-3 text-arka-text-muted whitespace-nowrap">{{ Number(ride.distance_km).toFixed(1) }} km</td>
                                    <td class="py-2 pr-3 text-arka-text">${{ Number(ride.price).toFixed(2) }}</td>
                                    <td class="py-2 pr-3 text-arka-text-muted">{{ PAYMENT_LABEL[ride.payment_method] ?? ride.payment_method }}</td>
                                    <td class="py-2 pr-3">
                                        <span :class="ride.status === 'completed' ? 'text-arka-primary-bright' : ride.status === 'cancelled' ? 'text-arka-danger' : 'text-arka-text-muted'">
                                            {{ STATUS_LABEL[ride.status] ?? ride.status }}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-3 text-arka-lime">{{ ride.points_earned ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>

                        <p v-if="!history.data.length" class="py-6 text-center text-sm text-arka-text-muted">
                            No hay carreras que coincidan con estos filtros.
                        </p>
                    </div>

                    <!-- Paginado simple: nunca se carga todo de una vez (sección 9.7) -->
                    <div v-if="history.prev_page_url || history.next_page_url" class="flex justify-between pt-3">
                        <Link v-if="history.prev_page_url" :href="history.prev_page_url" preserve-state class="text-sm text-arka-primary hover:text-arka-primary-bright">
                            &larr; Anterior
                        </Link>
                        <span v-else></span>

                        <Link v-if="history.next_page_url" :href="history.next_page_url" preserve-state class="text-sm text-arka-primary hover:text-arka-primary-bright">
                            Siguiente &rarr;
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
