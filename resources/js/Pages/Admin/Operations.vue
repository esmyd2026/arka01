<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FleetMap from '@/Components/FleetMap.vue';
import { Head, router } from '@inertiajs/vue3';

const props = defineProps({
    activeDemand: { type: Array, required: true },
    activeSectors: { type: Array, required: true },
    connected: { type: Object, required: true },
    demandByHour: { type: Array, required: true },
    demandByZone: { type: Array, required: true },
    waitTime: { type: Object, required: true },
});

// Para resaltar de un vistazo a qué horas se concentra más la demanda
// (pedido explícito del usuario) — sin librería de gráficos, una barra
// simple con ancho proporcional alcanza para esto.
const maxHourlyDemand = Math.max(1, ...props.demandByHour);

function notifyNearby(sectorId) {
    router.post(route('admin.operations.notify-demand'), { sector_id: sectorId }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · Operaciones" />

    <AdminLayout title="Centro de operaciones">
        <div class="py-12">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Conectados ahora (pedido explícito del usuario) -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                        <p class="text-sm text-arka-text-muted">Clientes conectados</p>
                        <p class="text-2xl font-semibold text-arka-text">{{ connected.clients }}</p>
                        <p class="text-xs text-arka-text-muted">Activos en los últimos {{ connected.window_minutes }} min.</p>
                    </div>
                    <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                        <p class="text-sm text-arka-text-muted">Conductores conectados</p>
                        <p class="text-2xl font-semibold text-arka-text">{{ connected.drivers }}</p>
                        <p class="text-xs text-arka-text-muted">Activos en los últimos {{ connected.window_minutes }} min.</p>
                    </div>
                </div>

                <!-- Tiempo de espera y cancelaciones (pedido explícito del usuario) -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-3">Tiempo de espera y cancelaciones</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                        <div>
                            <p class="text-arka-text-muted">Espera promedio</p>
                            <p class="text-arka-text font-medium">{{ waitTime.avg_wait_minutes != null ? `${waitTime.avg_wait_minutes} min` : '—' }}</p>
                        </div>
                        <div>
                            <p class="text-arka-text-muted">Nadie respondió</p>
                            <p class="text-arka-text font-medium">{{ waitTime.expired_count }}</p>
                        </div>
                        <div>
                            <p class="text-arka-text-muted">Canceladas en camino</p>
                            <p class="text-arka-danger font-medium">{{ waitTime.cancelled_while_en_route_count }}</p>
                        </div>
                        <div>
                            <p class="text-arka-text-muted">Canceladas antes de salir</p>
                            <p class="text-arka-text font-medium">{{ waitTime.cancelled_before_pickup_count }}</p>
                        </div>
                    </div>
                </div>

                <!-- Dónde se concentran las solicitudes activas (pedido explícito del usuario) -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <h3 class="text-lg font-medium text-arka-text">Solicitudes activas ahora ({{ activeDemand.length }})</h3>

                    <p v-if="!activeDemand.length" class="text-sm text-arka-text-muted">No hay solicitudes activas en este momento.</p>

                    <FleetMap
                        v-else
                        :markers="activeDemand.map((r) => ({ id: r.id, lat: r.lat, lng: r.lng, label: r.client_name }))"
                        height="360px"
                    />

                    <!-- Avisar a conductores cercanos (pedido explícito del usuario) -->
                    <div v-if="activeSectors.length" class="pt-2 space-y-2">
                        <p class="text-sm text-arka-text-muted">Avisar a conductores cercanos de una zona con demanda:</p>
                        <div v-for="s in activeSectors" :key="s.sector_id" class="flex items-center justify-between gap-3 py-1">
                            <span class="text-arka-text">{{ s.sector_name }} — {{ s.total }} solicitud(es)</span>
                            <PrimaryButton @click="notifyNearby(s.sector_id)">Avisar cerca</PrimaryButton>
                        </div>
                    </div>
                </div>

                <!-- Demanda histórica por horario (pedido explícito del usuario) -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-2">
                    <h3 class="text-lg font-medium text-arka-text mb-2">Demanda histórica por horario</h3>
                    <div v-for="(total, hour) in demandByHour" :key="hour" class="flex items-center gap-3 text-sm">
                        <span class="w-12 text-arka-text-muted shrink-0">{{ String(hour).padStart(2, '0') }}:00</span>
                        <div class="flex-1 bg-arka-base/60 rounded-full h-3 overflow-hidden">
                            <div class="h-full bg-arka-primary rounded-full" :style="{ width: `${(total / maxHourlyDemand) * 100}%` }" />
                        </div>
                        <span class="w-8 text-right text-arka-text">{{ total }}</span>
                    </div>
                </div>

                <!-- Demanda histórica por zona (pedido explícito del usuario) -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-3">Demanda histórica por zona</h3>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-arka-text-muted/10">
                            <tr v-for="z in demandByZone" :key="z.sector">
                                <td class="py-2 text-arka-text">{{ z.sector }}</td>
                                <td class="py-2 text-right text-arka-text-muted">{{ z.total }} solicitud(es)</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
