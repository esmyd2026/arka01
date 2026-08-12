<script setup>
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import UserAvatar from '@/Components/UserAvatar.vue';
import { confirmDialog } from '@/Utils/confirmDialog';

const props = defineProps({
    pendingInvitations: { type: Array, required: true },
    // Paginado (pedido explícito del usuario: "se ve muy largo, paginala") —
    // objeto de paginador de Laravel, no un array plano.
    activeMemberships: { type: Object, required: true },
    // Pedido explícito del usuario: "indicale cuántos tiene, nuevos, con
    // carreras, sin carrera" — sobre el total sin filtrar.
    activeMembershipStats: { type: Object, required: true },
    filters: { type: Object, required: true },
    // null = sin límite (plan Institucional sin cupo pactado).
    maxClients: { type: Number, default: null },
    planName: { type: String, required: true },
    activeClientCount: { type: Number, required: true },
    inviteCode: { type: String, default: null },
});

// Pedido explícito del usuario: que el conductor pueda invitar por WhatsApp
// a un cliente a que lo sume a su flota — mismo link público que "Referí a
// tu conductor" (Fleet/Show.vue, del lado cliente), solo que acá lo comparte
// el propio conductor con su gente en vez de que un cliente lo recomiende.
// Mismo criterio contra el link duplicado que ese otro botón: el texto NUNCA
// lleva la URL adentro, se arma un solo string con el link al final.
function inviteMessageText() {
    return '¡Hola! Soy conductor en Arka01 y le invito a agregarme a su flota de confianza para pedirme sus viajes.';
}

function shareInviteByWhatsApp() {
    const message = `${inviteMessageText()} Puede hacerlo acá: ${route('referrals.show', props.inviteCode)}`;
    window.open(`https://wa.me/?text=${encodeURIComponent(message)}`, '_blank');
}

const inviteLinkCopied = ref(false);
async function shareInviteGeneric() {
    const shareData = {
        title: 'Súmeme a su flota en Arka01',
        text: inviteMessageText(),
        url: route('referrals.show', props.inviteCode),
    };

    if (navigator.share) {
        try {
            await navigator.share(shareData);
        } catch {
            // Cerró el panel de compartir sin elegir nada — no es un error.
        }
        return;
    }

    await navigator.clipboard.writeText(`${inviteMessageText()} Puede hacerlo acá: ${route('referrals.show', props.inviteCode)}`);
    inviteLinkCopied.value = true;
    setTimeout(() => (inviteLinkCopied.value = false), 2000);
}

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

// Filtros + orden de "Flotas a las que pertenecés" (pedido explícito del
// usuario: "paginala y colocale filtros y ordenala de manera descendente,
// indicale cuántos tiene, nuevos, con carreras, sin carrera") — el backend
// (DriverInvitationController::index()) ya devuelve todo filtrado/ordenado/
// paginado según estos dos parámetros, acá solo se arma la URL.
const FILTER_CHIPS = [
    { value: 'todos', label: 'Todos' },
    { value: 'nuevos', label: 'Nuevos' },
    { value: 'con_carreras', label: 'Con carreras' },
    { value: 'sin_carreras', label: 'Sin carreras' },
];

const membershipQuery = reactive({
    filter: props.filters.filter ?? 'todos',
    sort: props.filters.sort ?? 'recientes',
});

function applyMembershipQuery() {
    router.get(route('driver.invitations.index'), membershipQuery, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['activeMemberships', 'activeMembershipStats', 'filters'],
    });
}

