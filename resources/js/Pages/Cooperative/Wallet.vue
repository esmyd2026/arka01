<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

// Pedido explícito del usuario ("la cooperativa ve la trazabilidad de las
// carreras, cuánto hizo su equipo y cuánto le deben o cuánto ella le debe a
// su equipo"): antes solo se podía ver esto conductor por conductor en
// Cooperative/DriverShow.vue — acá va todo el equipo junto.
const props = defineProps({
    cooperative: { type: Object, required: true },
    earnings: { type: Object, required: true },
    walletTotal: { type: Number, required: true },
    walletByDriver: { type: Array, required: true },
    rides: { type: Object, required: true },
});

const money = (value) => new Intl.NumberFormat('es-EC', { style: 'currency', currency: 'USD' }).format(value || 0);
const date = (value) => value ? new Intl.DateTimeFormat('es-EC', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
const statusLabel = { completed: 'Completada', cancelled: 'Cancelada', in_progress: 'En curso', scheduled: 'Programada' };
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

            <!-- Trazabilidad de las carreras (pedido explícito del
                 usuario) — historial completo del equipo, no solo el de un
                 conductor a la vez. -->
            <section class="rounded-2xl bg-arka-card">
                <div class="border-b border-arka-text-muted/10 p-5">
                    <p class="text-xs uppercase tracking-widest text-arka-primary">Trazabilidad</p>
                    <h2 class="mt-1 font-semibold text-arka-text">Carreras de todo el equipo</h2>
                </div>
                <p v-if="!rides.data.length" class="p-6 text-sm text-arka-text-muted">Su equipo todavía no registra carreras con la cooperativa.</p>
                <div v-else class="divide-y divide-arka-text-muted/10">
                    <article v-for="ride in rides.data" :key="ride.id" class="grid gap-3 p-4 sm:grid-cols-[1fr_auto] sm:items-center">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-semibold text-arka-text">{{ ride.driver }} → {{ ride.client }}</p>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px]"
                                    :class="ride.status === 'completed' ? 'bg-emerald-400/10 text-emerald-300' : ride.status === 'cancelled' ? 'bg-rose-400/10 text-rose-300' : 'bg-sky-400/10 text-sky-300'"
                                >{{ statusLabel[ride.status] || ride.status }}</span>
                            </div>
                            <p class="mt-1 truncate text-sm text-arka-text-muted">{{ ride.origin }} → {{ ride.destination }}</p>
                            <p class="mt-1 text-xs text-arka-text-muted">{{ date(ride.date) }} · {{ ride.distance_km != null ? `${ride.distance_km.toFixed(1)} km` : '' }} · {{ ride.payment_method }}</p>
                        </div>
                        <p class="text-lg font-bold" :class="ride.status === 'cancelled' ? 'text-arka-text-muted line-through' : 'text-arka-primary'">{{ money(ride.price) }}</p>
                    </article>
                </div>
                <div v-if="rides.links.length > 3" class="flex flex-wrap gap-2 border-t border-arka-text-muted/10 p-4">
                    <Link v-for="link in rides.links" :key="link.label" :href="link.url || '#'" v-html="link.label" class="rounded-lg px-3 py-1.5 text-xs" :class="link.active ? 'bg-arka-primary text-black' : 'bg-black/10 text-arka-text-muted'" />
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
