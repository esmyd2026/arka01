<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Dropdown from '@/Components/Dropdown.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import TrustScoreBadge from '@/Components/TrustScoreBadge.vue';
import DriverCategoryBadge from '@/Components/DriverCategoryBadge.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { confirmDialog } from '@/Utils/confirmDialog';
import { tierLabel } from '@/Utils/tierBadge';
import { openWhatsAppChooser } from '@/Utils/whatsapp';

// Roster completo de UNA flota: buscador para invitar, conductores activos e
// invitaciones pendientes — pedido explícito del usuario ("que no vaya un
// paso más... que ahí ya salga su flota, y los botones de agregar los
// acomodes por ahí"). Esto vivía solo en Fleet/Show.vue, una pantalla aparte
// a la que había que entrar tocando la flota; ahora es un componente que
// Fleet/List.vue arma una vez por cada flota, directo en "Mis flotas" — sin
// esa navegación extra. Fleet/Show.vue se deja como pantalla mínima que
// reusa este mismo componente, por si algo llega a enlazar directo a una
// flota puntual.
const props = defineProps({
    fleet: { type: Object, required: true },
    // null = sin límite de conductores por flota.
    maxDriversPerFleet: { type: Number, default: null },
    // Pedido explícito del usuario: mismos datos que ya se ven al buscar
    // (calificación, carreras completadas, categoría) también en "Conductores
    // en tu flota" — objeto keyed por user_id, ver FleetController::fleetDetails().
    memberStats: { type: Object, required: true },
});

// "Referí a tu conductor" (pedido explícito del usuario, con una segunda
// vuelta: "un botón bien intuitivo... que despliegue un link para enviar por
// WhatsApp"): antes dependía de la Web Share API del navegador — en
// computadora, la mayoría no la soporta, así que el botón solo copiaba al
// portapapeles en silencio, sin ninguna señal clara de "mandalo por acá".
// Ahora abre WhatsApp directo con el mensaje ya armado (mismo criterio que
// "Compartir por WhatsApp" del propio perfil del conductor) — mismo
// invite_code de siempre, no uno nuevo por referido.
function whatsappReferralUrl(member) {
    const url = route('referrals.show', member.driver.driver_profile.invite_code);
    const text = `¡Hola! Le recomiendo a ${member.driver.name} como conductor de confianza en Arka01 \n\nPuede agregarlo a su propia flota acá: ${url}`;

    return `https://wa.me/?text=${encodeURIComponent(text)}`;
}

// --- Buscador tipo red social (sección 3.2): nombre, apellido, usuario o código ---
const searchTerm = ref('');
const searchResults = ref([]);
const searching = ref(false);
// Pedido explícito del usuario: mostrar "no está registrado, invitalo" solo
// cuando de verdad ya volvió una respuesta vacía PARA ESTE término — sin
// esto, apenas se escribe el segundo carácter se alcanza a ver el aviso un
// instante con los resultados (o el vacío) del término anterior.
const lastSearchedTerm = ref('');
let searchTimeout = null;

const runSearch = () => {
    clearTimeout(searchTimeout);

    if (searchTerm.value.trim().length < 2) {
        searchResults.value = [];
        lastSearchedTerm.value = '';
        return;
    }

    // Debounce simple: espera 300ms sin tipeo antes de consultar al backend,
    // para no mandar una petición por cada tecla presionada.
    searchTimeout = setTimeout(async () => {
        searching.value = true;
        try {
            const { data } = await window.axios.get(route('fleet.search-drivers', props.fleet.id), {
                params: { q: searchTerm.value },
            });
            searchResults.value = data.drivers;
            lastSearchedTerm.value = searchTerm.value;
        } finally {
            searching.value = false;
        }
    }, 300);
};

