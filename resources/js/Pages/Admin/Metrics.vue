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
