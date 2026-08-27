<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { confirmDialog } from '@/Utils/confirmDialog';

// Pedido explícito del usuario: "quiero ver mis clientes vinculados el
// detalle no tanto pero al menos la lista, cantidad de carreras,
// puntuaccion y desvincular también" — clientes que agregaron esta
// cooperativa a su red (App\Models\ClientCooperative), no los que solo
// pidieron una carrera puntual (ver App\Http\Controllers\CooperativeClientController).
defineProps({
    cooperative: { type: Object, required: true },
    clients: { type: Array, required: true },
});

function formatDate(value) {
    return new Date(value).toLocaleDateString('es-EC', { day: 'numeric', month: 'short', year: 'numeric' });
}

async function unlink(client) {
    if (!(await confirmDialog(`¿Desvincular a ${client.name}? Puede volver a agregar a la cooperativa a su red cuando quiera — esto no borra su historial de carreras.`, { danger: true, confirmLabel: 'Desvincular' }))) return;
    router.delete(route('cooperative.clients.destroy', client.link_id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Clientes de la cooperativa" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <Link :href="route('cooperative.dashboard')" class="text-sm text-arka-primary hover:text-arka-primary-bright">&larr; Panel</Link>
                <h2 class="mt-1 text-xl font-semibold text-arka-text">Clientes vinculados a {{ cooperative.name }}</h2>
            </div>
        </template>

        <div class="py-8 sm:py-12">
            <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6">
                <p class="text-sm text-arka-text-muted">
                    Clientes que agregaron a {{ cooperative.name }} a su red de confianza — pueden pedirle carreras
                    directo a la cooperativa, no solo a través de una flota propia.
                </p>

                <div class="rounded-arka bg-arka-card shadow-lg">
                    <p v-if="!clients.length" class="p-6 text-sm text-arka-text-muted">
                        Todavía no hay clientes vinculados a esta cooperativa.
                    </p>

                    <div v-else class="divide-y divide-arka-text-muted/10">
                        <div v-for="client in clients" :key="client.link_id" class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center">
                            <UserAvatar :user="client" size-class="h-11 w-11 text-sm shrink-0" />
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-arka-text truncate">
                                    {{ client.name }}
                                    <span v-if="client.member_code" class="text-xs text-arka-text-muted">#{{ client.member_code }}</span>
                                </p>
                                <p class="mt-1 text-xs text-arka-text-muted">
                                    {{ client.completed_rides }} carrera{{ client.completed_rides === 1 ? '' : 's' }} completada{{ client.completed_rides === 1 ? '' : 's' }}
                                    <span v-if="client.cancelled_rides"> · {{ client.cancelled_rides }} cancelada{{ client.cancelled_rides === 1 ? '' : 's' }}</span>
                                    <span v-if="client.review_count"> · ★ {{ client.average_rating }} ({{ client.review_count }})</span>
                                    <span v-else> · sin calificaciones todavía</span>
                                    · vinculado desde {{ formatDate(client.linked_at) }}
                                </p>
                            </div>
                            <DangerButton class="shrink-0" @click="unlink(client)">Desvincular</DangerButton>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
