<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    alerts: { type: Object, required: true },
});
</script>

<template>
    <Head title="Admin · Alertas SOS" />

    <AdminLayout title="Alertas SOS">
        <div class="py-12">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Registro de cada vez que alguien activó el botón de emergencia durante un viaje (sección 8).
                    Solo auditoría — no hay ninguna acción para tomar acá.
                </p>

                <p v-if="!alerts.data.length" class="text-sm text-arka-text-muted">Todavía no hay alertas registradas.</p>

                <ul v-else class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <li v-for="alert in alerts.data" :key="alert.id" class="p-4 sm:p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-arka-text font-medium">
                                    {{ alert.triggered_by.name }}
                                    <span class="text-xs text-arka-text-muted">activó SOS</span>
                                </p>
                                <p class="text-sm text-arka-text-muted">
                                    Conductor: {{ alert.driver_name }}
                                    <span v-if="alert.vehicle_plate"> · Placa {{ alert.vehicle_plate }}</span>
                                </p>
                                <p class="text-xs text-arka-text-muted">
                                    {{ new Date(alert.created_at).toLocaleString() }} ·
                                    {{ alert.notified_contacts_count }} contacto(s) notificado(s)
                                </p>
                            </div>
                            <Link
                                v-if="alert.ride"
                                :href="route('rides.show', alert.ride_id)"
                                class="text-sm text-arka-primary hover:text-arka-primary-bright shrink-0"
                            >
                                Ver carrera
                            </Link>
                        </div>
                    </li>
                </ul>

                <div v-if="alerts.prev_page_url || alerts.next_page_url" class="flex justify-between">
                    <Link v-if="alerts.prev_page_url" :href="alerts.prev_page_url" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                        &larr; Anterior
                    </Link>
                    <span v-else></span>
                    <Link v-if="alerts.next_page_url" :href="alerts.next_page_url" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                        Siguiente &rarr;
                    </Link>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
