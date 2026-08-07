<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SubscriptionRequestPanel from '@/Components/SubscriptionRequestPanel.vue';
import SubscriptionRequestHistory from '@/Components/SubscriptionRequestHistory.vue';
import { Head, router } from '@inertiajs/vue3';

const props = defineProps({
    plans: { type: Array, required: true },
    currentPlan: { type: Object, required: true },
    usedClients: { type: Number, required: true },
    changes: { type: Array, required: true },
    pendingRequest: { type: Object, default: null },
    requestHistory: { type: Array, required: true },
});

function selectPlan(plan) {
    router.post(route('subscription-requests.store'), { subscription_plan_id: plan.id });
}

// Mismo criterio que App\Services\SubscriptionPlanEligibility (backend): no
// tiene sentido mostrar "Elegir" activo para un plan que ya no te alcanza
// con los clientes de confianza que tenés ahora mismo.
function fitsCurrentUsage(plan) {
    return plan.max_clients === null || props.usedClients <= plan.max_clients;
}
</script>

<template>
    <Head title="Mi plan de conductor" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-arka-text leading-tight">Mi plan de conductor</h2>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Plan vigente y cupo usado -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium text-arka-text">Plan {{ currentPlan.plan_name }}</h3>
                        <span class="text-sm text-arka-text-muted">
                            {{ usedClients }} de {{ currentPlan.max_clients ?? '∞' }} clientes de confianza
                        </span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        <span
                            class="px-2 py-1 rounded-arka"
                            :class="currentPlan.public_visibility ? 'bg-arka-primary/10 text-arka-primary-bright' : 'bg-arka-text-muted/10 text-arka-text-muted'"
                        >
                            Directorio público
                        </span>
                        <span
                            class="px-2 py-1 rounded-arka"
                            :class="currentPlan.priority_listing ? 'bg-arka-primary/10 text-arka-primary-bright' : 'bg-arka-text-muted/10 text-arka-text-muted'"
                        >
                            Prioridad en el directorio
                        </span>
                        <span
                            class="px-2 py-1 rounded-arka"
                            :class="currentPlan.verified_badge ? 'bg-arka-primary/10 text-arka-primary-bright' : 'bg-arka-text-muted/10 text-arka-text-muted'"
                        >
                            Insignia de verificado
                        </span>
                    </div>
                </div>

                <!-- Pedido en curso (consideración agregada al alcance): "botón de
                     acción" para elegir un plan + subir comprobante de pago. -->
                <SubscriptionRequestPanel :pending-request="pendingRequest" />

                <!-- Suscripciones = progresión, nunca retroceso (sección 19 de las
                     directrices de arquitectura): el backend ya no manda planes de
                     nivel inferior al vigente, así que si la lista solo trae el
                     plan actual, es porque ya es el más alto disponible. -->
                <p v-if="plans.length === 1" class="text-sm text-arka-text-muted px-1">
                    Ya tiene el plan de mayor nivel disponible para conductores.
                </p>

                <!-- Catálogo (sección 7.2) -->
                <div class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <div
                        v-for="plan in plans"
                        :key="plan.code"
                        class="p-4 sm:p-6 flex items-center justify-between gap-4"
                        :class="{ 'bg-arka-primary/5': plan.code === currentPlan.plan_code }"
                    >
                        <div>
                            <p class="text-arka-text font-medium">
                                {{ plan.name }}
                                <span v-if="plan.code === currentPlan.plan_code" class="text-xs text-arka-primary-bright">(su plan actual)</span>
                            </p>
                            <p class="text-sm text-arka-text-muted">
                                Hasta {{ plan.max_clients ?? 'clientes a medida (convenio)' }}
                                {{ plan.max_clients ? 'clientes de confianza' : '' }}
                                <span v-if="plan.public_visibility"> · directorio público</span>
                                <span v-if="plan.priority_listing"> · prioridad</span>
                                <span v-if="plan.verified_badge"> · insignia de verificado</span>
                            </p>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="text-arka-text font-semibold">${{ plan.monthly_price }}/mes</p>
                            <PrimaryButton
                                v-if="plan.code !== currentPlan.plan_code && !pendingRequest && fitsCurrentUsage(plan)"
                                class="mt-1"
                                @click="selectPlan(plan)"
                            >
                                Elegir
                            </PrimaryButton>
                            <p
                                v-else-if="plan.code !== currentPlan.plan_code && !pendingRequest"
                                class="mt-1 text-xs text-arka-warning max-w-[10rem]"
                            >
                                No le alcanza con sus {{ usedClients }} clientes de confianza actuales
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Cómo activar (sección 7.5: transferencia + confirmación manual) -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka text-sm text-arka-text-muted">
                    <p>
                        Elija un plan de la lista, haga la transferencia por el monto correspondiente y suba el
                        comprobante. Un administrador lo revisa y activa su nuevo plan manualmente — todavía no hay
                        cobro automático en Arka01.
                    </p>
                </div>

                <!-- Historial de pagos (consideración agregada al alcance): aprobados,
                     rechazados y pendientes — no solo la activación final. -->
                <SubscriptionRequestHistory :request-history="requestHistory" />

                <!-- Historial de activaciones (sección 9.6) -->
                <div v-if="changes.length" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-4">Historial de activaciones</h3>
                    <ul class="divide-y divide-arka-text-muted/10">
                        <li v-for="change in changes" :key="change.id" class="py-2 text-sm text-arka-text-muted">
                            {{ change.old_plan?.name ?? 'Gratis' }} &rarr; {{ change.new_plan.name }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