// Bug real reportado por el usuario (con captura: "Compartir invitación"
// aparecía solo, sin haber buscado nada) — con todo en su estado inicial
// (searchTerm/lastSearchedTerm en '', searchResults en []) esta condición ya
// daba `true` de entrada: '' === '' y 0 === 0, sin que nadie hubiera escrito
// un solo carácter. Ahora exige que de verdad se haya intentado una
// búsqueda (mismo umbral de 2 caracteres que runSearch()).
const showNotRegisteredYet = computed(
    () =>
        !searching.value &&
        searchTerm.value.trim().length >= 2 &&
        lastSearchedTerm.value === searchTerm.value &&
        searchResults.value.length === 0
);

// Invitar a alguien que TODAVÍA NO tiene cuenta en Arka01 (pedido explícito
// del usuario, caso real: buscó un conductor por nombre y no existía) —
// comparte un link genérico de registro, no el invite_code de nadie (ese es
// personal de un conductor que ya existe, ver referDriver() arriba).
function inviteMessageText() {
    return '¡Hola! Le invito a unirse a Arka01, la app para armar su propia flota de conductores de confianza.';
}

function shareInviteByWhatsApp() {
    const message = `${inviteMessageText()} Regístrese acá: ${route('register')}`;
    openWhatsAppChooser(message);
}

const inviteLinkCopied = ref(false);
async function shareInviteGeneric() {
    const shareData = { title: 'Únase a Arka01', text: inviteMessageText(), url: route('register') };

    if (navigator.share) {
        try {
            await navigator.share(shareData);
        } catch {
            // Cerró el panel de compartir sin elegir nada — no es un error.
        }
        return;
    }

    await navigator.clipboard.writeText(`${inviteMessageText()} Regístrese acá: ${route('register')}`);
    inviteLinkCopied.value = true;
    setTimeout(() => (inviteLinkCopied.value = false), 2000);
}

const invite = (driver) => {
    router.post(
        route('fleet.invitations.store', props.fleet.id),
        { driver_user_id: driver.user_id },
        {
            preserveScroll: true,
            onSuccess: () => {
                driver.status = 'pending';
            },
        }
    );
};

const cancelInvitation = (invitationId) => {
    router.delete(route('fleet.invitations.destroy', invitationId), { preserveScroll: true });
    invitations.value = invitations.value.filter((i) => i.id !== invitationId);
};

// Pedido explícito del usuario: los conductores también pueden mandar la
// solicitud (buscándolo desde Driver/Invitations.vue) — esta lista ya traía
// esas filas (FleetController::fleetDetails() no filtra por dirección), pero
// siempre mostraba "Cancelar" como si el cliente la hubiera mandado. Ahora
// distingue: si la mandó el conductor, le toca al cliente Aceptar/Rechazar.
const acceptFromDriver = (invitationId) => {
    router.post(route('driver.invitations.accept', invitationId), {}, { preserveScroll: true });
    invitations.value = invitations.value.filter((i) => i.id !== invitationId);
};

const rejectFromDriver = (invitationId) => {
    router.post(route('driver.invitations.reject', invitationId), {}, { preserveScroll: true });
    invitations.value = invitations.value.filter((i) => i.id !== invitationId);
};

const removeMember = async (memberId) => {
    if (!(await confirmDialog('¿Seguro que quiere sacar a este conductor de su flota?', { danger: true }))) return;

    router.delete(route('fleet.members.destroy', memberId), { preserveScroll: true });
};

const memberCount = computed(() => props.fleet.active_members?.length ?? 0);
const atLimit = computed(() => props.maxDriversPerFleet !== null && memberCount.value >= props.maxDriversPerFleet);

// Copia local editable de las invitaciones pendientes (mismo criterio que
// Driver/Invitations.vue): sin esto, una solicitud que manda un conductor
// mientras el cliente ya tiene esta pantalla abierta no aparecía hasta
// refrescar — el mismo problema en vivo ya reportado antes del lado
// conductor (ver Dashboard.vue), ahora también del lado cliente.
const invitations = ref([...(props.fleet.invitations ?? [])]);

const userId = usePage().props.auth.user.id;
let personalChannel = null;

