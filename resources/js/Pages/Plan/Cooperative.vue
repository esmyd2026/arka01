<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SubscriptionRequestPanel from '@/Components/SubscriptionRequestPanel.vue';
import SubscriptionRequestHistory from '@/Components/SubscriptionRequestHistory.vue';
import { Head, router } from '@inertiajs/vue3';

// "Mi plan" de cooperativa (pedido explícito del usuario: "dame los
// beneficios de cada plan y muéstralo en los planes de cada cooperativa") —
// mismo patrón que Plan/Client.vue, sin proyección de ganancias (no aplica:
// la cooperativa no "gana" por su propio plan).
const props = defineProps({
    plans: { type: Array, required: true },
    currentPlan: { type: Object, required: true },
    usedUnits: { type: Number, required: true },
    changes: { type: Array, required: true },
    pendingRequest: { type: Object, default: null },
    requestHistory: { type: Array, required: true },
    whatsappBusinessNumber: { type: String, default: null },
});

// Beneficios por plan (pedido explícito del usuario) — texto fijo por
// código, igual criterio que RideController::CLIENT_CANCEL_REASONS: no
// justifica un catálogo administrable para 3 planes curados a mano.
const BENEFITS = {
    gratis: [
        'Hasta 5 conductores afiliados',
        'Panel básico de gestión y visibilidad de su flota',
        'Sin descuento cruzado para sus conductores',
    ],
    basico: [
        'Hasta 10 conductores afiliados',
        'Panel completo de gestión y despacho',
        'Sus conductores acceden a 10% de descuento en su propio plan individual mientras estén afiliados',
    ],
    profesional: [
        'Hasta 50 conductores afiliados',
        'Panel completo de gestión y despacho + soporte prioritario',
        'Sus conductores acceden a 20% de descuento en su propio plan individual mientras estén afiliados',
    ],
};

function selectPlan(plan) {
    router.post(route('subscription-requests.store'), {
        subscription_plan_id: plan.id,
        plan_promotion_id: plan.active_promotion?.id ?? null,
    });
}

function formatDate(value) {
    return new Date(value).toLocaleDateString('es-EC', { day: 'numeric', month: 'short', year: 'numeric' });
}

// Mismo criterio que App\Services\SubscriptionPlanEligibility (backend): no
// tiene sentido mostrar "Elegir" activo para un plan que ya no alcanza con
// los conductores afiliados que ya tiene.
function fitsCurrentUsage(plan) {
    return plan.max_units === null || props.usedUnits <= plan.max_units;
}

// Tarjeta "Hablemos" (pedido explícito del usuario: "una opción de
// negociación" en vez de un tramo de precio fijo "sin límite") — mismo
// patrón wa.me/?text= que el resto de la app, sin número apunta a nada.
const talkToUsUrl = props.whatsappBusinessNumber
    ? `https://wa.me/${props.whatsappBusinessNumber}?text=${encodeURIComponent('Hola, somos una cooperativa y necesitamos más de 50 unidades en Arka01. Nos gustaría conversar un plan a medida.')}`
    : null;
</script>

<template>
    <Head title="Mi plan de cooperativa" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-arka-text leading-tight">Mi plan de cooperativa</h2>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Plan vigente y cupo usado -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium text-arka-text">Plan {{ currentPlan.plan_name }}</h3>
                        <span class="text-sm text-arka-text-muted">
                            {{ usedUnits }} de {{ currentPlan.max_units ?? '∞' }} unidades
                        </span>
                    </div>
                    <p v-if="currentPlan.driver_discount_percent > 0" class="mt-2 text-sm text-arka-text-muted">
                        Sus conductores afiliados acceden a {{ currentPlan.driver_discount_percent }}% de descuento en su propio plan individual.
                    </p>
                </div>

                <!-- Pedido en curso (consideración agregada al alcance): "botón de
                     acción" para elegir un plan + subir comprobante de pago. -->
                <SubscriptionRequestPanel :pending-request="pendingRequest" />

                <!-- Suscripciones = progresión, nunca retroceso (sección 19 de las
                     directrices de arquitectura): el backend ya no manda planes de
                     nivel inferior al vigente, así que si la lista solo trae el
                     plan actual, es porque ya es el más alto disponible. -->
                <p v-if="plans.length === 1" class="text-sm text-arka-text-muted px-1">
                    Ya tiene el plan de mayor nivel disponible para cooperativas.
                </p>

                <!-- Catálogo -->
                <div class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <div
                        v-for="plan in plans"
                        :key="plan.code"
                        class="p-4 sm:p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4"
                        :class="{ 'bg-arka-primary/5': plan.code === currentPlan.plan_code }"
                    >
                        <div>
                            <p class="text-arka-text font-medium">
                                {{ plan.name }}
                                <span v-if="plan.code === currentPlan.plan_code" class="text-xs text-arka-primary-bright">(su plan actual)</span>
                            </p>

                            <ul class="mt-1 space-y-0.5">
                                <li v-for="benefit in BENEFITS[plan.code] ?? []" :key="benefit" class="text-sm text-arka-text-muted flex items-start gap-1.5">
                                    <span class="text-arka-primary mt-0.5">✓</span>
                                    <span>{{ benefit }}</span>
                                </li>
                            </ul>

                            <!-- Promoción vigente (pedido explícito del usuario: "pagá tanto
                                 y ahorrá tanto... después de tal fecha pagarías el valor
                                 real") — ya viene resuelta y validada desde el backend. -->
                            <div v-if="plan.active_promotion" class="mt-2 p-2 rounded-arka bg-arka-lime/10 border border-arka-lime/30 max-w-sm">
                                <p class="text-xs text-arka-lime font-medium">
                                    🎁 {{ plan.active_promotion.label }}: pague ${{ plan.active_promotion.promo_price.toFixed(2) }}/mes y ahorre
                                    ${{ plan.active_promotion.savings.toFixed(2) }}/mes.
                                </p>
                                <p v-if="plan.active_promotion.ends_at" class="text-xs text-arka-text-muted mt-0.5">
                                    Válido hasta {{ formatDate(plan.active_promotion.ends_at) }} — después pagaría ${{ plan.monthly_price }}/mes.
                                </p>
                            </div>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="text-arka-text font-semibold">
                                <span v-if="plan.active_promotion" class="text-xs text-arka-text-muted line-through mr-1">
                                    ${{ plan.monthly_price }}
                                </span>
                                ${{ plan.active_promotion ? plan.active_promotion.promo_price.toFixed(2) : plan.monthly_price }}/mes
                            </p>
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
                                No le alcanza con sus {{ usedUnits }} unidades actuales
                            </p>
                        </div>
                    </div>

                    <!-- Tarjeta "Hablemos" (pedido explícito del usuario): en vez de un
                         tramo de precio fijo "sin límite", cooperativas más grandes se
                         negocian caso por caso — no es una fila de subscription_plans. -->
                    <div class="p-4 sm:p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div>
                            <p class="text-arka-text font-medium">¿Más de 50 unidades?</p>
                            <p class="text-sm text-arka-text-muted">Conversemos un plan a medida para su cooperativa.</p>
                        </div>
                        <a
                            v-if="talkToUsUrl"
                            :href="talkToUsUrl"
                            target="_blank"
                            rel="noopener"
                            class="shrink-0 inline-flex items-center justify-center rounded-arka border border-arka-primary px-4 py-2 text-sm font-semibold text-arka-primary hover:bg-arka-primary/10 transition"
                        >
                            Hablemos por WhatsApp
                        </a>
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
