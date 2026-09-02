<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    membership: { type: Object, required: true },
    summary: { type: Object, required: true },
    rides: { type: Object, required: true },
});

const money = (value) => new Intl.NumberFormat('es-EC', { style: 'currency', currency: 'USD' }).format(value || 0);
const hours = (minutes) => `${Math.floor((minutes || 0) / 60)} h ${Math.round((minutes || 0) % 60)} min`;
const date = (value) => value ? new Intl.DateTimeFormat('es-EC', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
const statusLabel = { completed: 'Completada', cancelled: 'Cancelada', in_progress: 'En curso', scheduled: 'Programada' };
</script>

<template>
    <Head :title="`Perfil de ${membership.driver.name}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('cooperative.drivers.index')" class="grid h-9 w-9 place-items-center rounded-full border border-arka-text-muted/20 text-arka-text-muted hover:text-arka-primary" aria-label="Volver">←</Link>
                <h2 class="text-lg font-semibold text-arka-text">Detalle del conductor</h2>
            </div>
        </template>

        <div class="mx-auto max-w-6xl space-y-5 px-4 py-6 sm:px-6">
            <section class="overflow-hidden rounded-2xl border border-arka-primary/15 bg-arka-card shadow-xl">
                <div class="h-1 bg-gradient-to-r from-arka-primary to-emerald-300"></div>
                <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
                    <UserAvatar :user="membership.driver" size-class="h-16 w-16 text-lg shrink-0" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="text-xl font-bold text-arka-text">{{ membership.driver.name }}</h1>
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="membership.driver.driver_profile?.is_available ? 'bg-emerald-400/15 text-emerald-300' : 'bg-slate-400/10 text-arka-text-muted'">{{ membership.driver.driver_profile?.is_available ? 'Disponible ahora' : 'Desconectado' }}</span>
                        </div>
                        <p class="mt-1 text-sm text-arka-text-muted">{{ membership.driver.member_code }} · {{ membership.driver.driver_profile?.vehicle_brand }} {{ membership.driver.driver_profile?.vehicle_model }} · {{ membership.driver.driver_profile?.vehicle_plate || 'Sin placa' }}</p>
                    </div>
                </div>
            </section>

            <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <article class="rounded-2xl bg-arka-card p-4"><p class="text-xs uppercase tracking-wider text-arka-text-muted">Ingresos hoy</p><p class="mt-2 text-2xl font-bold text-arka-primary">{{ money(summary.earnings_today) }}</p><p class="mt-1 text-xs text-arka-text-muted">{{ summary.rides_today }} carreras</p></article>
                <article class="rounded-2xl bg-arka-card p-4"><p class="text-xs uppercase tracking-wider text-arka-text-muted">Esta semana</p><p class="mt-2 text-2xl font-bold text-arka-text">{{ money(summary.earnings_week) }}</p><p class="mt-1 text-xs text-arka-text-muted">{{ hours(summary.active_minutes_week) }} activo</p></article>
                <article class="rounded-2xl bg-arka-card p-4"><p class="text-xs uppercase tracking-wider text-arka-text-muted">Este mes</p><p class="mt-2 text-2xl font-bold text-arka-text">{{ money(summary.earnings_month) }}</p><p class="mt-1 text-xs text-arka-text-muted">{{ summary.rides_month }} carreras</p></article>
                <article class="rounded-2xl bg-arka-card p-4"><p class="text-xs uppercase tracking-wider text-arka-text-muted">Histórico</p><p class="mt-2 text-2xl font-bold text-arka-text">{{ money(summary.earnings_total) }}</p><p class="mt-1 text-xs text-arka-text-muted">{{ summary.completed_total }} completadas</p></article>
            </section>

            <section class="grid gap-5 lg:grid-cols-[1.25fr_.75fr]">
                <article class="rounded-2xl bg-arka-card p-5">
                    <div class="flex items-center justify-between"><div><p class="text-xs uppercase tracking-widest text-arka-primary">Rendimiento</p><h2 class="mt-1 font-semibold text-arka-text">Resumen operativo</h2></div><span class="text-xs text-arka-text-muted">Datos de esta cooperativa</span></div>
                    <div class="mt-5 grid grid-cols-3 gap-3 text-center">
                        <div class="rounded-xl bg-black/10 p-3"><p class="text-xl font-bold text-arka-text">{{ summary.assigned_total }}</p><p class="text-xs text-arka-text-muted">Asignadas</p></div>
                        <div class="rounded-xl bg-black/10 p-3"><p class="text-xl font-bold text-emerald-300">{{ summary.completed_total }}</p><p class="text-xs text-arka-text-muted">Completadas</p></div>
                        <div class="rounded-xl bg-black/10 p-3"><p class="text-xl font-bold text-rose-300">{{ summary.cancelled_total }}</p><p class="text-xs text-arka-text-muted">Canceladas</p></div>
                    </div>
                </article>
                <article class="rounded-2xl bg-arka-card p-5">
                    <p class="text-xs uppercase tracking-widest text-arka-primary">Tiempo conectado</p>
                    <p class="mt-3 text-3xl font-bold text-arka-text">{{ hours(summary.active_minutes_month) }}</p>
                    <p class="text-xs text-arka-text-muted">durante este mes · {{ hours(summary.active_minutes_today) }} hoy</p>
                    <p v-if="!summary.activity_tracking_since" class="mt-4 rounded-xl bg-amber-400/10 p-3 text-xs text-amber-200">El registro preciso de sesiones comienza con esta actualización.</p>
                    <p v-else class="mt-4 text-xs text-arka-text-muted">Registrado desde {{ date(summary.activity_tracking_since) }} · histórico {{ hours(summary.active_minutes_total) }}</p>
                </article>
            </section>

            <!-- Billetera (pedido explícito del usuario): saldo neto entre
                 la cooperativa y este conductor — carreras en efectivo donde
                 se quedó con el margen de la cooperativa, compensadas con
                 las de transferencia donde fue la cooperativa quien se
                 quedó con la parte del conductor. Solo tiene datos si la
                 cooperativa configuró sus dos tarifas (Cooperative/Profile.vue). -->
            <section v-if="summary.wallet_balance !== 0" class="rounded-2xl p-5" :class="summary.wallet_balance > 0 ? 'bg-amber-400/10' : 'bg-emerald-400/10'">
                <p class="text-xs uppercase tracking-widest" :class="summary.wallet_balance > 0 ? 'text-amber-300' : 'text-emerald-300'">Billetera</p>
                <p class="mt-2 text-2xl font-bold" :class="summary.wallet_balance > 0 ? 'text-amber-200' : 'text-emerald-200'">
                    {{ summary.wallet_balance > 0 ? `El conductor le debe ${money(summary.wallet_balance)}` : `Ustedes le deben ${money(Math.abs(summary.wallet_balance))}` }}
                </p>
                <p class="mt-1 text-xs text-arka-text-muted">
                    {{ summary.wallet_balance > 0
                        ? 'Cobró de más en efectivo carreras cuyo margen era de la cooperativa.'
                        : 'La cooperativa recibió transferencias que en parte le correspondían a él.' }}
                </p>
            </section>
            <section v-else class="rounded-2xl bg-arka-card p-5">
                <p class="text-xs uppercase tracking-widest text-arka-primary">Billetera</p>
                <p class="mt-2 text-sm text-arka-text-muted">Sin saldo pendiente con este conductor.</p>
            </section>

            <!-- Trazabilidad (pedido explícito del usuario: "en cada
                 conductor de cooperativa quiero ver esa tabla también para
                 ver el detalle de las gestiones") — misma tabla que
                 Cooperative/Wallet.vue, sin la columna "Conductor" porque acá
                 ya es un solo conductor. -->
            <section class="rounded-2xl bg-arka-card">
                <div class="border-b border-arka-text-muted/10 p-5"><p class="text-xs uppercase tracking-widest text-arka-primary">Historial</p><h2 class="mt-1 font-semibold text-arka-text">Carreras e ingresos individuales</h2></div>
                <p v-if="!rides.data.length" class="p-6 text-sm text-arka-text-muted">Este conductor todavía no registra carreras con la cooperativa.</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[980px] border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-arka-text-muted/10 text-left text-xs uppercase tracking-wide text-arka-text-muted">
                                <th class="px-4 py-3 font-medium">Fecha</th>
                                <th class="px-4 py-3 font-medium">Cliente</th>
                                <th class="px-4 py-3 font-medium">Origen</th>
                                <th class="px-4 py-3 font-medium">Destino</th>
                                <th class="px-4 py-3 text-right font-medium">Km</th>
                                <th class="px-4 py-3 text-right font-medium">$/km</th>
                                <th class="px-4 py-3 font-medium">Pago</th>
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
                                <td class="px-4 py-3 text-arka-text">{{ ride.client }}</td>
                                <td class="max-w-[220px] truncate px-4 py-3 text-arka-text-muted" :title="ride.origin">{{ ride.origin }}</td>
                                <td class="max-w-[220px] truncate px-4 py-3 text-arka-text-muted" :title="ride.destination">{{ ride.destination }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-arka-text-muted">{{ ride.distance_km != null ? `${ride.distance_km.toFixed(1)} km` : '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-arka-text-muted">{{ ride.rate_per_km != null ? money(ride.rate_per_km) : '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-arka-text-muted capitalize">{{ ride.payment_method }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold" :class="ride.status === 'cancelled' ? 'text-arka-text-muted line-through' : 'text-arka-text'">{{ money(ride.price) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-arka-text">{{ money(ride.driver_pay) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right" :class="ride.driver_owes > 0 ? 'font-semibold text-amber-300' : 'text-arka-text-muted'">{{ ride.driver_owes > 0 ? money(ride.driver_owes) : '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right" :class="ride.cooperative_owes > 0 ? 'font-semibold text-emerald-300' : 'text-arka-text-muted'">{{ ride.cooperative_owes > 0 ? money(ride.cooperative_owes) : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="rides.links.length > 3" class="flex flex-wrap gap-2 border-t border-arka-text-muted/10 p-4"><Link v-for="link in rides.links" :key="link.label" :href="link.url || '#'" v-html="link.label" class="rounded-lg px-3 py-1.5 text-xs" :class="link.active ? 'bg-arka-primary text-black' : 'bg-black/10 text-arka-text-muted'" /></div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
