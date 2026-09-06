<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import UserAvatar from '@/Components/UserAvatar.vue';
import TrustScoreBadge from '@/Components/TrustScoreBadge.vue';
import { confirmDialog } from '@/Utils/confirmDialog';
import { openWhatsAppChooser } from '@/Utils/whatsapp';

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
// Pedido explícito del usuario: "el cuadro de invite solo deja un enlace y
// que sea el de WhatsApp" — se sacó la alternativa de "Compartir invitación"
// genérica, queda un único botón.
function shareInviteByWhatsApp() {
    const message = `¡Hola! Soy conductor en Arka01 y le invito a agregarme a su flota de confianza para pedirme sus viajes. Puede hacerlo acá: ${route('referrals.show', props.inviteCode)}`;
    openWhatsAppChooser(message);
}

// Pedido explícito del usuario: "los conductores que puedan mandar invitación
// mediante un buscador de clientes que existen" — mismo patrón de buscador
// con debounce que Fleet/Show.vue usa del otro lado (buscar conductores).
const searchTerm = ref('');
const searchResults = ref([]);
const searching = ref(false);
const lastSearchedTerm = ref('');
let searchTimeout = null;

const runSearch = () => {
    clearTimeout(searchTimeout);

    if (searchTerm.value.trim().length < 2) {
        searchResults.value = [];
        lastSearchedTerm.value = '';
        return;
    }

    searchTimeout = setTimeout(async () => {
        searching.value = true;
        try {
            const { data } = await window.axios.get(route('driver.clients.search'), {
                params: { q: searchTerm.value },
            });
            searchResults.value = data.clients;
            lastSearchedTerm.value = searchTerm.value;
        } finally {
            searching.value = false;
        }
    }, 300);
};

const showNoClientsFound = computed(
    () => !searching.value && lastSearchedTerm.value === searchTerm.value && searchResults.value.length === 0
);

const requestClient = (client) => {
    router.post(
        route('fleet-invitations.request'),
        { client_user_id: client.user_id },
        {
            preserveScroll: true,
            onSuccess: () => {
                client.status = 'pending';
            },
        }
    );
};

// Copia local para poder sumar invitaciones nuevas en vivo (antes solo se
// veían al refrescar la página) sin esperar una recarga completa.
const invitations = ref([...props.pendingInvitations]);
const openInvitation = ref(null);
const openMembership = ref(null);

watch(
    () => props.pendingInvitations,
    (value) => { invitations.value = [...value]; },
    { deep: true },
);

const userId = usePage().props.auth.user.id;
let personalChannel = null;

function handleFleetInvitationCreated(e) {
    // El evento en vivo solo trae un resumen. Recargamos exclusivamente esta
    // prop para obtener también reputación y clientes en común, sin refrescar
    // toda la pantalla ni perder filtros.
    router.reload({
        only: ['pendingInvitations'],
        preserveScroll: true,
    });
}

onMounted(() => {
    personalChannel = window.Echo.private(`App.Models.User.${userId}`);
    personalChannel.listen('.fleet-invitation.created', handleFleetInvitationCreated);
});

onBeforeUnmount(() => {
    personalChannel?.stopListening('.fleet-invitation.created', handleFleetInvitationCreated);
});

// Bug real reportado por el usuario ("le da aceptar y no le deja, no le
// lanza error ni le dice nada"): antes esto sacaba la invitación de la
// lista de inmediato, sin esperar la respuesta del servidor — si el backend
// la rechazaba (ej. llegó al cupo de clientes de su plan, o alguien ya la
// respondió desde otra pestaña), la tarjeta igual desaparecía como si se
// hubiera aceptado, pero el cliente nunca quedaba agregado y no había
// ningún aviso de que algo había fallado. Ahora la tarjeta solo se quita
// tras un onSuccess real, y un error de verdad se muestra (ver
// FleetInvitationManager::accept()/reject(), que ya mandan el motivo en
// errors.invitation).
const invitationActionError = ref(null);

const accept = (invitationId) => {
    invitationActionError.value = null;
    router.post(route('driver.invitations.accept', invitationId), {}, {
        preserveScroll: true,
        onSuccess: () => {
            invitations.value = invitations.value.filter((i) => i.id !== invitationId);
        },
        onError: (errors) => {
            invitationActionError.value = errors.invitation ?? 'No se pudo aceptar la solicitud. Intente de nuevo.';
        },
    });
};

