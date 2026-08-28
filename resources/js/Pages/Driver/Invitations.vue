<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import UserAvatar from '@/Components/UserAvatar.vue';
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

const userId = usePage().props.auth.user.id;
let personalChannel = null;

function handleFleetInvitationCreated(e) {
    invitations.value.unshift({
        id: e.id,
        fleet: { owner: { name: e.owner_name } },
        message: e.message,
    });
}

onMounted(() => {
    personalChannel = window.Echo.private(`App.Models.User.${userId}`);
    personalChannel.listen('.fleet-invitation.created', handleFleetInvitationCreated);
});

onBeforeUnmount(() => {
    personalChannel?.stopListening('.fleet-invitation.created', handleFleetInvitationCreated);
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

                    <!-- Pedido explícito del usuario ("manejar la privacidad...
                         limitemos la búsqueda por código nada más, porque
                         chocarían con millones de personas"): antes buscaba
                         también por nombre, y con una base grande de
                         usuarios eso da resultados ambiguos entre
                         desconocidos con el mismo nombre. -->
                    <InputLabel value="Código de socio" class="mt-4" />
                    <TextInput
                        v-model="searchTerm"
                        type="text"
                        class="mt-1 block w-full"
                        placeholder="Ej: 512"
                        @input="runSearch"
                    />

                    <ul v-if="searchResults.length" class="mt-4 divide-y divide-arka-text-muted/10">
                        <li
                            v-for="client in searchResults"
                            :key="client.user_id"
                            class="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3"
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
                    <p v-if="atLimit" class="mb-4 text-sm text-arka-warning">
                        Llegó al límite de clientes de confianza de su plan. Suba de plan para poder aceptar más.
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
                            class="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3"
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
                                    </p>
                                    <p v-if="invitation.message" class="text-sm text-arka-text-muted">
                                        "{{ invitation.message }}"
                                    </p>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-2 sm:shrink-0">
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
                            class="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3"
                        >
                            <div class="flex items-center gap-3 min-w-0">
                                <!-- Pedido explícito del usuario: "quiero ver
                                     el detalle de mi cliente" — mismo perfil
                                     público que ya existe para cualquier
                                     usuario logueado (Profile/Show.vue, sección
                                     3.6), sin tener que ser admin para verlo. -->
                                <Link :href="route('profiles.show', member.fleet.owner.public_id)" class="shrink-0">
                                    <UserAvatar :user="member.fleet.owner" size-class="h-12 w-12 text-base" />
                                </Link>
                                <div class="min-w-0">
                                    <p class="text-arka-text font-medium flex items-center gap-2 flex-wrap">
                                        <Link :href="route('profiles.show', member.fleet.owner.public_id)" class="hover:text-arka-primary-bright">
                                            {{ member.fleet.owner.name }}
                                        </Link>
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
                            <DangerButton class="sm:shrink-0" @click="leave(member.id)">No es mi cliente</DangerButton>
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
