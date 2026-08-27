<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import SubscriptionSummary from './Partials/SubscriptionSummary.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import RatingStars from '@/Components/RatingStars.vue';
import ShareProfileQr from '@/Components/ShareProfileQr.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { canInstallApp, installApp } from '@/pwaInstall';
import { confirmDialog } from '@/Utils/confirmDialog';
import { buildClientWhatsAppOptInUrl } from '@/Utils/whatsapp';

// "Ver mi suscripción" del menú de cuenta lleva a /profile#suscripcion — el
// scroll automático de Inertia al hash no es parejo entre versiones, así que
// lo forzamos acá como respaldo.
onMounted(() => {
    if (window.location.hash) {
        document.getElementById(window.location.hash.slice(1))?.scrollIntoView({ behavior: 'smooth' });
    }
});

const props = defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    cities: {
        type: Array,
        required: true,
    },
    countryCodes: {
        type: Array,
        required: true,
    },
    subscriptionSummary: {
        type: Object,
        required: true,
    },
    profileUrl: {
        type: String,
        required: true,
    },
    // Trazabilidad de referidos (pedido explícito del usuario): tabla de
    // quiénes se registraron a través de un enlace que este usuario compartió.
    referrals: {
        type: Array,
        required: true,
    },
    // "¿Quién lo recomendó?" (pedido explícito del usuario) — null hasta que
    // quede fijado por enlace, cupón, o la búsqueda manual de más abajo.
    referredBy: { type: Object, default: null },
    // Tarjeta de perfil "profesional" (pedido explícito del usuario, mismo
    // lenguaje visual que Referral/Show.vue) — su propia reputación, igual
    // que ese conductor la mostraba.
    averageRating: { type: Number, required: true },
    reviewCount: { type: Number, required: true },
    // Avisos de sus carreras por WhatsApp (pedido explícito del usuario: "un
    // botón que le invite a escribirle al chatbot de arka01 para que de
    // allí tomemos el número y puedan estar notificados de sus viajes").
    whatsappSession: { type: Object, default: null },
    whatsappBusinessNumber: { type: String, default: null },
});

// Compartir mi perfil (pedido explícito del usuario, con captura de
// referencia): QR con el logo + mensaje prearmado para WhatsApp. El link en
// sí ya se ve "profesional" al compartirse — WhatsApp arma la tarjeta con
// foto y nombre solo, leyendo las etiquetas og:* de Profile/Show.vue (ver
// PublicProfileController::show()).
const whatsappShareUrl = computed(() => {
    const text = `¡Hola! Soy ${usePage().props.auth.user.full_name} en Arka01 \n\nMirá mi perfil:\n${props.profileUrl}`;
    return `https://wa.me/?text=${encodeURIComponent(text)}`;
});

// "¿Quién lo recomendó?" (pedido explícito del usuario): buscar por nombre,
// usuario o código, y guardar una sola vez — se queda "quemado" (el backend
// rechaza pisar un referido ya asignado, por esta vía o por enlace/cupón).
const referrerSearchTerm = ref('');
const referrerResults = ref([]);
let referrerSearchTimeout = null;
const referrerForm = useForm({ referrer_user_id: null });
const selectedReferrer = ref(null);

function searchReferrer() {
    clearTimeout(referrerSearchTimeout);
    if (referrerSearchTerm.value.trim().length < 2) {
        referrerResults.value = [];
        return;
    }
    referrerSearchTimeout = setTimeout(async () => {
        const { data } = await window.axios.get(route('profile.search-referrer'), { params: { q: referrerSearchTerm.value } });
        referrerResults.value = data.users;
    }, 300);
}

function chooseReferrer(user) {
    selectedReferrer.value = user;
    referrerForm.referrer_user_id = user.id;
    referrerResults.value = [];
    referrerSearchTerm.value = '';
}

function saveReferrer() {
    referrerForm.post(route('profile.set-referrer'), { preserveScroll: true });
}

