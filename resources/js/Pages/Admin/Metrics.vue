<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    driverPlans: { type: Array, required: true },
    clientPlans: { type: Array, required: true },
    // Pedido explícito del usuario: "no tengo indicadores de las cooperativas".
    cooperativePlans: { type: Array, required: true },
    estimatedMrr: { type: Number, required: true },
    totals: { type: Object, required: true },
    landingCta: { type: Object, required: true },
});
</script>

<template>
    <Head title="Admin · Indicadores" />

    <AdminLayout title="Indicadores">
        <div class="py-12">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Números generales: "quién es quién" a nivel plataforma -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div class="p-4 bg-arka-card shadow rounded-arka text-center">
                        <p class="text-2xl font-semibold text-arka-text">{{ totals.users }}</p>
                        <p class="text-xs text-arka-text-muted">Usuarios</p>
                    </div>
                    <div class="p-4 bg-arka-card shadow rounded-arka text-center">
                        <p class="text-2xl font-semibold text-arka-text">{{ totals.drivers }}</p>
                        <p class="text-xs text-arka-text-muted">Conductores</p>
                    </div>
                    <div class="p-4 bg-arka-card shadow rounded-arka text-center">
                        <p class="text-2xl font-semibold text-arka-text">{{ totals.clients }}</p>
                        <p class="text-xs text-arka-text-muted">Clientes</p>
                    </div>
                    <div class="p-4 bg-arka-card shadow rounded-arka text-center">
                        <p class="text-2xl font-semibold text-arka-text">{{ totals.fleets }}</p>
                        <p class="text-xs text-arka-text-muted">Flotas</p>
                    </div>
                    <div class="p-4 bg-arka-card shadow rounded-arka text-center">
                        <p class="text-2xl font-semibold text-arka-text">{{ totals.completedRides }}</p>
                        <p class="text-xs text-arka-text-muted">Carreras completadas</p>
                    </div>
                    <!-- Pedido explícito del usuario: "no tengo indicadores de las cooperativas" -->
                    <div class="p-4 bg-arka-card shadow rounded-arka text-center">
                        <p class="text-2xl font-semibold text-arka-text">{{ totals.cooperatives }}</p>
                        <p class="text-xs text-arka-text-muted">Cooperativas</p>
                    </div>
                    <div class="p-4 bg-arka-card shadow rounded-arka text-center">
                        <p class="text-2xl font-semibold text-arka-text">{{ totals.approvedCooperatives }}</p>
                        <p class="text-xs text-arka-text-muted">Cooperativas aprobadas</p>
                    </div>
                </div>

                <!-- Embudo de adquisición de la portada. Se muestran personas
                     únicas para decidir con datos, no un contador inflado por
                     recargas o dobles clics. -->
                <section class="overflow-hidden rounded-2xl border border-arka-primary/15 bg-arka-card shadow-sm">
                    <div class="flex flex-col gap-2 border-b border-arka-text-muted/10 px-5 py-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.14em] text-arka-primary">Portada · últimos 30 días</p>
                            <h3 class="mt-1 text-xl font-semibold text-arka-text">Interés en crear una cuenta</h3>
                            <p class="mt-1 text-sm text-arka-text-muted">Qué hicieron los visitantes reales después de ver el llamado.</p>
                        </div>
                        <span class="w-fit rounded-full border border-arka-primary/20 bg-arka-primary/10 px-3 py-1 text-xs font-semibold text-arka-primary-bright">
                            {{ landingCta.conversion_rate }}% de conversión
                        </span>
                    </div>

                    <div class="grid grid-cols-2 divide-x divide-y divide-arka-text-muted/10 sm:grid-cols-3 xl:grid-cols-6 xl:divide-y-0">
                        <div class="p-4 sm:p-5">
                            <p class="text-2xl font-bold text-arka-text">{{ landingCta.unique_visitors_30d }}</p>
                            <p class="mt-1 text-xs text-arka-text-muted">Vieron el llamado</p>
                        </div>
                        <div class="p-4 sm:p-5">
                            <p class="text-2xl font-bold text-arka-primary-bright">{{ landingCta.unique_clicks_30d }}</p>
                            <p class="mt-1 text-xs text-arka-text-muted">Crear mi cuenta</p>
                        </div>
                        <div class="p-4 sm:p-5">
                            <p class="text-2xl font-bold text-arka-text">{{ landingCta.unique_login_intents_30d }}</p>
                            <p class="mt-1 text-xs text-arka-text-muted">Ya tengo cuenta</p>
                        </div>
                        <div class="p-4 sm:p-5">
                            <p class="text-2xl font-bold text-arka-text">{{ landingCta.unique_dismissals_30d }}</p>
                            <p class="mt-1 text-xs text-arka-text-muted">Cerraron el llamado</p>
                        </div>
                        <div class="p-4 sm:p-5">
                            <p class="text-2xl font-bold text-arka-text">{{ landingCta.clicks_7d }}</p>
                            <p class="mt-1 text-xs text-arka-text-muted">Registros en 7 días</p>
                        </div>
                        <div class="p-4 sm:p-5">
                            <p class="text-2xl font-bold text-arka-text">{{ landingCta.clicks_today }}</p>
                            <p class="mt-1 text-xs text-arka-text-muted">Registros hoy</p>
                        </div>
                    </div>

                    <div class="border-t border-arka-text-muted/10 px-5 pb-5 pt-4">
                        <div class="flex h-28 items-end gap-1.5" aria-label="Alcance y clics diarios de los últimos 14 días">
                            <div v-for="day in landingCta.daily" :key="day.date" class="group flex min-w-0 flex-1 flex-col items-center justify-end gap-1">
                                <div class="relative flex h-20 w-full items-end justify-center rounded-t bg-arka-base/40">
                                    <div class="w-full rounded-t bg-arka-primary/25 transition group-hover:bg-arka-primary/35" :style="{ height: `${Math.max((day.impressions / landingCta.max_daily) * 100, day.impressions ? 8 : 0)}%` }"></div>
                                    <div class="absolute bottom-0 w-1/3 rounded-t bg-arka-primary" :style="{ height: `${Math.max((day.clicks / landingCta.max_daily) * 100, day.clicks ? 8 : 0)}%` }"></div>
                                </div>
                                <span class="hidden text-[9px] text-arka-text-muted sm:block">{{ day.label }}</span>
                            </div>
                        </div>
                        <div class="mt-3 flex items-center gap-4 text-[11px] text-arka-text-muted">
                            <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-sm bg-arka-primary/30"></i>Vieron el CTA</span>
                            <span class="flex items-center gap-1.5"><i class="h-2 w-2 rounded-sm bg-arka-primary"></i>Hicieron clic</span>
                        </div>
                    </div>
                </section>

                <!-- Ingreso mensual recurrente estimado -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <p class="text-sm text-arka-text-muted">Ingreso mensual recurrente estimado (MRR)</p>
                    <p class="text-3xl font-semibold text-arka-primary-bright">${{ estimatedMrr.toFixed(2) }}</p>
                    <p class="mt-1 text-xs text-arka-text-muted">
                        Suma de todas las suscripciones activas o en gracia según su precio mensual (sección 7.5:
                        activación manual, no hay cobro automático real).
                    </p>
                </div>

                <!-- Distribución por plan: conductor -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-4">Planes de conductor</h3>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-arka-text-muted">
                                <th class="pb-2">Plan</th>
                                <th class="pb-2">Suscriptores</th>
                                <th class="pb-2">Precio</th>
                                <th class="pb-2">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-arka-text-muted/10">
                            <tr v-for="plan in driverPlans" :key="plan.code">
                                <td class="py-2 text-arka-text">{{ plan.name }}</td>
                                <td class="py-2 text-arka-text">{{ plan.subscriber_count }}</td>
                                <td class="py-2 text-arka-text-muted">${{ plan.monthly_price.toFixed(2) }}</td>
                                <td class="py-2 text-arka-text-muted">${{ plan.monthly_total.toFixed(2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Distribución por plan: cliente -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-4">Planes de cliente</h3>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-arka-text-muted">
                                <th class="pb-2">Plan</th>
                                <th class="pb-2">Suscriptores</th>
                                <th class="pb-2">Precio</th>
                                <th class="pb-2">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-arka-text-muted/10">
                            <tr v-for="plan in clientPlans" :key="plan.code">
                                <td class="py-2 text-arka-text">{{ plan.name }}</td>
                                <td class="py-2 text-arka-text">{{ plan.subscriber_count }}</td>
                                <td class="py-2 text-arka-text-muted">${{ plan.monthly_price.toFixed(2) }}</td>
                                <td class="py-2 text-arka-text-muted">${{ plan.monthly_total.toFixed(2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Distribución por plan: cooperativa (pedido explícito del
                     usuario: "no tengo indicadores de las cooperativas") -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-4">Planes de cooperativa</h3>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-arka-text-muted">
                                <th class="pb-2">Plan</th>
                                <th class="pb-2">Suscriptores</th>
                                <th class="pb-2">Precio</th>
                                <th class="pb-2">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-arka-text-muted/10">
                            <tr v-for="plan in cooperativePlans" :key="plan.code">
                                <td class="py-2 text-arka-text">{{ plan.name }}</td>
                                <td class="py-2 text-arka-text">{{ plan.subscriber_count }}</td>
                                <td class="py-2 text-arka-text-muted">${{ plan.monthly_price.toFixed(2) }}</td>
                                <td class="py-2 text-arka-text-muted">${{ plan.monthly_total.toFixed(2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
