<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({ cooperative: { type: Object, required: true }, stats: { type: Object, required: true }, requests: { type: Array, required: true }, drivers: { type: Array, required: true } });
const selectedDrivers = reactive({});

function assign(request) {
    const driverUserId = selectedDrivers[request.id];
    if (!driverUserId) return;
    router.post(route('cooperative.rides.assign', request.id), { driver_user_id: driverUserId }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Panel de cooperativa" />
    <AuthenticatedLayout>
        <template #header><h2 class="text-xl font-semibold text-arka-text">{{ cooperative.name || 'Panel de cooperativa' }}</h2></template>
        <div class="py-8 sm:py-12">
            <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6">
                <div v-if="cooperative.status !== 'approved'" class="rounded-arka border border-arka-warning/30 bg-arka-warning/10 p-4 text-sm text-arka-warning">
                    La cooperativa aún no puede operar. Estado: <strong>{{ cooperative.status }}</strong>.
                    <Link :href="route('cooperative.profile.edit')" class="ml-1 underline">Revisar perfil y documentos</Link>
                </div>
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-6">
                    <div v-for="(value, key) in stats" :key="key" class="rounded-arka bg-arka-card p-4 shadow-lg">
                        <p class="text-xs uppercase text-arka-text-muted">{{ { drivers: 'Conductores', available: 'Disponibles', pendingDrivers: 'Por aceptar', pendingRequests: 'Solicitudes', scheduledRequests: 'Programadas', activeRequests: 'Activas' }[key] }}</p>
                        <p class="mt-2 text-2xl font-bold text-arka-text">{{ value }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-3">
                    <Link :href="route('cooperative.drivers.index')" class="rounded-full bg-arka-primary px-4 py-2 text-sm font-semibold text-arka-base">Administrar conductores</Link>
                    <Link :href="route('cooperative.profile.edit')" class="rounded-full border border-arka-text-muted/20 px-4 py-2 text-sm text-arka-text">Perfil y documentos</Link>
                    <Link :href="route('cooperatives.show', cooperative.id)" class="rounded-full border border-arka-text-muted/20 px-4 py-2 text-sm text-arka-text">Perfil público</Link>
                </div>
                <section class="rounded-arka bg-arka-card shadow-lg">
                    <div class="border-b border-arka-text-muted/10 p-5"><h3 class="font-semibold text-arka-text">Solicitudes pendientes</h3></div>
                    <p v-if="!requests.length" class="p-6 text-sm text-arka-text-muted">No hay solicitudes pendientes.</p>
                    <div v-else class="divide-y divide-arka-text-muted/10">
                        <div v-for="request in requests" :key="request.id" class="p-5">
                            <p class="font-medium text-arka-text">{{ request.client.name }}</p>
                            <p class="mt-1 text-sm text-arka-text-muted">{{ request.origin_address || 'Origen' }} → {{ request.destination_address || 'Destino' }}</p>
                            <p v-if="request.is_scheduled" class="mt-1 text-xs text-arka-warning">Programada: {{ new Date(request.scheduled_at).toLocaleString('es-EC') }}</p>
                            <p class="mt-1 text-xs text-arka-primary">
                                {{ request.status === 'accepted' ? `Aceptada por ${request.driver?.name || 'conductor'}` : request.driver ? `Esperando respuesta de ${request.driver.name}` : 'Pendiente de asignar conductor' }}
                            </p>
                            <div v-if="request.status === 'pending'" class="mt-3 flex flex-col gap-2 sm:flex-row">
                                <select v-model="selectedDrivers[request.id]" class="min-w-0 flex-1 rounded-arka border-arka-text-muted/20 bg-arka-base text-sm text-arka-text">
                                    <option value="">Seleccione una unidad disponible</option>
                                    <option v-for="driver in props.drivers" :key="driver.user_id" :value="driver.user_id" :disabled="!driver.available || !driver.verified">
                                        {{ driver.name }}{{ !driver.available ? ' · no disponible' : !driver.verified ? ' · sin verificar' : '' }}
                                    </option>
                                </select>
                                <button type="button" class="rounded-full bg-arka-primary px-4 py-2 text-sm font-semibold text-arka-base disabled:opacity-50" :disabled="!selectedDrivers[request.id]" @click="assign(request)">
                                    {{ request.driver ? 'Reasignar' : 'Asignar' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
