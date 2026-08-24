<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';

// Panel de indicadores de la encuesta corta de conductor/pasajero (pedido
// explícito del usuario: "indicadores que me ayuden a determinar decisiones
// y fortalecer que estoy cubriendo un problema") — mismo estilo que
// Admin/Metrics.vue (tarjetas + tablas simples, sin librería de gráficos).
defineProps({
    roles: { type: Object, required: true }, // { pasajero: {...}, conductor: {...} }
});

const ROLE_LABELS = { pasajero: 'Pasajeros', conductor: 'Conductores' };
</script>

<template>
    <Head title="Admin · Encuestas" />

    <AdminLayout title="Encuestas">
        <div class="py-12">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Totales de un vistazo. -->
                <div class="grid grid-cols-2 gap-4">
                    <div v-for="(role, key) in roles" :key="key" class="p-4 bg-arka-card shadow rounded-arka text-center">
                        <p class="text-2xl font-semibold text-arka-text">{{ role.total }}</p>
                        <p class="text-xs text-arka-text-muted">Respuestas de {{ ROLE_LABELS[key] }}</p>
                    </div>
                </div>

                <template v-for="(role, key) in roles" :key="key">
                    <div v-if="role.total > 0" class="space-y-4">
                        <h2 class="text-lg font-semibold text-arka-text pt-2">{{ ROLE_LABELS[key] }}</h2>

                        <!-- El indicador más accionable: el problema que más se repite. -->
                        <div v-if="role.mainProblem" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                            <p class="text-sm text-arka-text-muted">Problema #1 reportado</p>
                            <p class="text-2xl font-semibold text-arka-primary-bright">{{ role.mainProblem.label }}</p>
                            <p class="mt-1 text-xs text-arka-text-muted">
                                {{ role.mainProblem.count }} de {{ role.total }} respuestas ({{ role.mainProblem.percent }}%)
                            </p>
                        </div>

                        <!-- Inseguridad general del país y de noche en particular: los dos ejes que pidió el usuario para justificar decisiones de producto sobre seguridad. -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                                <p class="text-sm text-arka-text-muted">Sienten insegura la situación del país hoy</p>
                                <p class="text-2xl font-semibold text-arka-primary-bright">{{ role.insecurityPerception.percent }}%</p>
                                <p class="mt-1 text-xs text-arka-text-muted">{{ role.insecurityPerception.count }} de {{ role.total }} respondieron "alta" o "muy alta"</p>
                            </div>
                            <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                                <p class="text-sm text-arka-text-muted">No se sienten seguros de noche</p>
                                <p class="text-2xl font-semibold text-arka-primary-bright">{{ role.nightSafety.percent }}%</p>
                                <p class="mt-1 text-xs text-arka-text-muted">{{ role.nightSafety.count }} de {{ role.total }} sienten inseguridad o evitan viajar/trabajar de noche</p>
                            </div>
                        </div>

                        <!-- Desglose completo, pregunta por pregunta. -->
                        <div v-for="question in role.questions" :key="question.key" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                            <h3 class="text-sm font-medium text-arka-text mb-1">{{ question.text }}</h3>
                            <p v-if="question.multi" class="text-xs text-arka-text-muted mb-2">Podía elegir varias opciones — los % no suman 100%.</p>
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-arka-text-muted">
                                        <th class="pb-2">Respuesta</th>
                                        <th class="pb-2">Cantidad</th>
                                        <th class="pb-2">%</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-arka-text-muted/10">
                                    <tr v-for="option in question.options" :key="option.key">
                                        <td class="py-2 text-arka-text">{{ option.label }}</td>
                                        <td class="py-2 text-arka-text-muted">{{ option.count }}</td>
                                        <td class="py-2 text-arka-text-muted">{{ option.percent }}%</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <p v-else class="text-sm text-arka-text-muted">Todavía no hay respuestas de {{ ROLE_LABELS[key] }}.</p>
                </template>
            </div>
        </div>
    </AdminLayout>
</template>
