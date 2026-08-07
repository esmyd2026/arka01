<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FleetMap from '@/Components/FleetMap.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, router } from '@inertiajs/vue3';
import { confirmDialog } from '@/Utils/confirmDialog';
import { tierLabel } from '@/Utils/tierBadge';

defineProps({
    availableDrivers: { type: Array, required: true },
    allDrivers: { type: Array, required: true },
});

// "Que cuando no estén disponibles desaparezcan" (pedido explícito del
// usuario): no hace falta WebSocket para un panel de admin — se refresca
// solo cada 20 seg., suficiente para algo que no necesita latencia de
// segundos como sí la necesita el conductor pidiendo/recibiendo una carrera.
let poll = null;
onMounted(() => {
    poll = setInterval(() => {
        router.reload({ only: ['availableDrivers'], preserveScroll: true, preserveState: true });
    }, 20000);
});
onBeforeUnmount(() => clearInterval(poll));

async function suspend(driver) {
    if (!(await confirmDialog(`¿Suspender a ${driver.name}? No va a poder conectarse ni recibir carreras hasta que lo reactives.`, { danger: true, confirmLabel: 'Suspender' }))) return;
    router.post(route('admin.drivers.suspend', driver.id), {}, { preserveScroll: true });
}

function reactivate(driver) {
    router.post(route('admin.drivers.reactivate', driver.id), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · Conductores" />

    <AdminLayout title="Conductores">
        <div class="py-12">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Disponibles ahora, en el mapa (pedido explícito del usuario) -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <h3 class="text-lg font-medium text-arka-text">Disponibles ahora ({{ availableDrivers.length }})</h3>

                    <p v-if="!availableDrivers.length" class="text-sm text-arka-text-muted">
                        Ningún conductor está disponible en este momento.
                    </p>

                    <!-- Ícono de auto (pedido explícito del usuario), no el pin
                         celeste genérico de Leaflet — ver Components/FleetMap.vue
                         (ICONS.car). -->
                    <FleetMap
                        v-else
                        :markers="availableDrivers.map((d) => ({ id: 'car', lat: d.lat, lng: d.lng, label: d.name }))"
                        height="360px"
                    />

                    <ul v-if="availableDrivers.length" class="divide-y divide-arka-text-muted/10">
                        <li v-for="d in availableDrivers" :key="d.user_id" class="py-2 flex items-center justify-between gap-3">
                            <span class="text-arka-text">{{ d.name }}</span>
                            <span class="text-sm text-arka-text-muted flex items-center gap-2">
                                <span>{{ tierLabel(d.tier) }}</span>
                                <span>★ {{ d.average_rating.toFixed(1) }}</span>
                            </span>
                        </li>
                    </ul>
                </div>

                <!-- Roster completo: bloquear/deshabilitar/desconectar (pedido
                     explícito del usuario) -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <h3 class="text-lg font-medium text-arka-text">Todos los conductores ({{ allDrivers.length }})</h3>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-arka-text-muted border-b border-arka-text-muted/10">
                                    <th class="py-2 pr-3">Conductor</th>
                                    <th class="py-2 pr-3">Estado</th>
                                    <th class="py-2 pr-3">Carreras</th>
                                    <th class="py-2 pr-3">Rechazos</th>
                                    <th class="py-2 pr-3">Verificación</th>
                                    <th class="py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-arka-text-muted/10">
                                <tr v-for="d in allDrivers" :key="d.user_id">
                                    <td class="py-2 pr-3">
                                        <p class="text-arka-text font-medium">{{ d.name }}</p>
                                        <p class="text-xs text-arka-text-muted">{{ d.email }}</p>
                                    </td>
                                    <td class="py-2 pr-3">
                                        <span v-if="d.is_suspended" class="text-arka-danger font-medium">Suspendido</span>
                                        <span v-else-if="d.is_available" class="text-arka-primary-bright font-medium">Disponible</span>
                                        <span v-else class="text-arka-text-muted">Desconectado</span>
                                    </td>
                                    <td class="py-2 pr-3 text-arka-text">{{ d.completed_rides_count }}</td>
                                    <td class="py-2 pr-3 text-arka-text">{{ d.rides_rejected_count }}</td>
                                    <td class="py-2 pr-3 text-arka-text-muted">{{ d.verification_status }}</td>
                                    <td class="py-2">
                                        <DangerButton v-if="!d.is_suspended" @click="suspend(d)">Suspender</DangerButton>
                                        <PrimaryButton v-else @click="reactivate(d)">Reactivar</PrimaryButton>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