// Bug evitado (nuevo desde que "Mis flotas" puede mostrar VARIAS flotas a la
// vez, una FleetRoster por cada una): el canal personal
// `App.Models.User.{id}` es UNO SOLO por cliente, no por flota — con más de
// una flota en pantalla, cada instancia de este componente se suscribe al
// MISMO canal. Antes (cuando esto vivía solo en Fleet/Show.vue, una flota a
// la vez) se limpiaba con `Echo.leave(canal)` al desmontar, pero eso cierra
// el canal entero — si una flota se desmonta mientras otra sigue en
// pantalla, le cortaría el aviso en vivo a esa otra. Acá se saca solo ESTE
// listener puntual (`stopListening`), sin tocar el canal compartido.
function onFleetInvitationCreated(e) {
    // El evento no distingue flota en el nombre del canal (es el canal
    // personal del cliente) — sin este filtro, una invitación que un
    // conductor le manda a UNA de las flotas aparecería repetida en TODAS
    // las que este cliente tenga abiertas en pantalla.
    if (e.direction !== 'driver' || e.fleet_id !== props.fleet.id) return;

    invitations.value.unshift({
        id: e.id,
        driver: { name: e.driver_name },
        message: e.message,
        initiated_by: 'driver',
    });
}

onMounted(() => {
    personalChannel = window.Echo.private(`App.Models.User.${userId}`);
    personalChannel.listen('.fleet-invitation.created', onFleetInvitationCreated);
});

onBeforeUnmount(() => {
    personalChannel?.stopListening('.fleet-invitation.created', onFleetInvitationCreated);
});
</script>