// Avisos de sus carreras por WhatsApp (pedido explícito del usuario) — mismo
// mecanismo ya probado del lado del conductor (Driver/Profile.vue): estado
// de la ventana de 24h + link para abrirla escribiéndole al número oficial.
const WHATSAPP_STATUS_LABEL = { active: 'Activa', expiring_soon: 'Próxima a vencer', expired: 'Expirada' };
const whatsappOptInUrl = buildClientWhatsAppOptInUrl(props.whatsappBusinessNumber, usePage().props.auth.user.id);

function whatsappTimeRemaining() {
    if (!props.whatsappSession || props.whatsappSession.status === 'expired') return null;
    const ms = new Date(props.whatsappSession.expires_at) - new Date();
    const hours = Math.floor(ms / 3_600_000);
    const minutes = Math.floor((ms % 3_600_000) / 60_000);
    return `${hours}h ${minutes}min`;
}

const linkCopied = ref(false);
async function copyProfileLink() {
    await navigator.clipboard.writeText(props.profileUrl);
    linkCopied.value = true;
    setTimeout(() => (linkCopied.value = false), 2000);
}

// Pedido explícito del usuario: "Instalar app" y "Pasarme a..." también acá,
// no solo en el menú desplegable del header (mismas funciones, mismo
// criterio — ver AuthenticatedLayout.vue).
async function installAppNow() {
    const accepted = await installApp();
    if (accepted) alert('¡Listo! Ya quedó instalada.');
}

const isDriver = computed(() => usePage().props.auth.isDriver);
const isAdmin = computed(() => usePage().props.auth.user.is_admin);

// "Complete su perfil" (pedido explícito del usuario: "estructura... a que
// si no tiene sus datos completos segmentado los pueda completar") — se
// arma una fila por dato, cada una apunta al campo real del formulario de
// abajo (nunca duplica el input). País siempre queda listo: no es un campo
// editable, la plataforma opera solo en Ecuador.
const profileChecklist = computed(() => {
    const user = usePage().props.auth.user;

    return [
        { label: 'Nombre', done: true, fieldId: 'name' },
        { label: 'Apellido', done: Boolean(user.last_name), fieldId: 'last_name' },
        { label: 'Fecha de nacimiento', done: Boolean(user.birth_date), fieldId: 'birth_date' },
        { label: 'País', done: true, fieldId: null },
        { label: 'Ciudad', done: Boolean(user.city_id), fieldId: 'city_id' },
        {
            label: 'Teléfono verificado',
            done: Boolean(user.phone) && Boolean(user.phone_verified_at),
            fieldId: 'phone_local',
        },
    ];
});

function goToField(fieldId) {
    if (!fieldId) return;
    const el = document.getElementById(fieldId);
    el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el?.focus({ preventScroll: true });
}

async function switchToClient() {
    if (!(await confirmDialog('¿Pasar a cliente? Su perfil de conductor queda guardado — puede volver a activarlo cuando quiera.'))) return;
    router.post(route('driver.profile.deactivate'));
}
</script>