function setMembershipFilter(value) {
    membershipQuery.filter = value;
    applyMembershipQuery();
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
                <!-- Invitar a un cliente (pedido explícito del usuario) — mismo
                     link público de "Referí a tu conductor", ahora compartible
                     directo por WhatsApp desde acá. -->
                <div v-if="inviteCode" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text">Invite a un cliente</h3>
                    <p class="mt-1 text-sm text-arka-text-muted">
                        Comparta su enlace — quien lo abra puede sumarlo a su flota de confianza con un toque, o crear
                        su cuenta si todavía no tiene.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <SecondaryButton @click="shareInviteByWhatsApp">📲 Invitar por WhatsApp</SecondaryButton>
                        <SecondaryButton @click="shareInviteGeneric">
                            {{ inviteLinkCopied ? 'Enlace copiado' : 'Compartir invitación' }}
                        </SecondaryButton>
                    </div>
                </div>

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
                            <div class="flex items-center gap-3 min-w-0">
                                <UserAvatar :user="invitation.fleet.owner" size-class="h-12 w-12 text-base shrink-0" />
                                <div class="min-w-0">
                                    <p class="text-arka-text font-medium flex items-center gap-2 flex-wrap">
                                        {{ invitation.fleet.owner.name }}
                                        <span v-if="invitation.client_review_count > 0" class="text-xs text-arka-lime">
                                            ★ {{ invitation.client_rating.toFixed(1) }}
                                        </span>
                                        <span v-else class="text-xs text-arka-text-muted">Sin calificaciones</span>
                                        <span class="text-xs">{{ CATEGORY_LABELS[invitation.client_category] }}</span>
                                    </p>
                                    <p v-if="invitation.message" class="text-sm text-arka-text-muted">
                                        "{{ invitation.message }}"
                                    </p>
                                </div>
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
                    <h3 class="text-lg font-medium text-arka-text mb-4">
                        Flotas a las que pertenecés ({{ activeMembershipStats.total }})
                    </h3>

                    <!-- Contadores + filtro (pedido explícito del usuario:
                         "indicale cuántos tiene, nuevos, con carreras, sin
                         carrera") — cada chip filtra al tocarlo, sobre el
                         total sin filtrar así los números no se mueven solos. -->
                    <div class="flex flex-wrap items-center gap-2 mb-4">
                        <button
                            v-for="chip in FILTER_CHIPS"
                            :key="chip.value"
                            type="button"
                            class="px-3 py-1.5 rounded-full text-xs font-medium transition"
                            :class="
                                membershipQuery.filter === chip.value
                                    ? 'bg-arka-primary/15 text-arka-primary-bright'
                                    : 'bg-arka-base/60 text-arka-text-muted hover:text-arka-text'
                            "
                            @click="setMembershipFilter(chip.value)"
                        >
                            {{ chip.label }} ({{ activeMembershipStats[chip.value === 'todos' ? 'total' : chip.value] }})
                        </button>

                        <!-- Orden (pedido explícito del usuario: "ordénala de
                             manera descendente") — ambas opciones ya son
                             descendentes de por sí (más reciente primero,
                             más carreras primero), solo cambia el criterio. -->
                        <select
                            v-model="membershipQuery.sort"
                            class="ms-auto rounded-arka border-arka-text-muted/30 bg-arka-card text-arka-text text-xs py-1.5"
                            @change="applyMembershipQuery"
                        >
                            <option value="recientes">Más recientes</option>
                            <option value="carreras">Más carreras</option>
                        </select>
                    </div>

                    <p v-if="!activeMemberships.data.length" class="text-sm text-arka-text-muted">
                        {{ activeMembershipStats.total === 0 ? 'Todavía no formás parte de ninguna flota.' : 'Ningún cliente coincide con este filtro.' }}
                    </p>

                    <ul v-else class="divide-y divide-arka-text-muted/10">
                        <li
                            v-for="member in activeMemberships.data"
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

                    <!-- Paginado (pedido explícito del usuario: "se ve muy largo, paginala") -->
                    <div v-if="activeMemberships.prev_page_url || activeMemberships.next_page_url" class="flex justify-between pt-4">
                        <Link
                            v-if="activeMemberships.prev_page_url"
                            :href="activeMemberships.prev_page_url"
                            preserve-scroll
                            class="text-sm text-arka-primary hover:text-arka-primary-bright"
                        >
                            &larr; Anterior
                        </Link>
                        <span v-else></span>

                        <Link
                            v-if="activeMemberships.next_page_url"
                            :href="activeMemberships.next_page_url"
                            preserve-scroll
                            class="text-sm text-arka-primary hover:text-arka-primary-bright"
                        >
                            Siguiente &rarr;
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