const reject = (invitationId) => {
    invitationActionError.value = null;
    router.post(route('driver.invitations.reject', invitationId), {}, {
        preserveScroll: true,
        onSuccess: () => {
            invitations.value = invitations.value.filter((i) => i.id !== invitationId);
        },
        onError: (errors) => {
            invitationActionError.value = errors.invitation ?? 'No se pudo rechazar la solicitud. Intente de nuevo.';
        },
    });
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
    diamante: 'Diamante',
    oro: 'Oro',
    plata: 'Plata',
    cobre: 'Cobre',
};

function formatLastRide(iso) {
    if (!iso) return 'Todavía no le hice ninguna carrera';
    return `Última carrera: ${new Date(iso).toLocaleDateString('es-EC', { day: 'numeric', month: 'short', year: 'numeric' })}`;
}

function formatJoinedAt(iso) {
    if (!iso) return 'Cliente de confianza';
    return `En su cartera desde ${new Date(iso).toLocaleDateString('es-EC', { month: 'short', year: 'numeric' })}`;
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
                     link público de "Referí a tu conductor". Minimizada a
                     propósito (pedido explícito del usuario: "que quede tipo
                     minimizado, quiero que sea limpio") — un solo botón, sin
                     texto de más. -->
                <div v-if="inviteCode" class="p-4 bg-arka-card shadow rounded-arka flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="text-sm font-medium text-arka-text">Invite a un cliente</h3>
                        <p class="text-xs text-arka-text-muted truncate">Compártale su enlace de flota.</p>
                    </div>
                    <SecondaryButton class="gap-1.5 shrink-0" @click="shareInviteByWhatsApp">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor">
                            <path
                                d="M12 2a10 10 0 0 0-8.6 15L2 22l5.2-1.4A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.1l-.3-.2-3.1.8.8-3-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1-.2.2-.7.8-.8 1-.1.1-.3.2-.5.1-.2-.1-1-.4-1.9-1.2-.7-.6-1.2-1.4-1.3-1.6-.1-.2 0-.4.1-.5.1-.1.2-.3.4-.4.1-.1.2-.2.2-.4.1-.1 0-.3 0-.4 0-.1-.6-1.4-.8-1.9-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.4.1-.6.3-.2.2-.8.8-.8 1.9s.8 2.2 1 2.4c.1.1 1.6 2.4 3.8 3.4.5.2.9.4 1.3.5.6.2 1.1.1 1.5.1.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.1-1.2 0-.1-.2-.2-.4-.3Z"
                            />
                        </svg>
                        WhatsApp
                    </SecondaryButton>
                </div>

                <!-- Buscar un cliente existente y mandarle la solicitud (pedido
                     explícito del usuario) -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text">Buscar un cliente</h3>
                    <p class="mt-1 text-sm text-arka-text-muted">
                        ¿Ya tiene su cuenta en Arka01? Búsquelo y mándele la solicitud directo, sin esperar a que lo
                        invite primero.
                    </p>

                    <!-- Pedido explícito del usuario ("puedes habilitar para
                         que busque por nombre y usuario tambien"): vuelve a
                         admitir nombre/apellido/usuario además del código de
                         socio, con el mismo criterio que ya usaba la
                         búsqueda de conductores del lado del cliente. -->
                    <InputLabel value="Nombre, usuario o código de socio" class="mt-4" />
                    <TextInput
                        v-model="searchTerm"
                        type="text"
                        class="mt-1 block w-full"
                        placeholder="Ej: Juan Pérez, @jperez o 512"
                        @input="runSearch"
                    />

                    <ul v-if="searchResults.length" class="mt-4 divide-y divide-arka-text-muted/10">
                        <li
                            v-for="client in searchResults"
                            :key="client.user_id"
                            class="flex flex-col gap-3 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between"
                        >
                            <div class="flex items-center gap-3 min-w-0">
                                <UserAvatar :user="client" size-class="h-11 w-11 text-sm shrink-0" />
                                <div class="min-w-0">
                                    <p class="text-arka-text font-medium flex items-center gap-1.5 flex-wrap">
                                        {{ client.name }}
                                        <span v-if="client.member_code" class="text-xs text-arka-text-muted">#{{ client.member_code }}</span>
                                    </p>
                                    <p v-if="client.city || client.username" class="text-sm text-arka-text-muted">
                                        <span v-if="client.city">{{ client.city }}</span>
                                        <span v-if="client.city && client.username"> · </span>
                                        <span v-if="client.username">@{{ client.username }}</span>
                                    </p>
                                    <p class="text-xs text-arka-text-muted">
                                        <span v-if="client.review_count > 0" class="text-arka-lime">★ {{ client.average_rating.toFixed(1) }}</span>
                                        <span v-else>Sin calificaciones</span>
                                    </p>
                                </div>
                            </div>

                            <PrimaryButton v-if="client.status === 'not_invited'" class="sm:shrink-0" @click="requestClient(client)">
                                Enviar solicitud
                            </PrimaryButton>
                            <span v-else-if="client.status === 'pending'" class="text-sm text-arka-lime sm:shrink-0">
                                Solicitud enviada
                            </span>
                            <!-- Pedido explícito del usuario ("asi mismo con luis que
                                 si le aparece que le diga este cliente pertenece a tu
                                 flota de cooperativa"): mismo criterio que del lado
                                 del cliente — aparece en la búsqueda, pero sin botón
                                 y con el motivo real en vez de un error al tocarlo. -->
                            <span
                                v-else-if="client.status === 'cooperative_locked'"
                                class="max-w-[14rem] text-right text-xs text-arka-text-muted sm:shrink-0"
                            >
                                Este cliente pertenece a su flota de cooperativa
                            </span>
                            <span v-else class="text-sm text-arka-text-muted sm:shrink-0"> Ya es su cliente </span>
                        </li>
                    </ul>

                  <!--   <div v-if="showNoClientsFound" class="mt-4 p-4 rounded-arka bg-arka-base/60">
                        <p class="text-sm text-arka-text">"{{ searchTerm }}" no coincide con ningún cliente registrado.</p>
                        <p class="mt-1 text-sm text-arka-text-muted">
                            Puede invitarlo a sumarse a Arka01 con el botón de arriba.
                        </p>
                    </div> -->
                </div>

                <!-- Invitaciones pendientes de responder -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-2">Invitaciones recibidas</h3>
                    <!-- Pedido explícito del usuario: el mensaje no menciona una
                         cantidad específica adentro del texto — el número real ya
                         se ve arriba, en el encabezado ("X de Y clientes"),
                         siempre tomado del plan vigente, nunca hardcodeado acá. -->
                    <p v-if="atLimit" class="mb-4 text-sm text-arka-warning">
                        Alcanzó la capacidad de clientes incluida en su plan actual.
                        <Link :href="route('driver.plan.edit')" class="font-semibold underline">Mejore su plan</Link>
                        para ampliar su cartera privada y seguir agregando clientes.
                    </p>

                    <p v-if="invitationActionError" class="mb-4 text-sm text-arka-danger">
                        {{ invitationActionError }}
                    </p>

                    <p v-if="!invitations.length" class="text-sm text-arka-text-muted">
                        No tiene invitaciones pendientes por ahora.
                    </p>

                    <ul v-else class="divide-y divide-arka-text-muted/10">
                        <!-- Bug reportado por el usuario (con captura: nombre
                             cortado y botón "Aceptar" superpuesto en móvil) —
                             mismo arreglo ya aplicado en Fleet/Show.vue: sin
                             apilar en pantallas angostas, los botones (shrink-0)
                             le comían el ancho al bloque de nombre/datos. -->
                        <li
                            v-for="invitation in invitations"
                            :key="invitation.id"
                            class="grid gap-3 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start"
                        >
                            <div class="flex items-center gap-3 min-w-0">
                                <UserAvatar :user="invitation.fleet.owner" size-class="h-12 w-12 text-base shrink-0" />
                                <div class="min-w-0">
                                    <!-- "Recomendar mi flota" (pedido explícito del usuario): si
                                         quien invitó no es el dueño de la flota, es una
                                         recomendación de un tercero — el conductor necesita
                                         saber quién lo recomendó antes de aceptar o rechazar. -->
                                    <p
                                        v-if="invitation.inviter && invitation.inviter.id !== invitation.fleet.owner_user_id"
                                        class="mb-1 inline-block rounded-full bg-arka-primary/10 px-2 py-0.5 text-xs font-semibold text-arka-primary"
                                    >
                                        Recomendado por {{ invitation.inviter.name }}
                                    </p>
                                    <p class="text-arka-text font-medium flex items-center gap-2 flex-wrap">
                                        {{ invitation.fleet.owner.name }}
                                        <span v-if="invitation.client_review_count > 0" class="text-xs text-arka-lime">
                                            ★ {{ invitation.client_rating.toFixed(1) }}
                                        </span>
                                        <span v-else class="text-xs text-arka-text-muted">Sin calificaciones</span>
                                        <span class="text-xs">{{ CATEGORY_LABELS[invitation.client_category] }}</span>
                                        <span
                                            v-if="invitation.mutual_clients_count > 0"
                                            class="inline-flex items-center gap-1 rounded-full bg-arka-primary/10 px-2 py-0.5 text-xs font-semibold text-arka-primary"
                                        >
                                            <svg class="h-3.5 w-3.5 fill-none stroke-current" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm8-1a3.5 3.5 0 1 0 0-7M2 21a6 6 0 0 1 12 0m1-7a5 5 0 0 1 7 4.6" stroke-width="1.7" stroke-linecap="round" /></svg>
                                            {{ invitation.mutual_clients_count }} cliente{{ invitation.mutual_clients_count === 1 ? '' : 's' }} en común
                                        </span>
                                    </p>
                                    <p v-if="invitation.message" class="text-sm text-arka-text-muted">
                                        "{{ invitation.message }}"
                                    </p>
                                    <button
                                        type="button"
                                        class="mt-2 inline-flex items-center gap-1.5 text-xs font-semibold text-arka-primary hover:text-arka-primary-bright"
                                        :aria-expanded="openInvitation === invitation.id"
                                        @click="openInvitation = openInvitation === invitation.id ? null : invitation.id"
                                    >
                                        {{ openInvitation === invitation.id ? 'Ocultar información' : 'Revisar perfil y confianza' }}
                                        <svg class="h-3.5 w-3.5 fill-none stroke-current transition-transform" :class="{ 'rotate-180': openInvitation === invitation.id }" viewBox="0 0 24 24" aria-hidden="true"><path d="m7 9 5 5 5-5" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                    </button>
                                </div>
                            </div>

                            <div
                                v-if="openInvitation === invitation.id"
                                class="w-full rounded-xl border border-arka-primary/15 bg-arka-base/50 p-3 sm:col-span-2"
                            >
                                <!-- Pedido explícito del usuario: el texto genérico
                                     "Información para decidir" no aportaba nada — en su
                                     lugar, los datos concretos que sí sirven para decidir:
                                     carreras, conductores que ya tiene, en común y círculo,
                                     más el índice de confianza (como % simple, sin fracción). -->
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <TrustScoreBadge :trust="invitation.trust" />
                                    <Link
                                        :href="route('profiles.show', invitation.fleet.owner.public_id)"
                                        class="inline-flex min-h-9 items-center rounded-lg border border-arka-primary/30 px-3 py-1.5 text-xs font-semibold text-arka-primary-bright hover:bg-arka-primary/10"
                                    >
                                        Ver perfil completo
                                    </Link>
                                </div>
                                <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                    <div class="rounded-lg bg-arka-card p-2.5">
                                        <p class="text-[10px] text-arka-text-muted">Carreras</p>
                                        <p class="text-sm font-semibold text-arka-text">{{ invitation.trust?.completed_rides ?? 0 }}</p>
                                    </div>
                                    <div class="rounded-lg bg-arka-card p-2.5">
                                        <p class="text-[10px] text-arka-text-muted">Conductores</p>
                                        <p class="text-sm font-semibold text-arka-text">{{ invitation.driver_count }}</p>
                                    </div>
                                    <div class="rounded-lg bg-arka-card p-2.5">
                                        <p class="text-[10px] text-arka-text-muted">En común</p>
                                        <p class="text-sm font-semibold text-arka-text">{{ invitation.mutual_clients_count }}</p>
                                    </div>
                                    <div class="rounded-lg bg-arka-card p-2.5">
                                        <p class="text-[10px] text-arka-text-muted">Círculo</p>
                                        <p class="text-sm font-semibold text-arka-text">{{ invitation.trust?.network_connections ?? 0 }}</p>
                                    </div>
                                </div>
                                <p class="mt-3 text-xs font-semibold text-arka-text">
                                    {{ invitation.mutual_clients_count > 0 ? 'Clientes tuyos que conocen a esta persona' : 'Sin clientes en común por ahora' }}
                                </p>
                                <div v-if="invitation.mutual_clients_count > 0" class="mt-2 flex flex-wrap gap-2">
                                    <Link
                                        v-for="person in invitation.mutual_clients"
                                        :key="person.public_id"
                                        :href="route('profiles.show', person.public_id)"
                                        class="inline-flex min-w-0 items-center gap-2 rounded-full border border-white/5 bg-arka-card px-2 py-1.5 transition hover:border-arka-primary/30"
                                    >
                                        <UserAvatar :user="person" size-class="h-7 w-7 text-[10px] shrink-0" />
                                        <span class="max-w-36 truncate text-xs font-medium text-arka-text">{{ person.name }}</span>
                                    </Link>
                                    <span v-if="invitation.mutual_clients_count > invitation.mutual_clients.length" class="self-center text-xs text-arka-text-muted">
                                        +{{ invitation.mutual_clients_count - invitation.mutual_clients.length }} más
                                    </span>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-2 sm:shrink-0">
                                <PrimaryButton
                                    v-if="openInvitation === invitation.id"
                                    :disabled="atLimit"
                                    @click="accept(invitation.id)"
                                >
                                    Aceptar cliente
                                </PrimaryButton>
                                <SecondaryButton v-else @click="openInvitation = invitation.id">Revisar solicitud</SecondaryButton>
                                <SecondaryButton @click="reject(invitation.id)">Rechazar</SecondaryButton>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Cartera activa. Sigue el mismo patrón visual de las
                     tarjetas de conductores que ve el cliente: identidad,
                     indicadores comparables y acciones claras. -->
                <section class="rounded-arka border border-arka-text-muted/10 bg-arka-card p-4 shadow sm:p-6">
                    <div class="mb-4 flex items-start justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-arka-primary">Su cartera</p>
                            <h3 class="mt-1 text-lg font-semibold text-arka-text">Clientes de confianza</h3>
                            <p class="mt-1 text-xs text-arka-text-muted">Revise su historial y las conexiones que respaldan cada relación.</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-arka-primary/10 px-2.5 py-1 text-xs font-semibold text-arka-primary-bright">
                            {{ activeMembershipStats.total }} cliente{{ activeMembershipStats.total === 1 ? '' : 's' }}
                        </span>
                    </div>

                    <!-- Contadores + filtro (pedido explícito del usuario:
                         "indicale cuántos tiene, nuevos, con carreras, sin
                         carrera") — cada chip filtra al tocarlo, sobre el
                         total sin filtrar así los números no se mueven solos. -->
                    <div class="mb-3 flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                        <button
                            v-for="chip in FILTER_CHIPS"
                            :key="chip.value"
                            type="button"
                            class="shrink-0 rounded-full px-3 py-1.5 text-xs font-medium transition"
                            :class="
                                membershipQuery.filter === chip.value
                                    ? 'bg-arka-primary/15 text-arka-primary-bright'
                                    : 'bg-arka-base/60 text-arka-text-muted hover:text-arka-text'
                            "
                            @click="setMembershipFilter(chip.value)"
                        >
                            {{ chip.label }} ({{ activeMembershipStats[chip.value === 'todos' ? 'total' : chip.value] }})
                        </button>

                    </div>

                    <div class="mb-4 flex items-center justify-between gap-3 rounded-xl border border-arka-text-muted/10 bg-arka-base/45 px-3 py-2">
                        <span class="text-xs text-arka-text-muted">Ordenar clientes</span>
                        <select
                            v-model="membershipQuery.sort"
                            class="rounded-lg border-arka-text-muted/20 bg-arka-card py-1.5 pe-8 ps-3 text-xs text-arka-text"
                            @change="applyMembershipQuery"
                        >
                            <option value="recientes">Actividad reciente</option>
                            <option value="carreras">Más carreras juntos</option>
                        </select>
                    </div>

                    <p v-if="!activeMemberships.data.length" class="text-sm text-arka-text-muted">
                        {{ activeMembershipStats.total === 0 ? 'Todavía no formás parte de ninguna flota.' : 'Ningún cliente coincide con este filtro.' }}
                    </p>

                    <ul v-else class="grid gap-3 sm:grid-cols-2">
                        <li
                            v-for="member in activeMemberships.data"
                            :key="member.id"
                            class="group relative overflow-hidden rounded-2xl border border-arka-text-muted/10 bg-arka-base p-4 shadow-sm transition duration-200 hover:border-arka-primary/35 hover:shadow-lg hover:shadow-black/10"
                        >
                            <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-arka-primary/50 to-transparent opacity-0 transition group-hover:opacity-100"></div>

                            <div class="flex min-w-0 items-start gap-3">
                                <Link :href="route('profiles.show', member.fleet.owner.public_id)" class="shrink-0">
                                    <UserAvatar :user="member.fleet.owner" size-class="h-14 w-14 text-base ring-2 ring-arka-primary/15" />
                                </Link>
                                <div class="min-w-0 flex-1">
                                    <Link :href="route('profiles.show', member.fleet.owner.public_id)" class="block truncate font-semibold text-arka-text hover:text-arka-primary-bright">
                                        {{ member.fleet.owner.name }}
                                    </Link>
                                    <p class="mt-0.5 truncate text-xs text-arka-text-muted">
                                        <span v-if="member.fleet.owner.username">@{{ member.fleet.owner.username }}</span>
                                        <span v-if="member.fleet.owner.username && member.fleet.owner.member_code"> · </span>
                                        <span v-if="member.fleet.owner.member_code">Socio #{{ member.fleet.owner.member_code }}</span>
                                    </p>
                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        <span class="rounded-full bg-arka-primary/10 px-2 py-0.5 text-[10px] font-semibold text-arka-primary-bright">Cliente</span>
                                        <span class="rounded-full border border-arka-text-muted/15 px-2 py-0.5 text-[10px] font-medium text-arka-text-muted">
                                            {{ CATEGORY_LABELS[member.client_category] }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <TrustScoreBadge :trust="member.trust" class="mt-3 max-w-full" />

                            <div class="mt-3 grid grid-cols-3 divide-x divide-arka-text-muted/10 rounded-xl border border-arka-text-muted/10 bg-arka-card/70 py-3 text-center">
                                <div class="px-1">
                                    <p class="text-sm font-semibold text-arka-text">{{ member.client_review_count > 0 ? member.client_rating.toFixed(1) : '—' }}</p>
                                    <p class="mt-0.5 text-[9px] uppercase tracking-wide text-arka-text-muted">Calificación</p>
                                </div>
                                <div class="px-1">
                                    <p class="text-sm font-semibold text-arka-text">{{ member.rides_together_count }}</p>
                                    <p class="mt-0.5 text-[9px] uppercase tracking-wide text-arka-text-muted">Carreras</p>
                                </div>
                                <div class="px-1">
                                    <p class="text-sm font-semibold text-arka-text">{{ member.mutual_clients_count }}</p>
                                    <p class="mt-0.5 text-[9px] uppercase tracking-wide text-arka-text-muted">En común</p>
                                </div>
                            </div>

                            <div class="mt-3 flex items-center justify-between gap-2 text-[11px]">
                                <span class="truncate text-arka-text-muted">{{ formatLastRide(member.last_ride_at) }}</span>
                                <span
                                    class="shrink-0 rounded-full px-2 py-1 font-medium"
                                    :class="member.requests_disabled ? 'bg-arka-warning/10 text-arka-warning' : 'bg-arka-primary/10 text-arka-primary-bright'"
                                >
                                    {{ member.requests_disabled ? 'Pausado' : 'Solicitudes activas' }}
                                </span>
                            </div>

                            <div class="mt-4 grid grid-cols-2 gap-2">
                                <Link
                                    :href="route('profiles.show', member.fleet.owner.public_id)"
                                    class="inline-flex min-h-10 items-center justify-center rounded-xl bg-arka-primary px-3 py-2 text-center text-xs font-semibold text-arka-base transition hover:bg-arka-primary-bright"
                                >
                                    Ver perfil
                                </Link>
                                <button
                                    type="button"
                                    class="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-xl border border-arka-primary/30 px-3 py-2 text-xs font-semibold text-arka-primary-bright transition hover:bg-arka-primary/10"
                                    :aria-expanded="openMembership === member.id"
                                    @click="openMembership = openMembership === member.id ? null : member.id"
                                >
                                    {{ openMembership === member.id ? 'Cerrar detalle' : 'Ver relaciones' }}
                                    <svg class="h-3.5 w-3.5 fill-none stroke-current transition-transform" :class="{ 'rotate-180': openMembership === member.id }" viewBox="0 0 24 24" aria-hidden="true"><path d="m7 9 5 5 5-5" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                </button>
                            </div>

                            <div
                                v-if="openMembership === member.id"
                                class="mt-4 rounded-xl border border-arka-primary/15 bg-arka-card/75 p-3"
                            >
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-arka-text">Detalle de confianza</p>
                                        <p class="mt-0.5 text-[11px] text-arka-text-muted">{{ formatJoinedAt(member.joined_at) }}</p>
                                    </div>
                                    <span class="text-xs font-semibold text-arka-primary-bright">{{ member.trust?.score ?? 0 }}%</span>
                                </div>
                                <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                    <div class="rounded-lg bg-arka-card p-2.5">
                                        <p class="text-[10px] text-arka-text-muted">Carreras</p>
                                        <p class="text-sm font-semibold text-arka-text">{{ member.trust?.completed_rides ?? 0 }}</p>
                                    </div>
                                    <div class="rounded-lg bg-arka-card p-2.5">
                                        <p class="text-[10px] text-arka-text-muted">Conductores</p>
                                        <p class="text-sm font-semibold text-arka-text">{{ member.driver_count }}</p>
                                    </div>
                                    <div class="rounded-lg bg-arka-card p-2.5">
                                        <p class="text-[10px] text-arka-text-muted">En común</p>
                                        <p class="text-sm font-semibold text-arka-text">{{ member.mutual_clients_count }}</p>
                                    </div>
                                    <div class="rounded-lg bg-arka-card p-2.5">
                                        <p class="text-[10px] text-arka-text-muted">Círculo</p>
                                        <p class="text-sm font-semibold text-arka-text">{{ member.trust?.network_connections ?? 0 }}</p>
                                    </div>
                                </div>
                                <p class="mt-3 text-xs font-semibold text-arka-text">
                                    {{ member.mutual_clients_count > 0 ? 'Personas de su cartera que también lo conocen' : 'Aún no tienen personas de confianza en común' }}
                                </p>
                                <div v-if="member.mutual_clients_count > 0" class="mt-3 flex flex-wrap gap-2">
                                    <Link
                                        v-for="person in member.mutual_clients"
                                        :key="person.public_id"
                                        :href="route('profiles.show', person.public_id)"
                                        class="inline-flex min-w-0 items-center gap-2 rounded-full border border-white/5 bg-arka-card px-2 py-1.5 transition hover:border-arka-primary/30"
                                    >
                                        <UserAvatar :user="person" size-class="h-7 w-7 shrink-0 text-[10px]" />
                                        <span class="max-w-36 truncate text-xs font-medium text-arka-text">{{ person.name }}</span>
                                    </Link>
                                </div>

                                <!-- Las acciones administrativas quedan en el
                                     detalle, lejos del CTA principal, para
                                     evitar que el conductor quite a alguien
                                     por error al recorrer la lista. -->
                                <div class="mt-4 border-t border-arka-text-muted/10 pt-3">
                                    <button
                                        type="button"
                                        class="flex w-full items-center justify-between rounded-lg px-2 py-2 text-left text-xs font-medium transition hover:bg-arka-base/60"
                                        :class="member.requests_disabled ? 'text-arka-primary-bright' : 'text-arka-text-muted'"
                                        @click="toggleRequests(member.id)"
                                    >
                                        <span>{{ member.requests_disabled ? 'Volver a recibir sus solicitudes' : 'Pausar solicitudes de este cliente' }}</span>
                                        <span aria-hidden="true">→</span>
                                    </button>
                                    <button
                                        type="button"
                                        class="mt-1 flex w-full items-center justify-between rounded-lg px-2 py-2 text-left text-xs font-medium text-red-400 transition hover:bg-red-500/10"
                                        @click="leave(member.id)"
                                    >
                                        <span>Quitar de mis clientes</span>
                                        <span aria-hidden="true">→</span>
                                    </button>
                                </div>
                            </div>
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
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
