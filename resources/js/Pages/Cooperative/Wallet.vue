<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

// Pedido explícito del usuario ("la cooperativa ve la trazabilidad de las
// carreras, cuánto hizo su equipo y cuánto le deben o cuánto ella le debe a
// su equipo"): antes solo se podía ver esto conductor por conductor en
// Cooperative/DriverShow.vue — acá va todo el equipo junto.
const props = defineProps({
    cooperative: { type: Object, required: true },
    earnings: { type: Object, required: true },
    walletTotal: { type: Number, required: true },
    walletByDriver: { type: Array, required: true },
    paymentStats: { type: Object, required: true },
    paymentReviews: { type: Array, required: true },
    rides: { type: Object, required: true },
});

const money = (value) => new Intl.NumberFormat('es-EC', { style: 'currency', currency: 'USD' }).format(value || 0);
const date = (value) => value ? new Intl.DateTimeFormat('es-EC', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
const statusLabel = { completed: 'Completada', cancelled: 'Cancelada', in_progress: 'En curso', scheduled: 'Programada' };
const paymentStatusLabel = { pending: 'Pendiente', proof_submitted: 'Por revisar', confirmed: 'Pagada', rejected: 'Rechazada' };
const rejectionReasons = reactive({});
const processing = reactive({});

function confirmTransfer(rideId) {
    processing[rideId] = true;
    router.post(route('cooperative.payments.transfer.confirm', rideId), {}, {
        preserveScroll: true,
        onFinish: () => { processing[rideId] = false; },
    });
}

function rejectTransfer(rideId) {
    const reason = rejectionReasons[rideId]?.trim();
    if (!reason) return;
    processing[rideId] = true;
    router.post(route('cooperative.payments.transfer.reject', rideId), { reason }, {
        preserveScroll: true,
        onFinish: () => { processing[rideId] = false; },
    });
}

const size = (bytes) => bytes ? `${(Number(bytes) / 1024).toFixed(0)} KB` : '—';
</script>

<template>
    <Head title="Billetera de la cooperativa" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('cooperative.dashboard')" class="grid h-9 w-9 place-items-center rounded-full border border-arka-text-muted/20 text-arka-text-muted hover:text-arka-primary" aria-label="Volver">←</Link>
                <h2 class="text-lg font-semibold text-arka-text">Billetera y trazabilidad del equipo</h2>
            </div>
        </template>

        <div class="mx-auto max-w-6xl space-y-5 px-4 py-6 sm:px-6">
            <!-- Cuánto hizo su equipo (pedido explícito del usuario) — mismo
                 criterio de columnas que Cooperative/DriverShow.vue, sumado
                 sobre TODOS los conductores afiliados en vez de uno solo. -->
            <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <article class="rounded-2xl bg-arka-card p-4">
                    <p class="text-xs uppercase tracking-wider text-arka-text-muted">Ingresos hoy</p>
                    <p class="mt-2 text-2xl font-bold text-arka-primary">{{ money(earnings.today) }}</p>
                </article>
                <article class="rounded-2xl bg-arka-card p-4">
                    <p class="text-xs uppercase tracking-wider text-arka-text-muted">Esta semana</p>
                    <p class="mt-2 text-2xl font-bold text-arka-text">{{ money(earnings.week) }}</p>
                </article>
                <article class="rounded-2xl bg-arka-card p-4">
                    <p class="text-xs uppercase tracking-wider text-arka-text-muted">Este mes</p>
                    <p class="mt-2 text-2xl font-bold text-arka-text">{{ money(earnings.month) }}</p>
                </article>
                <article class="rounded-2xl bg-arka-card p-4">
                    <p class="text-xs uppercase tracking-wider text-arka-text-muted">Histórico</p>
                    <p class="mt-2 text-2xl font-bold text-arka-text">{{ money(earnings.total) }}</p>
                    <p class="mt-1 text-xs text-arka-text-muted">{{ earnings.completed_rides }} carreras completadas</p>
                </article>
            </section>

            <!-- Bandeja financiera: los comprobantes no se mezclan con el
                 despacho operativo. La cooperativa toma una decisión y esa
                 confirmación actualiza inmediatamente al conductor. -->
            <section class="rounded-2xl bg-arka-card">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-arka-text-muted/10 p-5">
                    <div>
                        <p class="text-xs uppercase tracking-widest text-arka-primary">Pagos de carreras</p>
                        <h2 class="mt-1 font-semibold text-arka-text">Comprobantes por revisar</h2>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span class="rounded-full bg-sky-400/10 px-3 py-1.5 font-semibold text-sky-300">{{ paymentStats.proofs_to_review }} por revisar</span>
                        <span class="rounded-full bg-amber-400/10 px-3 py-1.5 font-semibold text-amber-300">{{ paymentStats.cash_to_confirm }} efectivo pendiente</span>
                        <span class="rounded-full bg-arka-primary/10 px-3 py-1.5 font-semibold text-arka-primary">{{ paymentStats.confirmed }} pagadas</span>
                    </div>
                </div>

                <p v-if="!paymentReviews.length" class="p-6 text-sm text-arka-text-muted">No hay comprobantes pendientes de revisión.</p>
                <div v-else class="grid gap-3 p-4 lg:grid-cols-2">
                    <article v-for="payment in paymentReviews" :key="payment.id" class="rounded-xl border border-arka-text-muted/15 bg-arka-base/35 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-arka-text">Carrera #{{ payment.id }} · {{ money(payment.amount) }}</p>
                                <p class="mt-1 text-xs text-arka-text-muted">Cliente: {{ payment.client }}</p>
                                <p class="text-xs text-arka-text-muted">Conductor: {{ payment.driver }}</p>
                            </div>
                            <span class="rounded-full bg-sky-400/10 px-2.5 py-1 text-[11px] font-semibold text-sky-300">Por revisar</span>
                        </div>
                        <div class="mt-3 flex items-center justify-between gap-3 rounded-lg bg-black/10 px-3 py-2 text-xs text-arka-text-muted">
                            <span>Optimizado: {{ size(payment.stored_size) }}</span>
                            <span v-if="payment.original_size">Original: {{ size(payment.original_size) }}</span>
                        </div>
                        <a :href="payment.proof_url" target="_blank" class="mt-3 flex w-full items-center justify-center rounded-xl border border-arka-primary/30 px-3 py-2.5 text-sm font-semibold text-arka-primary hover:bg-arka-primary/10">Ver comprobante completo</a>
                        <input v-model="rejectionReasons[payment.id]" type="text" maxlength="500" placeholder="Motivo si necesita rechazarlo" class="mt-3 block w-full rounded-xl border-arka-text-muted/20 bg-arka-base text-sm text-arka-text placeholder:text-arka-text-muted/60 focus:border-arka-primary focus:ring-arka-primary" />
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <button type="button" class="rounded-xl border border-arka-danger/30 px-3 py-2.5 text-sm font-semibold text-arka-danger disabled:opacity-40" :disabled="processing[payment.id] || !rejectionReasons[payment.id]?.trim()" @click="rejectTransfer(payment.id)">Rechazar</button>
                            <button type="button" class="rounded-xl bg-arka-primary px-3 py-2.5 text-sm font-bold text-arka-base disabled:opacity-40" :disabled="processing[payment.id]" @click="confirmTransfer(payment.id)">Confirmar pago</button>
                        </div>
                    </article>
                </div>
            </section>

            <!-- Cuánto le deben o cuánto ella le debe a su equipo (pedido
                 explícito del usuario) — total agregado primero, desglose
                 por conductor debajo cuando hay algo pendiente. Mismo signo
                 que CooperativeWalletEntry::balanceFor(): positivo = el
                 equipo le debe a la cooperativa, negativo = al revés. -->
            <section class="rounded-2xl p-5" :class="walletTotal === 0 ? 'bg-arka-card' : walletTotal > 0 ? 'bg-amber-400/10' : 'bg-emerald-400/10'">
                <p class="text-xs uppercase tracking-widest" :class="walletTotal === 0 ? 'text-arka-primary' : walletTotal > 0 ? 'text-amber-300' : 'text-emerald-300'">Billetera del equipo</p>
                <p v-if="walletTotal === 0" class="mt-2 text-sm text-arka-text-muted">Sin saldo pendiente con su equipo por ahora.</p>
                <template v-else>
                    <p class="mt-2 text-2xl font-bold" :class="walletTotal > 0 ? 'text-amber-200' : 'text-emerald-200'">
                        {{ walletTotal > 0 ? `Su equipo les debe ${money(walletTotal)}` : `Ustedes le deben ${money(Math.abs(walletTotal))} a su equipo` }}
                    </p>
                    <p class="mt-1 text-xs text-arka-text-muted">
                        {{ walletTotal > 0
                            ? 'Suma de lo que cobraron de más en efectivo, menos lo que ya se les debe por transferencia.'
                            : 'Suma de lo que recibieron de más por transferencia, menos lo que ya cobraron de más en efectivo.' }}
                    </p>
                </template>

                <div v-if="walletByDriver.length" class="mt-4 divide-y divide-arka-text-muted/10 rounded-xl bg-black/10">
                    <div v-for="row in walletByDriver" :key="row.driver_user_id" class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                        <span class="text-arka-text">{{ row.driver_name }}</span>
                        <span class="font-semibold" :class="row.balance > 0 ? 'text-amber-300' : 'text-emerald-300'">
                            {{ row.balance > 0 ? `Le debe ${money(row.balance)}` : `Le deben ${money(Math.abs(row.balance))}` }}
                        </span>
                    </div>
                </div>
            </section>

            <!-- Trazabilidad de las carreras (pedido explícito del usuario:
                 "que sea una tabla" con cliente, origen, destino, cobrado al
                 cliente, pagado al conductor, tipo de pago, y cuánto debe
                 cada lado) — historial completo del equipo en una sola
                 tabla, no tarjetas sueltas por carrera. -->
            <section class="rounded-2xl bg-arka-card">
                <div class="border-b border-arka-text-muted/10 p-5">
                    <p class="text-xs uppercase tracking-widest text-arka-primary">Trazabilidad</p>
                    <h2 class="mt-1 font-semibold text-arka-text">Carreras de todo el equipo</h2>
                </div>
                <p v-if="!rides.data.length" class="p-6 text-sm text-arka-text-muted">Su equipo todavía no registra carreras con la cooperativa.</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[1240px] border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-arka-text-muted/10 text-left text-xs uppercase tracking-wide text-arka-text-muted">
                                <th class="px-4 py-3 font-medium">Fecha</th>
                                <th class="px-4 py-3 font-medium">Conductor</th>
                                <th class="px-4 py-3 font-medium">Cliente</th>
                                <th class="px-4 py-3 font-medium">Origen</th>
                                <th class="px-4 py-3 font-medium">Destino</th>
                                <th class="px-4 py-3 text-right font-medium">Km</th>
                                <th class="px-4 py-3 text-right font-medium">$/km</th>
                                <th class="px-4 py-3 font-medium">Pago</th>
                                <th class="px-4 py-3 font-medium">Estado del pago</th>
                                <th class="px-4 py-3 text-right font-medium">Cobrado al cliente</th>
                                <th class="px-4 py-3 text-right font-medium">Pagado al conductor</th>
                                <th class="px-4 py-3 text-right font-medium">Debe el conductor</th>
                                <th class="px-4 py-3 text-right font-medium">Le debemos al conductor</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-arka-text-muted/10 [font-variant-numeric:tabular-nums]">
                            <tr v-for="ride in rides.data" :key="ride.id" class="align-top">
                                <td class="whitespace-nowrap px-4 py-3 text-xs text-arka-text-muted">
                                    {{ date(ride.date) }}
                                    <span
                                        class="mt-1 block w-fit rounded-full px-2 py-0.5 text-[10px]"
                                        :class="ride.status === 'completed' ? 'bg-emerald-400/10 text-emerald-300' : ride.status === 'cancelled' ? 'bg-rose-400/10 text-rose-300' : 'bg-sky-400/10 text-sky-300'"
                                    >{{ statusLabel[ride.status] || ride.status }}</span>
                                </td>
                                <td class="px-4 py-3 text-arka-text">{{ ride.driver }}</td>
                                <td class="px-4 py-3 text-arka-text">{{ ride.client }}</td>
                                <td class="max-w-[220px] truncate px-4 py-3 text-arka-text-muted" :title="ride.origin">{{ ride.origin }}</td>
                                <td class="max-w-[220px] truncate px-4 py-3 text-arka-text-muted" :title="ride.destination">{{ ride.destination }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-arka-text-muted">{{ ride.distance_km != null ? `${ride.distance_km.toFixed(1)} km` : '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-arka-text-muted">{{ ride.rate_per_km != null ? money(ride.rate_per_km) : '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-arka-text-muted capitalize">{{ ride.payment_method }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="rounded-full px-2 py-1 text-[11px] font-semibold" :class="ride.payment_status === 'confirmed' ? 'bg-arka-primary/10 text-arka-primary' : ride.payment_status === 'proof_submitted' ? 'bg-sky-400/10 text-sky-300' : ride.payment_status === 'rejected' ? 'bg-arka-danger/10 text-arka-danger' : 'bg-amber-400/10 text-amber-300'">{{ paymentStatusLabel[ride.payment_status] || 'Pendiente' }}</span>
                                    <a v-if="ride.payment_proof_url" :href="ride.payment_proof_url" target="_blank" class="mt-1 block text-[11px] text-arka-primary hover:underline">Ver comprobante</a>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold" :class="ride.status === 'cancelled' ? 'text-arka-text-muted line-through' : 'text-arka-text'">{{ money(ride.price) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-arka-text">{{ money(ride.driver_pay) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right" :class="ride.driver_owes > 0 ? 'font-semibold text-amber-300' : 'text-arka-text-muted'">{{ ride.driver_owes > 0 ? money(ride.driver_owes) : '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right" :class="ride.cooperative_owes > 0 ? 'font-semibold text-emerald-300' : 'text-arka-text-muted'">{{ ride.cooperative_owes > 0 ? money(ride.cooperative_owes) : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="rides.links.length > 3" class="flex flex-wrap gap-2 border-t border-arka-text-muted/10 p-4">
                    <Link v-for="link in rides.links" :key="link.label" :href="link.url || '#'" v-html="link.label" class="rounded-lg px-3 py-1.5 text-xs" :class="link.active ? 'bg-arka-primary text-black' : 'bg-black/10 text-arka-text-muted'" />
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