<template>
    <div class="space-y-6">
        <!-- Buscador para invitar conductores -->
        <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka border border-arka-text-muted/10">
            <!-- Pedido explícito del usuario: buscar por nombre, apellido,
                 usuario o código — cada resultado ya muestra foto, código y
                 calificación, así que varios homónimos no se confunden entre
                 sí (ver FleetController::searchDrivers()). El teléfono sigue
                 sin mostrarse en los resultados, eso no cambió. -->
            <InputLabel value="Buscar conductor por nombre, apellido, usuario o código" />
            <TextInput
                v-model="searchTerm"
                type="text"
                class="mt-1 block w-full"
                placeholder="Ej: Ana Cedeño, @ana o el código"
                @input="runSearch"
                :disabled="atLimit"
            />
            <p v-if="atLimit" class="mt-2 text-sm text-arka-warning">
                Llegó al límite de conductores de esta flota. Saque a alguien o suba de plan para invitar
                a más.
            </p>

            <ul v-if="searchResults.length" class="mt-4 divide-y divide-arka-text-muted/10">
                <li
                    v-for="driver in searchResults"
                    :key="driver.user_id"
                    class="py-3 flex items-center justify-between gap-4"
                >
                    <div class="flex items-center gap-3 min-w-0">
                        <UserAvatar :user="driver" size-class="h-11 w-11 text-sm shrink-0" />
                        <div class="min-w-0">
                            <p class="text-arka-text font-medium flex items-center gap-1.5 flex-wrap">
                                {{ driver.name }}
                                <span v-if="driver.member_code" class="text-xs text-arka-text-muted">#{{ driver.member_code }}</span>
                                <span class="text-xs">{{ tierLabel(driver.tier) }}</span>
                            </p>
                            <p class="text-sm text-arka-text-muted">
                                ${{ driver.rate_per_km }}/km
                                <span v-if="driver.username">· @{{ driver.username }}</span>
                            </p>
                            <DriverCategoryBadge class="mt-1" :label="driver.public_category_label" />
                            <p class="text-xs text-arka-text-muted">
                                <span v-if="driver.review_count > 0" class="text-arka-lime">★ {{ driver.average_rating.toFixed(1) }}</span>
                                <span v-else>Sin calificaciones</span>
                                · {{ driver.rides_count }} carrera(s) completada(s)
                                <!-- Pedido explícito del usuario: saber cuántos
                                     clientes tiene ya este conductor antes de
                                     sumarse — no es lo mismo invitar a alguien
                                     recién empezando que a alguien muy repartido. -->
                                · {{ driver.active_clients_count }} cliente(s)
                            </p>
                            <TrustScoreBadge :trust="driver.trust" class="mt-2" />
                        </div>
                    </div>

                    <PrimaryButton
                        v-if="driver.status === 'not_invited'"
                        :disabled="atLimit"
                        @click="invite(driver)"
                    >
                        Invitar
                    </PrimaryButton>
                    <span v-else-if="driver.status === 'pending'" class="text-sm text-arka-lime">
                        Invitación enviada
                    </span>
                    <span v-else class="text-sm text-arka-text-muted"> Ya está en su flota </span>
                </li>
            </ul>

            <!-- Pedido explícito del usuario (caso real: buscó un
                 conductor y no existía) — en vez de dejar la
                 búsqueda en silencio, invita a sumarlo a la
                 plataforma. -->
            <div v-if="showNotRegisteredYet" class="mt-4 flex flex-wrap gap-2">
                <SecondaryButton class="gap-1.5" @click="shareInviteGeneric">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="18" cy="6" r="2.5" />
                        <circle cx="6" cy="12" r="2.5" />
                        <circle cx="18" cy="18" r="2.5" />
                        <path stroke-linecap="round" d="M8.2 10.8 15.8 7.2M8.2 13.2l7.6 3.6" />
                    </svg>
                    {{ inviteLinkCopied ? 'Enlace copiado' : 'Compartir invitación' }}
                </SecondaryButton>
            </div>
        </div>

        <!-- Conductores activos en la flota -->
        <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka border border-arka-text-muted/10">
            <h3 class="text-lg font-medium text-arka-text mb-4">Conductores en su flota</h3>

            <p v-if="!fleet.active_members?.length" class="text-sm text-arka-text-muted">
                Todavía no tiene conductores en esta flota. Busque a alguien de confianza arriba para
                invitarlo, o mire el
                <Link :href="route('directory.index')" class="text-arka-primary hover:text-arka-primary-bright">
                    directorio de conductores públicos
                </Link>.
            </p>

            <!-- La tarjeta prioriza la decisión del cliente: primero identifica
                 al conductor, luego compara sus métricas y finalmente encuentra
                 las dos acciones de uso frecuente siempre visibles. -->
            <div v-else class="grid gap-3 sm:grid-cols-2">
                <article
                    v-for="member in fleet.active_members"
                    :key="member.id"
                    class="group relative overflow-hidden rounded-2xl border border-arka-text-muted/10 bg-arka-base p-4 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-arka-primary/35 hover:shadow-lg hover:shadow-black/10"
                >
                    <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-arka-primary/45 to-transparent opacity-0 transition group-hover:opacity-100"></div>

                    <!-- La acción destructiva queda apartada de los CTA para no
                         comprimirlos ni provocar pulsaciones accidentales. -->
                    <div class="absolute right-3 top-3 z-10">
                        <Dropdown align="right" width="48">
                            <template #trigger>
                                <button
                                    type="button"
                                    class="grid h-8 w-8 shrink-0 place-items-center rounded-xl border border-arka-text-muted/20 bg-arka-base/90 text-arka-text-muted transition hover:border-arka-text-muted/40 hover:bg-arka-card hover:text-arka-text"
                                    :aria-label="`Más opciones para ${member.driver.name}`"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                                        <circle cx="5" cy="12" r="1.8" />
                                        <circle cx="12" cy="12" r="1.8" />
                                        <circle cx="19" cy="12" r="1.8" />
                                    </svg>
                                </button>
                            </template>
                            <template #content>
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-2 px-4 py-2 text-start text-sm text-arka-danger hover:bg-arka-base transition"
                                    @click="removeMember(member.id)"
                                >
                                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m-8 0 .8 12a2 2 0 0 0 2 1.9h4.4a2 2 0 0 0 2-1.9L18 7" />
                                    </svg>
                                    Sacar de la flota
                                </button>
                            </template>
                        </Dropdown>
                    </div>

                    <div class="flex items-start gap-3 pr-9">
                        <div class="rounded-full ring-2 ring-arka-card ring-offset-2 ring-offset-arka-base">
                            <UserAvatar :user="member.driver" size-class="h-14 w-14 text-base shrink-0" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <Link
                                :href="route('profiles.show', member.driver.public_id)"
                                class="flex flex-wrap items-center gap-1.5 font-semibold text-arka-text transition hover:text-arka-primary-bright"
                            >
                                {{ member.driver.name }}
                                <span
                                    v-if="memberStats[member.driver.id]"
                                    class="rounded-full border border-arka-primary/15 bg-arka-primary/10 px-2 py-0.5 text-[10px] font-semibold text-arka-primary"
                                >
                                    {{ tierLabel(memberStats[member.driver.id].tier) }}
                                </span>
                            </Link>
                            <!-- Pedido explícito del usuario: no mostrar el teléfono acá
                                 (privacidad) — solo la tarifa. -->
                            <p v-if="member.driver.driver_profile" class="mt-1 text-xs text-arka-text-muted">
                                Tarifa · <span class="font-medium text-arka-primary">${{ member.driver.driver_profile.rate_per_km }}/km</span>
                            </p>
                            <DriverCategoryBadge
                                v-if="memberStats[member.driver.id]"
                                class="mt-1"
                                :label="memberStats[member.driver.id].public_category_label"
                            />
                        </div>
                    </div>

                    <div v-if="memberStats[member.driver.id]" class="mt-4 grid grid-cols-3 divide-x divide-arka-text-muted/10 rounded-xl border border-arka-text-muted/10 bg-arka-card/60 py-2.5 text-center">
                        <div class="px-1">
                            <p class="text-sm font-semibold text-arka-text">
                                <template v-if="memberStats[member.driver.id].review_count > 0">
                                    <span class="text-arka-lime">★</span>
                                    {{ memberStats[member.driver.id].average_rating.toFixed(1) }}
                                </template>
                                <template v-else>—</template>
                            </p>
                            <p class="mt-0.5 text-[10px] text-arka-text-muted">Calificación</p>
                        </div>
                        <div class="px-1">
                            <p class="text-sm font-semibold text-arka-text">{{ memberStats[member.driver.id].rides_count }}</p>
                            <p class="mt-0.5 text-[10px] text-arka-text-muted">Carreras</p>
                        </div>
                        <div class="px-1">
                            <p class="text-sm font-semibold text-arka-text">{{ memberStats[member.driver.id].active_clients_count }}</p>
                            <p class="mt-0.5 text-[10px] text-arka-text-muted">Clientes</p>
                        </div>
                    </div>

                    <!-- Pedir carrera y recomendar son acciones frecuentes, por
                         eso permanecen visibles. El menú conserva únicamente la
                         acción destructiva para evitar pulsaciones accidentales. -->
                    <div class="mt-4 grid grid-cols-2 items-center gap-2">
                        <Link
                            :href="route('ride-requests.create', { flota: fleet.id, conductor: member.driver.public_id })"
                            class="inline-flex h-9 min-w-0 items-center justify-center gap-1.5 rounded-xl bg-arka-primary px-2 text-[11px] font-semibold text-arka-base shadow-sm transition hover:bg-arka-primary-bright focus:outline-none focus:ring-2 focus:ring-arka-primary focus:ring-offset-2 focus:ring-offset-arka-base"
                        >
                            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16.5v-3.2a2 2 0 0 1 .2-.9l1.6-3.3a2 2 0 0 1 1.8-1.1h8.8a2 2 0 0 1 1.8 1.1l1.6 3.3a2 2 0 0 1 .2.9v3.2" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16.5h16M6 16.5v1.8M18 16.5v1.8" />
                                <circle cx="7.5" cy="14" r=".1" stroke-linecap="round" />
                                <circle cx="16.5" cy="14" r=".1" stroke-linecap="round" />
                            </svg>
                            Pedir carrera
                        </Link>

                        <a
                            v-if="member.driver.driver_profile"
                            :href="whatsappReferralUrl(member)"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex h-9 min-w-0 items-center justify-center gap-1.5 rounded-xl border border-[#25D366]/30 bg-[#25D366]/10 px-2 text-[11px] font-semibold text-[#57e389] transition hover:border-[#25D366]/50 hover:bg-[#25D366]/15 focus:outline-none focus:ring-2 focus:ring-[#25D366]/60 focus:ring-offset-2 focus:ring-offset-arka-base"
                            :aria-label="`Recomendar a ${member.driver.name} por WhatsApp`"
                        >
                            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2a10 10 0 0 0-8.6 15L2 22l5.2-1.4A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.1l-.3-.2-3.1.8.8-3-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1-.2.2-.7.8-.8 1-.1.1-.3.2-.5.1-.2-.1-1-.4-1.9-1.2-.7-.6-1.2-1.4-1.3-1.6-.1-.2 0-.4.1-.5.1-.1.2-.3.4-.4.1-.1.2-.2.2-.4.1-.1 0-.3 0-.4 0-.1-.6-1.4-.8-1.9-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.4.1-.6.3-.2.2-.8.8-.8 1.9s.8 2.2 1 2.4c.1.1 1.6 2.4 3.8 3.4.5.2.9.4 1.3.5.6.2 1.1.1 1.5.1.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.1-1.2 0-.1-.2-.2-.4-.3Z" />
                            </svg>
                            Recomendar
                        </a>
                    </div>
                </article>
            </div>
        </div>

        <!-- Invitaciones pendientes de respuesta -->
        <div v-if="invitations.length" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka border border-arka-text-muted/10">
            <h3 class="text-lg font-medium text-arka-text mb-4">Invitaciones pendientes</h3>

            <ul class="divide-y divide-arka-text-muted/10">
                <li
                    v-for="invitation in invitations"
                    :key="invitation.id"
                    class="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3"
                >
                    <!-- El conductor mandó la solicitud: le toca al cliente responder. -->
                    <template v-if="invitation.initiated_by === 'driver'">
                        <div class="min-w-0">
                            <p class="text-arka-text">
                                <span class="font-medium">{{ invitation.driver.name }}</span>
                                quiere unirse a su flota
                            </p>
                            <p v-if="invitation.message" class="text-sm text-arka-text-muted">"{{ invitation.message }}"</p>
                        </div>
                        <div class="flex gap-2 shrink-0">
                            <PrimaryButton @click="acceptFromDriver(invitation.id)">Aceptar</PrimaryButton>
                            <SecondaryButton @click="rejectFromDriver(invitation.id)">Rechazar</SecondaryButton>
                        </div>
                    </template>

                    <!-- La mandó otro cliente recomendando a este conductor para MI
                         flota ("Recomendar mi flota", pedido explícito del usuario) —
                         yo (dueño de la flota) puedo cancelarla igual que una que
                         hubiera mandado yo mismo (ver FleetInvitationPolicy::cancel()). -->
                    <template v-else-if="invitation.initiated_by === 'referral'">
                        <div class="min-w-0">
                            <p class="text-arka-text">{{ invitation.driver.name }}</p>
                            <p class="text-xs text-arka-primary">Recomendado por {{ invitation.inviter?.name }}</p>
                        </div>
                        <SecondaryButton @click="cancelInvitation(invitation.id)">Cancelar</SecondaryButton>
                    </template>

                    <!-- Invitación mandada por el propio cliente: puede cancelarla. -->
                    <template v-else>
                        <p class="text-arka-text">{{ invitation.driver.name }}</p>
                        <SecondaryButton @click="cancelInvitation(invitation.id)">Cancelar</SecondaryButton>
                    </template>
                </li>
            </ul>
        </div>
    </div>
</template>