<template>
    <Head title="Mi perfil" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <UserAvatar :user="$page.props.auth.user" size-class="h-12 w-12 text-base" />
                <h2 class="font-semibold text-xl text-arka-text leading-tight">Mi perfil</h2>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Tarjeta de perfil (pedido explícito del usuario: "algo así
                     profesional como en las tarjetas cuando se comparten
                     referidos", y ahora "cambiale el diseño mas pro") — franja
                     de acento arriba + layout horizontal en pantallas grandes,
                     mismo criterio de card+ícono ya usado en Cooperative/Show.vue. -->
                <div class="max-w-2xl mx-auto bg-arka-card shadow-md rounded-arka overflow-hidden">
                    <div class="h-1.5 bg-gradient-to-r from-arka-primary to-arka-lime"></div>
                    <div class="p-6 sm:p-8 flex flex-col sm:flex-row items-center sm:items-start gap-5 text-center sm:text-left">
                        <UserAvatar :user="$page.props.auth.user" size-class="h-20 w-20 text-2xl shrink-0" />
                        <div class="min-w-0 flex-1">
                            <p class="text-xl font-semibold text-arka-text">{{ $page.props.auth.user.full_name }}</p>
                            <p class="text-sm text-arka-text-muted">
                                @{{ $page.props.auth.user.username }} · Socio #{{ $page.props.auth.user.member_code }}
                            </p>
                            <div class="mt-2 flex flex-wrap items-center justify-center sm:justify-start gap-2">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-medium bg-arka-primary/15 text-arka-primary-bright">
                                    🤝 Círculo de confianza
                                </span>
                                <RatingStars v-if="reviewCount > 0" :rating="averageRating" :count="reviewCount" readonly />
                            </div>
                            <p class="mt-3 text-sm text-arka-text-muted max-w-md">
                                Arme su flota de conductores de confianza y pida sus viajes dentro de ese círculo,
                                sin comisión de la plataforma.
                            </p>
                            <p class="mt-2 text-xs text-arka-text-muted">
                                Miembro desde
                                {{ new Date($page.props.auth.user.created_at).toLocaleDateString('es-EC', { dateStyle: 'long' }) }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- "Complete su perfil" (pedido explícito del usuario:
                     "estructura... a que si no tiene sus datos completos
                     segmentado los pueda completar") — desaparece sola en
                     cuanto no falta nada, mismo booleano que ya enciende el
                     puntito rojo de la nav (HandleInertiaRequests). -->
                <div v-if="$page.props.auth.isProfileIncomplete" class="max-w-2xl mx-auto p-5 sm:p-6 bg-arka-card border border-arka-danger/30 shadow-md rounded-arka">
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full bg-arka-danger shrink-0"></span>
                        <h2 class="text-lg font-medium text-arka-text">Complete su perfil</h2>
                    </div>
                    <p class="mt-1 text-sm text-arka-text-muted">
                        Le falta completar algunos datos — tocá "Completar" para ir directo al campo.
                    </p>
                    <ul class="mt-4 divide-y divide-arka-text-muted/10">
                        <li v-for="item in profileChecklist" :key="item.label" class="py-2 flex items-center justify-between gap-3">
                            <span class="flex items-center gap-2 text-sm" :class="item.done ? 'text-arka-text-muted' : 'text-arka-text'">
                                <svg v-if="item.done" class="h-4 w-4 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m5 12 5 5L20 7" />
                                </svg>
                                <span v-else class="h-4 w-4 rounded-full border-2 border-arka-text-muted/40 shrink-0"></span>
                                {{ item.label }}
                            </span>
                            <button
                                v-if="!item.done && item.fieldId"
                                type="button"
                                class="text-xs font-medium text-arka-primary hover:text-arka-primary-bright shrink-0"
                                @click="goToField(item.fieldId)"
                            >
                                Completar →
                            </button>
                        </li>
                    </ul>
                </div>

                <!-- Tarjetas sobre fondo de tarjeta (arka-card), consistente con el resto de la interfaz -->
                <div class="p-4 sm:p-8 bg-arka-card shadow sm:rounded-arka">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        :cities="cities"
                        :country-codes="countryCodes"
                        class="max-w-xl"
                    />
                </div>

                <!-- Cuenta (pedido explícito del usuario): accesos que antes
                     solo estaban en el menú desplegable del header, también
                     acá. Usuario/código de socio ya se muestran arriba, en la
                     tarjeta de perfil — sin repetirlos acá. -->
                <div class="p-4 sm:p-8 bg-arka-card shadow sm:rounded-arka">
                    <h2 class="flex items-center gap-2 text-lg font-medium text-arka-text">
                        <svg class="h-5 w-5 text-arka-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm7.4-3a7.4 7.4 0 0 1-.1 1.2l2 1.6-2 3.4-2.4-1a7.5 7.5 0 0 1-2 1.2l-.4 2.6H9.5l-.4-2.6a7.5 7.5 0 0 1-2-1.2l-2.4 1-2-3.4 2-1.6a7.4 7.4 0 0 1 0-2.4l-2-1.6 2-3.4 2.4 1a7.5 7.5 0 0 1 2-1.2L9.5 2h5l.4 2.6a7.5 7.5 0 0 1 2 1.2l2.4-1 2 3.4-2 1.6c.07.4.1.8.1 1.2Z" />
                        </svg>
                        Cuenta
                    </h2>
                    <!-- Pedido explícito del usuario ("¿este código es el mismo con
                         el que me buscan?"): aclarado a propósito — el código de
                         socio de arriba es distinto al del link de invitación (más
                         largo, ver "Compartir mi perfil" abajo). -->
                    <p class="mt-1 text-sm text-arka-text-muted">
                        Con su código de socio (arriba) lo encuentran en el buscador de flotas.
                    </p>

                    <!-- Verificar teléfono (pedido explícito del usuario: que el
                         cliente también pueda, no solo quedar bloqueado esperando
                         a que el sistema se lo exija de golpe). -->
                    <p v-if="$page.props.auth.user.phone && !$page.props.auth.user.phone_verified_at" class="mt-4 text-sm text-arka-warning">
                        📵 Su teléfono todavía no está verificado.
                        <Link :href="route('phone.verify.show')" class="underline hover:opacity-80 font-medium">
                            Verificar ahora
                        </Link>
                    </p>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <SecondaryButton v-if="canInstallApp" @click="installAppNow">Instalar app</SecondaryButton>
                        <Link
                            v-if="!isAdmin && !isDriver"
                            :href="route('driver.profile.edit')"
                            class="inline-flex items-center px-4 py-2 bg-arka-card border border-arka-text-muted/30 rounded-arka font-semibold text-xs text-arka-text uppercase tracking-widest shadow-sm hover:bg-arka-base focus:outline-none focus:ring-2 focus:ring-arka-primary focus:ring-offset-2 focus:ring-offset-arka-base transition ease-in-out duration-150"
                        >
                            Pasarme a conductor
                        </Link>
                        <SecondaryButton v-if="!isAdmin && isDriver" @click="switchToClient">Pasarme a cliente</SecondaryButton>
                    </div>
                </div>

                <!-- Avisos de sus carreras por WhatsApp (pedido explícito del
                     usuario: "un botón... que le invite a escribirle al chatbot
                     de arka01 para que de allí tomemos el número y que ellos
                     puedan estar notificados de sus viajes") — solo para
                     clientes: un conductor ya tiene su propia versión de esto,
                     con otro mensaje, en Driver/Profile.vue. -->
                <div v-if="!isAdmin && !isDriver && whatsappBusinessNumber" class="p-4 sm:p-8 bg-arka-card shadow sm:rounded-arka">
                    <div class="flex items-start gap-3">
                        <div class="shrink-0 h-10 w-10 rounded-full bg-arka-primary/15 grid place-items-center">
                            <svg class="h-5 w-5 text-arka-primary-bright" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2a10 10 0 0 0-8.6 15L2 22l5.2-1.4A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.1l-.3-.2-3.1.8.8-3-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1-.2.2-.7.8-.8 1-.1.1-.3.2-.5.1-.2-.1-1-.4-1.9-1.2-.7-.6-1.2-1.4-1.3-1.6-.1-.2 0-.4.1-.5.1-.1.2-.3.4-.4.1-.1.2-.2.2-.4.1-.1 0-.3 0-.4 0-.1-.6-1.4-.8-1.9-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.4.1-.6.3-.2.2-.8.8-.8 1.9s.8 2.2 1 2.4c.1.1 1.6 2.4 3.8 3.4.5.2.9.4 1.3.5.6.2 1.1.1 1.5.1.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.1-1.2 0-.1-.2-.2-.4-.3Z" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-lg font-medium text-arka-text">Avisos de sus viajes por WhatsApp</h2>
                            <p class="mt-1 text-sm text-arka-text-muted max-w-xl">
                                Entérese apenas un conductor acepta, sale a buscarlo, llega y termina el viaje —
                                sin tener la app abierta.
                            </p>
                            <p class="mt-2 text-sm text-arka-text-muted">
                                <template v-if="whatsappSession && whatsappSession.status !== 'expired'">
                                    Ventana {{ WHATSAPP_STATUS_LABEL[whatsappSession.status] }} — le quedan {{ whatsappTimeRemaining() }}.
                                </template>
                                <template v-else>
                                    {{ whatsappSession ? 'Su ventana de WhatsApp expiró' : 'Todavía no conectó WhatsApp' }}.
                                </template>
                            </p>
                            <a
                                :href="whatsappOptInUrl"
                                target="_blank"
                                rel="noopener"
                                class="mt-3 inline-flex items-center gap-2 px-4 py-2 rounded-arka bg-arka-primary text-arka-base text-sm font-semibold hover:bg-arka-primary-bright transition"
                            >
                                {{ whatsappSession && whatsappSession.status !== 'expired' ? 'Renovar por WhatsApp' : 'Conectar WhatsApp' }} &rarr;
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Compartir mi perfil (pedido explícito del usuario): QR con el
                     logo + WhatsApp, para que otros lo escaneen o lo abran desde un
                     enlace que se ve profesional al compartirse. -->
                <div class="p-4 sm:p-8 bg-arka-card shadow sm:rounded-arka">
                    <h2 class="flex items-center gap-2 text-lg font-medium text-arka-text">
                        <svg class="h-5 w-5 text-arka-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.68 13.34a3 3 0 1 1 0-2.68m0 2.68 6.63 3.32m-6.63-6 6.63-3.32m0 0a3 3 0 1 0-5.37-2.68 3 3 0 0 0 5.37 2.68Zm0 9.32a3 3 0 1 0-5.37 2.68 3 3 0 0 0 5.37-2.68Z" />
                        </svg>
                        Compartir mi perfil
                    </h2>
                    <p class="mt-1 text-sm text-arka-text-muted max-w-xl">
                        Para que alguien lo agregue a su flota o vea sus calificaciones sin buscarlo a mano —
                        escaneando el código o abriendo el enlace.
                    </p>

                    <div class="mt-4 flex flex-col sm:flex-row items-start sm:items-center gap-6">
                        <ShareProfileQr :url="profileUrl" />

                        <div class="flex flex-col gap-2">
                            <a
                                :href="whatsappShareUrl"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-2 px-4 py-2 rounded-arka bg-arka-primary text-arka-base text-sm font-semibold hover:bg-arka-primary-bright transition"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12 2a10 10 0 0 0-8.6 15L2 22l5.2-1.4A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.1l-.3-.2-3.1.8.8-3-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1-.2.2-.7.8-.8 1-.1.1-.3.2-.5.1-.2-.1-1-.4-1.9-1.2-.7-.6-1.2-1.4-1.3-1.6-.1-.2 0-.4.1-.5.1-.1.2-.3.4-.4.1-.1.2-.2.2-.4.1-.1 0-.3 0-.4 0-.1-.6-1.4-.8-1.9-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.4.1-.6.3-.2.2-.8.8-.8 1.9s.8 2.2 1 2.4c.1.1 1.6 2.4 3.8 3.4.5.2.9.4 1.3.5.6.2 1.1.1 1.5.1.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.1-1.2 0-.1-.2-.2-.4-.3Z" />
                                </svg>
                                Compartir por WhatsApp
                            </a>
                            <SecondaryButton @click="copyProfileLink">
                                {{ linkCopied ? '¡Copiado!' : 'Copiar enlace' }}
                            </SecondaryButton>
                        </div>
                    </div>
                </div>

                <!-- "¿Quién lo recomendó?" (pedido explícito del usuario: "por
                     cupones, por enlaces de referidos o por ingresos
                     manuales pueden obtener referidos") — esta es la tercera
                     vía, manual: buscar y guardar una sola vez. Si ya vino
                     por enlace o cupón, esto ya aparece resuelto y de solo
                     lectura. -->
                <div class="p-4 sm:p-8 bg-arka-card shadow sm:rounded-arka">
                    <h2 class="flex items-center gap-2 text-lg font-medium text-arka-text">
                        <svg class="h-5 w-5 text-arka-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21c-4.97-3.14-8-6.5-8-10a5 5 0 0 1 9-3 5 5 0 0 1 9 3c0 3.5-3.03 6.86-8 10Z" />
                        </svg>
                        ¿Quién lo recomendó?
                    </h2>

                    <div v-if="referredBy" class="mt-3 max-w-xl">
                        <p class="text-sm text-arka-text">
                            Fue recomendado por <span class="font-medium">{{ referredBy.name }}</span>
                            <span v-if="referredBy.username" class="text-arka-text-muted"> · @{{ referredBy.username }}</span>
                        </p>
                    </div>

                    <div v-else class="mt-3 max-w-xl space-y-3">
                        <p class="text-sm text-arka-text-muted">
                            Si alguien de Arka01 lo invitó pero no quedó registrado solo, búsquelo acá y guárdelo — una vez
                            guardado, no se puede cambiar.
                        </p>

                        <div v-if="!selectedReferrer">
                            <TextInput
                                v-model="referrerSearchTerm"
                                class="w-full"
                                placeholder="Nombre, usuario o código de socio"
                                @input="searchReferrer"
                            />
                            <ul v-if="referrerResults.length" class="mt-2 divide-y divide-arka-text-muted/10 rounded-arka border border-arka-text-muted/10">
                                <li
                                    v-for="candidate in referrerResults"
                                    :key="candidate.id"
                                    class="cursor-pointer p-2 text-sm text-arka-text hover:bg-arka-base"
                                    @click="chooseReferrer(candidate)"
                                >
                                    {{ candidate.name }}
                                    <span class="text-arka-text-muted">
                                        <span v-if="candidate.username">· @{{ candidate.username }}</span>
                                        <span v-if="candidate.member_code"> · #{{ candidate.member_code }}</span>
                                    </span>
                                </li>
                            </ul>
                        </div>

                        <div v-else class="flex items-center gap-2 flex-wrap">
                            <span class="text-sm text-arka-text">
                                {{ selectedReferrer.name }}
                                <span v-if="selectedReferrer.username" class="text-arka-text-muted">· @{{ selectedReferrer.username }}</span>
                            </span>
                            <SecondaryButton @click="selectedReferrer = null; referrerForm.referrer_user_id = null;">Cambiar</SecondaryButton>
                            <PrimaryButton :disabled="referrerForm.processing" @click="saveReferrer">Guardar</PrimaryButton>
                        </div>
                        <InputError :message="referrerForm.errors.referrer_user_id" />
                    </div>
                </div>

                <!-- Trazabilidad de referidos (pedido explícito del usuario):
                     quiénes se registraron en Arka01 a través de un enlace que
                     este usuario compartió (invitación de flota o perfil
                     público) — solo se muestra si tiene al menos uno. -->
                <div v-if="referrals.length" class="p-4 sm:p-8 bg-arka-card shadow sm:rounded-arka">
                    <h2 class="flex items-center gap-2 text-lg font-medium text-arka-text">
                        <svg class="h-5 w-5 text-arka-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 20a3 3 0 0 0-3-3H9a3 3 0 0 0-3 3M12 14a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm7 5v-1a3 3 0 0 0-2.25-2.9M17 7.3A3.5 3.5 0 0 1 17 14" />
                        </svg>
                        Mis referidos
                    </h2>
                    <p class="mt-1 text-sm text-arka-text-muted max-w-xl">
                        Personas que se registraron en Arka01 a través de un enlace que usted compartió.
                    </p>

                    <div class="mt-4 overflow-x-auto max-w-xl">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-arka-text-muted border-b border-arka-text-muted/10">
                                    <th class="py-2 pr-3">Nombre</th>
                                    <th class="py-2 pr-3">Rol</th>
                                    <th class="py-2 pr-3">Se unió</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-arka-text-muted/10">
                                <tr v-for="referral in referrals" :key="referral.id">
                                    <td class="py-2 pr-3 text-arka-text font-medium">{{ referral.name }}</td>
                                    <td class="py-2 pr-3 text-arka-text-muted">{{ referral.role }}</td>
                                    <td class="py-2 pr-3 text-arka-text-muted">
                                        {{ new Date(referral.registered_at).toLocaleDateString('es-EC', { dateStyle: 'medium' }) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="Object.keys(subscriptionSummary).length" class="p-4 sm:p-8 bg-arka-card shadow sm:rounded-arka">
                    <SubscriptionSummary :summary="subscriptionSummary" class="max-w-xl" />
                </div>

                <div class="p-4 sm:p-8 bg-arka-card shadow sm:rounded-arka">
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div class="p-4 sm:p-8 bg-arka-card shadow sm:rounded-arka">
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
