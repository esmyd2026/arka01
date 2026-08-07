<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import UserAvatar from '@/Components/UserAvatar.vue';
import { confirmDialog } from '@/Utils/confirmDialog';

const props = defineProps({
    pendingInvitations: { type: Array, required: true },
    activeMemberships: { type: Array, required: true },
    // null = sin límite (plan Institucional sin cupo pactado).
    maxClients: { type: Number, default: null },
    planName: { type: String, required: true },
    activeClientCount: { type: Number, required: true },
});

// Copia local para poder sumar invitaciones nuevas en vivo (antes solo se
// veían al refrescar la página) sin esperar una recarga completa.
const invitations = ref([...props.pendingInvitations]);

const userId = usePage().props.auth.user.id;
let personalChannel = null;

onMounted(() => {
    personalChannel = window.Echo.private(`App.Models.User.${userId}`);
    personalChannel.listen('.fleet-invitation.created', (e) => {
        invitations.value.unshift({
            id: e.id,
            fleet: { owner: { name: e.owner_name } },
            message: e.message,
        });
    });
});

onBeforeUnmount(() => {
    window.Echo.leave(`App.Models.User.${userId}`);
});

const accept = (invitationId) => {
    router.post(route('driver.invitations.accept', invitationId), {}, { preserveScroll: true });
    invitations.value = invitations.value.filter((i) => i.id !== invitationId);
};

const reject = (invitationId) => {
    router.post(route('driver.invitations.reject', invitationId), {}, { preserveScroll: true });
    invitations.value = invitations.value.filter((i) => i.id !== invitationId);
};

const leave = async (memberId) => {
    if (!(await confirmDialog('¿Seguro que ya no es su cliente? Va a salir de su flota.', { danger: true }))) return;

    router.post(route('driver.fleets.leave', memberId), {}, { preserveScroll: true });
};

// Deshabilitar solicitudes de un cliente puntual (pedido explícito del
// usuario): sigue siendo parte de la flota, solo deja de mandarle pedidos.
const toggleRequests = (memberId) => {
    router.post(route('driver.fleets.toggle-requests', memberId), {}, { preserveScroll: true });
};

const CATEGORY_LABELS = {
    diamante: '💎 Diamante',
    oro: '🥇 Oro',
    plata: '🥈 Plata',
    cobre: '🥉 Cobre',
};

function formatLastRide(iso) {
    if (!iso) return 'Todavía no le hice ninguna carrera';
    return `Última carrera: ${new Date(iso).toLocaleDateString('es-EC', { day: 'numeric', month: 'short', year: 'numeric' })}`;
}

const atLimit = props.maxClients !== null && props.activeClientCount >= props.maxClients;
</script>

<template>
    <Head title="Mis clientes de confianza" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-arka-text leading-tight">Mis clientes de confianza</h2>
                <span class="text-sm text-arka-text-muted">
                    Plan {{ planName }} · {{ activeClientCount }} de {{ maxClients ?? '∞' }} clientes
                </span>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Invitaciones pendientes de responder -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-2">Invitaciones recibidas</h3>
                    <p v-if="atLimit" class="mb-4 text-sm text-arka-warning">
                        Llegó al límite de clientes de confianza de su plan. Suba de plan para poder aceptar más.
                    </p>

                    <p v-if="!invitations.length" class="text-sm text-arka-text-muted">
                        No tiene invitaciones pendientes por ahora.
                    </p>

                    <ul v-else class="divide-y divide-arka-text-muted/10">
                        <li
                            v-for="invitation in invitations"
                            :key="invitation.id"
                            class="py-3 flex items-center justify-between gap-4"
                        >
                            <div>
                                <p class="text-arka-text font-medium">{{ invitation.fleet.owner.name }}</p>
                                <p v-if="invitation.message" class="text-sm text-arka-text-muted">
                                    "{{ invitation.message }}"
                                </p>
                            </div>

                            <div class="flex gap-2 shrink-0">
                                <PrimaryButton :disabled="atLimit" @click="accept(invitation.id)">
                                    Aceptar
                                </PrimaryButton>
                                <SecondaryButton @click="reject(invitation.id)">Rechazar</SecondaryButton>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Flotas a las que ya pertenezco -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-4">Flotas a las que pertenecés</h3>

                    <p v-if="!activeMemberships.length" class="text-sm text-arka-text-muted">
                        Todavía no formás parte de ninguna flota.
                    </p>

                    <ul v-else class="divide-y divide-arka-text-muted/10">
                        <li
                            v-for="member in activeMemberships"
                            :key="member.id"
                            class="py-3 flex items-center justify-between gap-4"
                        >
                            <div class="flex items-center gap-3 min-w-0">
                                <UserAvatar :user="member.fleet.owner" size-class="h-12 w-12 text-base shrink-0" />
                                <div class="min-w-0">
                                    <p class="text-arka-text font-medium flex items-center gap-2 flex-wrap">
                                        {{ member.fleet.owner.name }}
                                        <span v-if="member.client_review_count > 0" class="text-xs text-arka-lime">
                                            ★ {{ member.client_rating.toFixed(1) }}
                                        </span>
                                        <span v-else class="text-xs text-arka-text-muted">Sin calificaciones</span>
                                        <span class="text-xs">{{ CATEGORY_LABELS[member.client_category] }}</span>
                                    </p>
                                    <p class="text-sm text-arka-text-muted">
                                        {{ member.rides_together_count }} carrera(s) hecha(s)
                                    </p>
                                    <p class="text-xs text-arka-text-muted">{{ formatLastRide(member.last_ride_at) }}</p>
                                    <button
                                        type="button"
                                        class="mt-1 text-xs"
                                        :class="member.requests_disabled ? 'text-arka-warning' : 'text-arka-primary hover:text-arka-primary-bright'"
                                        @click="toggleRequests(member.id)"
                                    >
                                        {{ member.requests_disabled ? 'Solicitudes deshabilitadas — toque para habilitar' : 'Deshabilitar solicitudes' }}
                                    </button>
                                </div>
                            </div>
                            <DangerButton class="shrink-0" @click="leave(member.id)">No es mi cliente</DangerButton>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
