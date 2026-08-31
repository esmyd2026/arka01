<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import SubscriptionSummary from './Partials/SubscriptionSummary.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import RatingStars from '@/Components/RatingStars.vue';
import SectionIcon from '@/Components/SectionIcon.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref } from 'vue';
import { canInstallApp, installApp } from '@/pwaInstall';
import { confirmDialog } from '@/Utils/confirmDialog';
import { buildClientWhatsAppOptInUrl } from '@/Utils/whatsapp';

// "Ver mi suscripción" del menú de cuenta lleva a /profile#suscripcion — el
// scroll automático de Inertia al hash no es parejo entre versiones, así que
// lo forzamos acá como respaldo.
onMounted(() => {
    if (window.location.hash) {
        const targetId = window.location.hash.slice(1);
        if (targetId === 'suscripcion') accountSection.value = 'subscription';
        nextTick(() => document.getElementById(targetId)?.scrollIntoView({ behavior: 'smooth' }));
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
    trustIndex: { type: Object, required: true },
    // Avisos de sus carreras por WhatsApp (pedido explícito del usuario: "un
    // botón que le invite a escribirle al chatbot de arka01 para que de
    // allí tomemos el número y puedan estar notificados de sus viajes").
    whatsappSession: { type: Object, default: null },
    whatsappBusinessNumber: { type: String, default: null },
});

// Compartir mi perfil (pedido explícito del usuario: "mejora el boton...
// para que llegue un card... con llamada a la accion diciendo que hola yo
// estoy usando arka01 unete y haz que la movilidad sea ahora mas segura").
// El link en sí ya se ve "profesional" al compartirse — WhatsApp arma la
// tarjeta con foto y descripción solo, leyendo las etiquetas og:* de
// Profile/Show.vue (ver PublicProfileController::show() para el caso sin
// sesión de WhatsApp, que usa la misma copia). La atribución del referido no
// depende de nada en este texto ni en la URL: quien se registre desde el
// perfil de este usuario ya queda marcado como su referido (ver
// Profile/Show.vue, botones "Crear cuenta"/"creá una cuenta").
const whatsappProfileShareUrl = computed(() => {
    const name = usePage().props.auth.user.full_name;
    const text = `¡Hola! Soy ${name}. Mi índice de confianza en Arka01 es ${props.trustIndex.score}%. Este es mi perfil público para que pueda conocerme y verificar mi información:\n${props.profileUrl}`;
    return `https://wa.me/?text=${encodeURIComponent(text)}`;
});

const whatsappReferralUrl = computed(() => {
    const name = usePage().props.auth.user.full_name;
    const text = `¡Hola! ${name} le invita a unirse a Arka01 🚖, una comunidad de movilidad basada en personas de confianza.\n\nRegístrese desde este enlace para que la recomendación quede asociada:\n${props.profileUrl}`;
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

// Pedido explícito del usuario: "Instalar app" y "Pasarme a..." también acá,
// no solo en el menú desplegable del header (mismas funciones, mismo
// criterio — ver AuthenticatedLayout.vue).
async function installAppNow() {
    const accepted = await installApp();
    if (accepted) alert('¡Listo! Ya quedó instalada.');
}

const isDriver = computed(() => usePage().props.auth.isDriver);
const isAdmin = computed(() => usePage().props.auth.user.is_admin);

function trustScoreStyle(score) {
    return { '--profile-trust-score': `${Math.max(0, Math.min(100, score)) * 3.6}deg` };
}

// La cuenta reúne información personal, preferencias, referidos, plan y
// seguridad. Mantener un solo bloque abierto evita una página interminable
// sin desmontar formularios ni perder datos escritos.
const accountSection = ref(usePage().props.auth.isProfileIncomplete ? 'personal' : null);
const toggleAccountSection = (section) => {
    accountSection.value = accountSection.value === section ? null : section;
};

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
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <p class="text-xl font-semibold text-arka-text">{{ $page.props.auth.user.full_name }}</p>
                                    <p class="text-sm text-arka-text-muted">
                                        @{{ $page.props.auth.user.username }} · Socio #{{ $page.props.auth.user.member_code }}
                                    </p>
                                    <div class="mt-2 flex flex-wrap items-center justify-center gap-2 sm:justify-start">
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-arka-primary/15 px-2.5 py-0.5 text-xs font-medium text-arka-primary-bright">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm13 10v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
                                            </svg>
                                            Círculo de confianza
                                        </span>
                                        <RatingStars v-if="reviewCount > 0" :rating="averageRating" :count="reviewCount" readonly />
                                    </div>
                                    <p class="mt-2 text-xs text-arka-text-muted">
                                        Miembro desde
                                        {{ new Date($page.props.auth.user.created_at).toLocaleDateString('es-EC', { dateStyle: 'long' }) }}
                                    </p>
                                </div>

                                <Link
                                    :href="route('trust-circle.index')"
                                    class="group shrink-0 text-center"
                                    :aria-label="`Ver índice de confianza: ${trustIndex.score}%, nivel ${trustIndex.level}`"
                                >
                                    <div class="profile-trust-ring" :style="trustScoreStyle(trustIndex.score)">
                                        <!-- Pedido explícito del usuario: "no coloques 50/100 solo deja
                                             el 50%". -->
                                        <div><strong>{{ trustIndex.score }}%</strong></div>
                                    </div>
                                    <span class="mt-1.5 block text-[10px] font-semibold text-arka-text-muted transition group-hover:text-arka-primary">
                                        Índice de confianza
                                    </span>
                                </Link>
                            </div>

                            <!-- Misma tarjeta y mismas acciones para cliente y
                                 conductor: compartir el perfil no es lo mismo
                                 que invitar a una persona nueva a Arka01. -->
                            <div class="mt-4 grid w-full grid-cols-1 gap-2 sm:grid-cols-2">
                                <a
                                    :href="whatsappProfileShareUrl"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-arka-primary px-4 py-2.5 text-sm font-semibold text-arka-base transition hover:bg-arka-primary-bright"
                                >
                                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M12 2a10 10 0 0 0-8.6 15L2 22l5.2-1.4A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.1l-.3-.2-3.1.8.8-3-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1-.2.2-.7.8-.8 1-.1.1-.3.2-.5.1-.2-.1-1-.4-1.9-1.2-.7-.6-1.2-1.4-1.3-1.6-.1-.2 0-.4.1-.5.1-.1.2-.3.4-.4.1-.1.2-.2.2-.4.1-.1 0-.3 0-.4 0-.1-.6-1.4-.8-1.9-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.4.1-.6.3-.2.2-.8.8-.8 1.9s.8 2.2 1 2.4c.1.1 1.6 2.4 3.8 3.4.5.2.9.4 1.3.5.6.2 1.1.1 1.5.1.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.1-1.2 0-.1-.2-.2-.4-.3Z" />
                                    </svg>
                                    Compartir mi perfil
                                </a>
                                <a
                                    :href="whatsappReferralUrl"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-arka-primary/40 bg-arka-primary/10 px-4 py-2.5 text-sm font-semibold text-arka-primary-bright transition hover:bg-arka-primary/20"
                                >
                                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
                                        <circle cx="12" cy="12" r="9" />
                                    </svg>
                                    Referir Arka01
                                </a>
                            </div>
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

                <!-- Secciones operativas de la cuenta: una sola abierta para
                     que en móvil se encuentre cada ajuste sin recorrer toda
                     la página. -->
                <section class="overflow-hidden rounded-2xl border border-arka-text-muted/10 bg-arka-card shadow-sm">
                    <button type="button" class="flex w-full items-center gap-3 p-4 text-left sm:p-5" @click="toggleAccountSection('personal')">
                        <SectionIcon name="user" />
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold text-arka-text">Datos personales</span>
                            <span class="mt-0.5 block text-xs" :class="$page.props.auth.isProfileIncomplete ? 'text-arka-warning' : 'text-arka-primary'">
                                {{ $page.props.auth.isProfileIncomplete ? 'Hay información pendiente de completar' : 'Información actualizada' }}
                            </span>
                        </span>
                        <svg class="h-5 w-5 shrink-0 text-arka-text-muted transition-transform" :class="accountSection === 'personal' ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                        </svg>
                    </button>
                <div v-show="accountSection === 'personal'" class="border-t border-arka-text-muted/10 p-4 sm:p-6">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        :cities="cities"
                        :country-codes="countryCodes"
                        class="max-w-xl"
                    />
                </div>
                </section>

                <!-- Cuenta (pedido explícito del usuario): accesos que antes
                     solo estaban en el menú desplegable del header, también
                     acá. Usuario/código de socio ya se muestran arriba, en la
                     tarjeta de perfil — sin repetirlos acá. -->
                <section class="overflow-hidden rounded-2xl border border-arka-text-muted/10 bg-arka-card shadow-sm">
                    <button type="button" class="flex w-full items-center gap-3 p-4 text-left sm:p-5" @click="toggleAccountSection('account')">
                        <SectionIcon name="settings" />
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold text-arka-text">Cuenta y preferencias</span>
                            <span class="mt-0.5 block text-xs text-arka-text-muted">
                                {{ isDriver ? 'Cuenta conductora' : isAdmin ? 'Cuenta administrativa' : 'Cuenta cliente' }} · Aplicación y WhatsApp
                            </span>
                        </span>
                        <svg class="h-5 w-5 shrink-0 text-arka-text-muted transition-transform" :class="accountSection === 'account' ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                        </svg>
                    </button>
                    <div v-show="accountSection === 'account'" class="space-y-6 border-t border-arka-text-muted/10 p-4 sm:p-6">
                <div>
                    <h2 class="flex items-center gap-2 text-lg font-medium text-arka-text">
                        <svg class="h-5 w-5 text-arka-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm7.4-3a7.4 7.4 0 0 1-.1 1.2l2 1.6-2 3.4-2.4-1a7.5 7.5 0 0 1-2 1.2l-.4 2.6H9.5l-.4-2.6a7.5 7.5 0 0 1-2-1.2l-2.4 1-2-3.4 2-1.6a7.4 7.4 0 0 1 0-2.4l-2-1.6 2-3.4 2.4 1a7.5 7.5 0 0 1 2-1.2L9.5 2h5l.4 2.6a7.5 7.5 0 0 1 2 1.2l2.4-1 2 3.4-2 1.6c.07.4.1.8.1 1.2Z" />
                        </svg>
                        Cuenta
                    </h2>
                    <!-- Pedido explícito del usuario ("¿este código es el mismo con
                         el que me buscan?"): aclarado a propósito — el código de
                         socio de arriba es distinto al enlace público que se
                         comparte desde la tarjeta superior. -->
                    <p class="mt-1 text-sm text-arka-text-muted">
                        Con su código de socio (arriba) lo encuentran en el buscador de flotas.
                    </p>

                    <!-- Verificar teléfono (pedido explícito del usuario: que el
                         cliente también pueda, no solo quedar bloqueado esperando
                         a que el sistema se lo exija de golpe). -->
                    <p v-if="$page.props.auth.user.phone && !$page.props.auth.user.phone_verified_at" class="mt-4 flex flex-wrap items-center gap-1.5 text-sm text-arka-warning">
                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 2h10a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm3 16h4M3 3l18 18" />
                        </svg>
                        Su teléfono todavía no está verificado.
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

                    </div>
                </section>

                <!-- "¿Quién lo recomendó?" (pedido explícito del usuario: "por
                     cupones, por enlaces de referidos o por ingresos
                     manuales pueden obtener referidos") — esta es la tercera
                     vía, manual: buscar y guardar una sola vez. Si ya vino
                     por enlace o cupón, esto ya aparece resuelto y de solo
                     lectura. -->
                <section class="overflow-hidden rounded-2xl border border-arka-text-muted/10 bg-arka-card shadow-sm">
                    <button type="button" class="flex w-full items-center gap-3 p-4 text-left sm:p-5" @click="toggleAccountSection('referrals')">
                        <SectionIcon name="referrals" />
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold text-arka-text">Recomendaciones</span>
                            <span class="mt-0.5 block text-xs text-arka-text-muted">
                                {{ referredBy ? `Recomendado por ${referredBy.name}` : 'Registre quién lo invitó' }} · {{ referrals.length }} referido{{ referrals.length === 1 ? '' : 's' }}
                            </span>
                        </span>
                        <svg class="h-5 w-5 shrink-0 text-arka-text-muted transition-transform" :class="accountSection === 'referrals' ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                        </svg>
                    </button>
                    <div v-show="accountSection === 'referrals'" class="space-y-6 border-t border-arka-text-muted/10 p-4 sm:p-6">
                <div>
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
                    </div>
                </section>

                <section v-if="Object.keys(subscriptionSummary).length" class="overflow-hidden rounded-2xl border border-arka-text-muted/10 bg-arka-card shadow-sm">
                    <button type="button" class="flex w-full items-center gap-3 p-4 text-left sm:p-5" @click="toggleAccountSection('subscription')">
                        <SectionIcon name="subscription" />
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold text-arka-text">Mi suscripción</span>
                            <span class="mt-0.5 block text-xs text-arka-text-muted">Plan vigente, límites y opciones para mejorar</span>
                        </span>
                        <svg class="h-5 w-5 shrink-0 text-arka-text-muted transition-transform" :class="accountSection === 'subscription' ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                        </svg>
                    </button>
                <div v-show="accountSection === 'subscription'" class="border-t border-arka-text-muted/10 p-4 sm:p-6">
                    <SubscriptionSummary :summary="subscriptionSummary" class="max-w-xl" />
                </div>
                </section>

                <section class="overflow-hidden rounded-2xl border border-arka-text-muted/10 bg-arka-card shadow-sm">
                    <button type="button" class="flex w-full items-center gap-3 p-4 text-left sm:p-5" @click="toggleAccountSection('security')">
                        <SectionIcon name="security" />
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold text-arka-text">Seguridad</span>
                            <span class="mt-0.5 block text-xs text-arka-text-muted">Cambiar contraseña o eliminar la cuenta</span>
                        </span>
                        <svg class="h-5 w-5 shrink-0 text-arka-text-muted transition-transform" :class="accountSection === 'security' ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                        </svg>
                    </button>
                    <div v-show="accountSection === 'security'" class="space-y-8 border-t border-arka-text-muted/10 p-4 sm:p-6">
                <div>
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div class="border-t border-arka-text-muted/10 pt-6">
                    <DeleteUserForm class="max-w-xl" />
                </div>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.profile-trust-ring {
    position: relative;
    display: grid;
    width: 88px;
    height: 88px;
    place-items: center;
    border-radius: 50%;
    background: conic-gradient(#34d399 var(--profile-trust-score), rgba(255, 255, 255, 0.08) 0);
}

.profile-trust-ring::before {
    position: absolute;
    inset: 7px;
    border-radius: inherit;
    background: #10231b;
    content: '';
}

.profile-trust-ring > div {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: baseline;
}

.profile-trust-ring strong {
    color: #e7f4ee;
    font-size: 1.6rem;
}

.profile-trust-ring small {
    color: #93ada2;
    font-size: 0.65rem;
}

@media (max-width: 420px) {
    .profile-trust-ring {
        width: 76px;
        height: 76px;
    }

    .profile-trust-ring strong {
        font-size: 1.35rem;
    }
}
</style>
