<script setup>
import { reactive } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { confirmDialog } from '@/Utils/confirmDialog';

const props = defineProps({
    rides: { type: Object, required: true },
    filters: { type: Object, required: true },
});

// Pedido explícito del usuario: "eliminar carrera desde el panel para poder
// depurar esas de prueba, así mismo las programadas y las de Expresos" —
// mismo criterio de filtros que el resto del admin (Admin/Clients.vue,
// Admin/Drivers.vue): buscar + filtrar por estado, sin traer todo de una.
const form = reactive({
    q: props.filters.q ?? '',
    status: props.filters.status ?? '',
});

function applyFilters() {
    router.get(route('admin.rides.index'), form, { preserveState: true, preserveScroll: true, replace: true });
}

const STATUS_LABEL = {
    scheduled: 'Programada',
    in_progress: 'En curso',
    completed: 'Completada',
    cancelled: 'Cancelada',
};

const STATUS_CLASS = {
    scheduled: 'text-arka-lime',
    in_progress: 'text-arka-primary-bright',
    completed: 'text-arka-text-muted',
    cancelled: 'text-arka-danger',
};

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('es-EC', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

// Borrado en cascada (ver Admin\RideController::destroy()): se lleva
// reseñas, incidentes de Expreso, alertas SOS, mensajes del chat y la
// solicitud que la originó; recalcula puntos del conductor. Irreversible,
// por eso el diálogo marcado como "danger" antes de disparar.
async function destroyRide(ride) {
    const confirmed = await confirmDialog(
        `¿Eliminar la carrera #${ride.id} (${ride.client_name} · ${ride.driver_name})? Se borran también sus reseñas, y se recalculan puntos y conteos. No se puede deshacer.`,
        { danger: true, confirmLabel: 'Eliminar' }
    );
    if (!confirmed) return;

    router.delete(route('admin.rides.destroy', ride.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · Carreras" />

    <AdminLayout title="Carreras">
        <div class="py-12">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <div>
                        <h3 class="text-lg font-medium text-arka-text">Carreras ({{ rides.total }})</h3>
                        <p class="text-sm text-arka-text-muted">
                            Para depurar carreras de prueba — sueltas, programadas o de Expreso. Al eliminar una, se borran también sus
                            reseñas y se recalculan puntos y conteos del conductor y del cliente.
                        </p>
                    </div>

                    <form @submit.prevent="applyFilters" class="flex flex-wrap items-end gap-3">
                        <div class="flex-1 min-w-[160px]">
                            <TextInput v-model="form.q" placeholder="Nombre o teléfono de cliente/conductor" class="w-full" />
                        </div>
                        <select v-model="form.status" class="rounded-arka border-arka-text-muted/30 bg-arka-card text-arka-text text-sm">
                            <option value="">Todos los estados</option>
                            <option v-for="(label, value) in STATUS_LABEL" :key="value" :value="value">{{ label }}</option>
                        </select>
                        <PrimaryButton type="submit">Filtrar</PrimaryButton>
                    </form>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-arka-text-muted border-b border-arka-text-muted/10">
                                    <th class="py-2 pr-3">#</th>
                                    <th class="py-2 pr-3">Cliente</th>
                                    <th class="py-2 pr-3">Conductor</th>
                                    <th class="py-2 pr-3">Recorrido</th>
                                    <th class="py-2 pr-3">Estado</th>
                                    <th class="py-2 pr-3">Precio</th>
                                    <th class="py-2 pr-3">Creada</th>
                                    <th class="py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-arka-text-muted/10">
                                <tr
                                    v-for="ride in rides.data"
                                    :key="ride.id"
                                    class="cursor-pointer hover:bg-arka-primary/5"
                                    @click="router.visit(route('admin.rides.show', ride.id))"
                                >
                                    <td class="py-2 pr-3 text-arka-text-muted">{{ ride.id }}</td>
                                    <td class="py-2 pr-3 text-arka-text">{{ ride.client_name }}</td>
                                    <td class="py-2 pr-3 text-arka-text">{{ ride.driver_name }}</td>
                                    <td class="py-2 pr-3 text-arka-text-muted max-w-[220px] truncate" :title="`${ride.origin_address ?? '—'} → ${ride.destination_address ?? '—'}`">
                                        {{ ride.origin_address ?? '—' }} → {{ ride.destination_address ?? '—' }}
                                    </td>
                                    <td class="py-2 pr-3 font-medium" :class="STATUS_CLASS[ride.status]">{{ STATUS_LABEL[ride.status] ?? ride.status }}</td>
                                    <td class="py-2 pr-3 text-arka-text">${{ ride.price.toFixed(2) }}</td>
                                    <td class="py-2 pr-3 text-arka-text-muted">{{ formatDate(ride.created_at) }}</td>
                                    <td class="py-2">
                                        <DangerButton size="sm" @click.stop="destroyRide(ride)">Eliminar</DangerButton>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <p v-if="!rides.data.length" class="py-6 text-center text-sm text-arka-text-muted">
                            No hay carreras que coincidan con estos filtros.
                        </p>
                    </div>

                    <!-- Paginado simple: nunca se carga todo de una vez (sección 9.7) -->
                    <div v-if="rides.prev_page_url || rides.next_page_url" class="flex justify-between pt-2">
                        <Link
                            v-if="rides.prev_page_url"
                            :href="rides.prev_page_url"
                            preserve-state
                            class="text-sm text-arka-primary hover:text-arka-primary-bright"
                        >
                            &larr; Anterior
                        </Link>
                        <span v-else></span>

                        <Link
                            v-if="rides.next_page_url"
                            :href="rides.next_page_url"
                            preserve-state
                            class="text-sm text-arka-primary hover:text-arka-primary-bright"
                        >
                            Siguiente &rarr;
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
