<script setup>
import { computed, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { confirmDialog } from '@/Utils/confirmDialog';
import { tierLabel } from '@/Utils/tierBadge';

const props = defineProps({
    fleet: { type: Object, required: true },
    // null = sin límite (plan Multi-flota con cupo personalizado).
    maxDriversPerFleet: { type: Number, default: null },
    // Pedido explícito del usuario: mismos datos que ya se ven al buscar
    // (calificación, carreras completadas, categoría) también en "Conductores
    // en tu flota" — objeto keyed por user_id, ver FleetController::show().
    memberStats: { type: Object, required: true },
});

// "Referí a tu conductor" (pedido explícito del usuario): un enlace público
// con el mismo invite_code que ya tiene el conductor, para recomendarlo a
// otras personas — Web Share API si el navegador la soporta (celular), si no
// se copia al portapapeles (mismo criterio que "Compartí tu código" del
// inicio del conductor).
const referredMemberId = ref(null);

async function referDriver(member) {
    const url = route('referrals.show', member.driver.driver_profile.invite_code);
    const shareData = { title: `Viaje con ${member.driver.name} en Arka01`, url };

    if (navigator.share) {
        try {
            await navigator.share(shareData);
        } catch {
            // El usuario cerró el panel de compartir sin elegir nada — no es un error.
        }
        return;
    }

    await navigator.clipboard.writeText(url);
    referredMemberId.value = member.id;
    setTimeout(() => (referredMemberId.value = null), 2000);
}

// --- Buscador tipo red social (sección 3.2): nombre, teléfono o código de invitación ---
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

const showNotRegisteredYet = computed(
    () => !searching.value && lastSearchedTerm.value === searchTerm.value && searchResults.value.length === 0
);

// Invitar a alguien que TODAVÍA NO tiene cuenta en Arka01 (pedido explícito
// del usuario, caso real: buscó un conductor por nombre y no existía) —
// comparte un link genérico de registro, no el invite_code de nadie (ese es
// personal de un conductor que ya existe, ver referDriver() arriba).
//
// Bug reportado por el usuario: el link salía DUPLICADO en WhatsApp. Pasaba
// porque navigator.share() recibía el link metido adentro del texto Y de
// nuevo aparte en `url` — muchas apps (WhatsApp incluida) arman el mensaje
// final concatenando texto + url, así que si ya estaba en el texto, quedaba
// dos veces. Ahora el texto NUNCA lleva el link adentro: para wa.me se arma
// un solo string con el link al final (una sola vez); para compartir
// genérico, el link va solo en el campo `url`, nunca repetido en `text`.
// También se sacó el emoji 🚗 (reportado como un ícono roto/"�" en algunos
// teléfonos — no vale la pena el riesgo por un detalle cosmético).
function inviteMessageText() {
    return '¡Hola! Le invito a unirse a Arka01, la app para armar su propia flota de conductores de confianza.';
}

function shareInviteByWhatsApp() {
    const message = `${inviteMessageText()} Regístrese acá: ${route('register')}`;
    window.open(`https://wa.me/?text=${encodeURIComponent(message)}`, '_blank');
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
};

const removeMember = async (memberId) => {
    if (!(await confirmDialog('¿Seguro que quiere sacar a este conductor de su flota?', { danger: true }))) return;

    router.delete(route('fleet.members.destroy', memberId), { preserveScroll: true });
};

const memberCount = props.fleet.active_members?.length ?? 0;
const atLimit = props.maxDriversPerFleet !== null && memberCount >= props.maxDriversPerFleet;
</script>

<template>
    <Head :title="fleet.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <Link :href="route('fleet.index')" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                        &larr; Mis flotas
                    </Link>
                    <h2 class="font-semibold text-xl text-arka-text leading-tight">{{ fleet.name }}</h2>
                </div>
                <span class="text-sm text-arka-text-muted">
                    {{ memberCount }} de {{ maxDriversPerFleet ?? '∞' }} conductores
                </span>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div v-if="memberCount > 0" class="flex justify-end">
                    <Link :href="route('ride-requests.create', { flota: fleet.id })">
                        <PrimaryButton>Pedir una carrera</PrimaryButton>
                    </Link>
                </div>

                <!-- Buscador para invitar conductores -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <InputLabel value="Buscar conductor por nombre, teléfono, usuario o código" />
                    <TextInput
                        v-model="searchTerm"
                        type="text"
                        class="mt-1 block w-full"
                        placeholder="Ej: Juan Pérez, 09..., jperez, 512 o el código de invitación"
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
                                        {{ driver.phone }} · ${{ driver.rate_per_km }}/km
                                        <span v-if="driver.username">· @{{ driver.username }}</span>
                                    </p>
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
                    <div v-if="showNotRegisteredYet" class="mt-4 p-4 rounded-arka bg-arka-base/60">
                        <p class="text-sm text-arka-text">
                            "{{ searchTerm }}" todavía no está registrado en Arka01.
                        </p>
                        <p class="mt-1 text-sm text-arka-text-muted">
                            Invítelo a sumarse — apenas cree su cuenta, lo va a poder agregar a su flota desde acá.
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <SecondaryButton @click="shareInviteByWhatsApp">📲 Invitar por WhatsApp</SecondaryButton>
                            <SecondaryButton @click="shareInviteGeneric">
                                {{ inviteLinkCopied ? 'Enlace copiado' : 'Compartir invitación' }}
                            </SecondaryButton>
                        </div>
                    </div>
                </div>

                <!-- Conductores activos en la flota -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-4">Conductores en su flota</h3>

                    <p v-if="!fleet.active_members?.length" class="text-sm text-arka-text-muted">
                        Todavía no tiene conductores en esta flota. Busque a alguien de confianza arriba para
                        invitarlo, o mire el
                        <Link :href="route('directory.index')" class="text-arka-primary hover:text-arka-primary-bright">
                            directorio de conductores públicos
                        </Link>.
                    </p>

                    <ul v-else class="divide-y divide-arka-text-muted/10">
                        <li
                            v-for="member in fleet.active_members"
                            :key="member.id"
                            class="py-3 flex items-center justify-between gap-4"
                        >
                            <div class="flex items-center gap-3 min-w-0">
                                <UserAvatar :user="member.driver" size-class="h-11 w-11 text-sm shrink-0" />
                                <div class="min-w-0">
                                    <Link
                                        :href="route('profiles.show', member.driver.id)"
                                        class="text-arka-text font-medium hover:text-arka-primary-bright flex items-center gap-1.5 flex-wrap"
                                    >
                                        {{ member.driver.name }}
                                        <span
                                            v-if="memberStats[member.driver.id]"
                                            class="text-xs"
                                        >
                                            {{ tierLabel(memberStats[member.driver.id].tier) }}
                                        </span>
                                    </Link>
                                    <p class="text-sm text-arka-text-muted">
                                        {{ member.driver.phone }}
                                        <span v-if="member.driver.driver_profile">
                                            · ${{ member.driver.driver_profile.rate_per_km }}/km
                                        </span>
                                    </p>
                                    <p v-if="memberStats[member.driver.id]" class="text-xs text-arka-text-muted">
                                        <span v-if="memberStats[member.driver.id].review_count > 0" class="text-arka-lime">
                                            ★ {{ memberStats[member.driver.id].average_rating.toFixed(1) }}
                                        </span>
                                        <span v-else>Sin calificaciones</span>
                                        · {{ memberStats[member.driver.id].rides_count }} carrera(s) completada(s)
                                        · {{ memberStats[member.driver.id].active_clients_count }} cliente(s)
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                <!-- Pedido explícito del usuario: elegir un conductor de
                                     la flota tiene que ofrecer pedirle una carrera directo.
                                     Mismas clases que SecondaryButton, pero es una navegación
                                     (Link → <a>), no puede envolver un <button> adentro. -->
                                <Link
                                    :href="route('ride-requests.create', { flota: fleet.id, conductor: member.driver.id })"
                                    class="inline-flex items-center px-4 py-2 bg-arka-card border border-arka-text-muted/30 rounded-arka font-semibold text-xs text-arka-text uppercase tracking-widest shadow-sm hover:bg-arka-base focus:outline-none focus:ring-2 focus:ring-arka-primary focus:ring-offset-2 focus:ring-offset-arka-base transition ease-in-out duration-150"
                                >
                                    Pedir carrera
                                </Link>
                                <SecondaryButton
                                    v-if="member.driver.driver_profile"
                                    @click="referDriver(member)"
                                >
                                    {{ referredMemberId === member.id ? 'Enlace copiado' : 'Referir' }}
                                </SecondaryButton>
                                <DangerButton @click="removeMember(member.id)">Sacar</DangerButton>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Invitaciones pendientes de respuesta -->
                <div v-if="fleet.invitations?.length" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-4">Invitaciones pendientes</h3>

                    <ul class="divide-y divide-arka-text-muted/10">
                        <li
                            v-for="invitation in fleet.invitations"
                            :key="invitation.id"
                            class="py-3 flex items-center justify-between gap-4"
                        >
                            <p class="text-arka-text">{{ invitation.driver.name }}</p>
                            <SecondaryButton @click="cancelInvitation(invitation.id)">Cancelar</SecondaryButton>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
