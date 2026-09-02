<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import QRCode from 'qrcode';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import Checkbox from '@/Components/Checkbox.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import RatingStars from '@/Components/RatingStars.vue';
import SessionDataUsage from '@/Components/SessionDataUsage.vue';
import SectionIcon from '@/Components/SectionIcon.vue';
import DriverCategoryBadge from '@/Components/DriverCategoryBadge.vue';
import DriverAvailabilityToggle from '@/Components/DriverAvailabilityToggle.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { buildWhatsAppOptInUrl } from '@/Utils/whatsapp';
import { tierColorClass, tierLabel } from '@/Utils/tierBadge';
import { canInstallApp, installApp } from '@/pwaInstall';
import { confirmDialog } from '@/Utils/confirmDialog';

// Pedido explícito del usuario: mostrar una lista de colores para elegir en
// vez de obligar a escribirlo a mano (evita variantes como "blanco"/"Blanco
// perla"/"Blanco" que después no calzan bien al mostrarse en las tarjetas de
// conductor). Lista acotada a los colores de vehículo más comunes en Ecuador,
// con "Otro" al final para no bloquear un caso raro.
const VEHICLE_COLORS = [
    'Blanco', 'Negro', 'Gris', 'Plata', 'Rojo', 'Azul', 'Verde',
    'Amarillo', 'Naranja', 'Café', 'Dorado', 'Beige', 'Vino', 'Otro',
];

const props = defineProps({
    // null si el usuario todavía no activó su rol de conductor.
    driverProfile: {
        type: Object,
        default: null,
    },
    canConnect: { type: Boolean, default: false },
    connectionBlockReason: { type: String, default: null },
    missingDriverRequirements: { type: Array, default: () => [] },
    planLimits: { type: Object, required: true },
    // Avisos de carrera nueva por WhatsApp (pedido explícito del usuario).
    whatsappSession: { type: Object, default: null },
    whatsappBusinessNumber: { type: String, default: null },
    // Pedido explícito del usuario: poder ver y corregir el número
    // declarado, que es el que se valida contra el que usa para conectarse.
    currentPhone: { type: String, default: null },
    phoneVerified: { type: Boolean, default: false },
    // Medallas por puntos (pedido explícito del usuario): carreras
    // completadas por la app suman puntos, que suben de medalla — a partir
    // de cierta medalla (hoy Oro) aparece en el directorio público.
    totalPoints: { type: Number, default: 0 },
    tier: { type: Object, required: true },
    // null si ya está en la medalla más alta habilitada para el directorio.
    nextPublicTier: { type: Object, default: null },
    // Pedido explícito del usuario: tope de la tarifa mínima propia — puede
    // declarar una MENOR (la plataforma la respeta), pero no una mayor.
    platformMinimumFare: { type: Number, required: true },
    // Cargo por distancia de recogida (pedido explícito del usuario): solo
    // para explicarle al conductor qué activa el interruptor de abajo.
    pickupSurchargeThresholdKm: { type: Number, required: true },
    pickupSurchargePercent: { type: Number, required: true },
    // Catálogo fijo para "Tipo de vehículo" (pedido explícito del usuario:
    // confidencialidad en las pantallas públicas — este dato reemplaza a la
    // foto y a la placa completa como lo que sí se muestra al cliente).
    vehicleTypes: { type: Object, required: true },
    vehicleAmenities: { type: Object, required: true },
    serviceCategories: { type: Object, required: true },
    publicDriverCategories: { type: Object, required: true },
    // Tarjeta de perfil "profesional" (pedido explícito del usuario, mismo
    // lenguaje visual que Referral/Show.vue).
    averageRating: { type: Number, required: true },
    // Billetera cooperativa-conductor (pedido explícito del usuario): null
    // si no pertenece a ninguna cooperativa, o si esa cooperativa todavía no
    // configuró sus tarifas (nunca hay saldo que mostrar en ese caso).
    cooperativeWallet: { type: Object, default: null },
    // Cuentas bancarias (pedido explícito del usuario): varias, con una
    // favorita — ver DriverBankAccountController.
    bankAccounts: { type: Array, default: () => [] },
    // Catálogo de bancos de Ecuador (pedido explícito del usuario: "que sea
    // un seleccionable... con los principales primero") — ver
    // App\Models\DriverBankAccount::banks(). El último valor es siempre
    // "Otro", que revela un campo de texto libre en vez de forzar una
    // opción de la lista.
    banks: { type: Array, default: () => [] },
    reviewCount: { type: Number, required: true },
});

// "Mi cooperativa" (pedido explícito del usuario): mismo prop compartido
// globalmente por HandleInertiaRequests, no uno propio de esta pantalla —
// ver App\Services\Driver\DriverAccessResolver.
const driverAccess = usePage().props.auth.driverAccess;

// Pedido explícito del usuario: el recuadro de tarifa en Inicio
// (Dashboard.vue) enlaza acá con "#rate_per_km" — sin esto, la pantalla se
// abría siempre desde arriba y había que buscar el campo a mano.
onMounted(() => {
    if (window.location.hash) {
        const targetId = window.location.hash.slice(1);
        if (['rate_per_km', 'minimum_fare', 'max_request_distance_km'].includes(targetId)) {
            activeProfileSection.value = 'work';
        }
        nextTick(() => document.getElementById(targetId)?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
    }
});

// Misma lista que RegisteredUserController::COUNTRY_CODES. shortLabel (ver
// SearchableSelect.vue): bug real reportado por el usuario, el selector con
// el nombre del país entero le dejaba casi nada de ancho al campo del
// número en móvil, que quedaba aplastado.
const countryCodes = [
    { code: '+593', label: '🇪🇨 +593 Ecuador', shortLabel: '🇪🇨 +593' },
    { code: '+51', label: '🇵🇪 +51 Perú', shortLabel: '🇵🇪 +51' },
    { code: '+57', label: '🇨🇴 +57 Colombia', shortLabel: '🇨🇴 +57' },
    { code: '+58', label: '🇻🇪 +58 Venezuela', shortLabel: '🇻🇪 +58' },
    { code: '+56', label: '🇨🇱 +56 Chile', shortLabel: '🇨🇱 +56' },
    { code: '+54', label: '🇦🇷 +54 Argentina', shortLabel: '🇦🇷 +54' },
];
const countryCodeOptions = countryCodes.map((c) => ({ value: c.code, label: c.label, shortLabel: c.shortLabel }));

const whatsappOptInUrl = buildWhatsAppOptInUrl(props.whatsappBusinessNumber, usePage().props.auth.user.id);

function whatsappTimeRemaining() {
    if (!props.whatsappSession || props.whatsappSession.status === 'expired') return null;
    const ms = new Date(props.whatsappSession.expires_at) - new Date();
    const hours = Math.floor(ms / 3_600_000);
    const minutes = Math.floor((ms % 3_600_000) / 60_000);
    return `${hours}h ${minutes}min`;
}

// Dibuja el código de invitación como QR en el <canvas> de abajo, para que un
// cliente lo pueda escanear y agregarte a su flota sin escribir nada
// (sección 3.2 y 9.5: búsqueda "por código de invitación o QR").
//
// Bug real reportado por el usuario: el QR codificaba el código PELADO
// ("ABC12345"), así que cualquier lector de cámara normal (fuera de la
// app) solo mostraba ese texto suelto, sin nada para tocar. Ahora codifica
// la URL pública de "Referí a tu conductor" (/referir/{invite_code}, ver
// ReferralController::show()) — quien escanea cae directo en esa pantalla,
// con el botón de agregarlo a su flota si ya tiene sesión de cliente, o la
// invitación a crear cuenta si todavía no.
const drawInviteQr = async () => {
    if (!props.driverProfile) return;

    await nextTick();
    const canvas = document.getElementById('invite-qr');
    if (canvas) {
        await QRCode.toCanvas(canvas, route('referrals.show', props.driverProfile.invite_code), { width: 160, margin: 1 });
    }
};

watch(() => props.driverProfile?.invite_code, drawInviteQr, { immediate: true });

// Mensaje prearmado para compartir la invitación por WhatsApp (pedido
// explícito del usuario): antes solo se veía el código pelado o el QR — sin
// contexto, quien lo recibía no sabía qué era ni por qué confiar. El texto
// aclara que es la plataforma (Arka01, no un enlace suelto) y lo hace
// personal (viene de una persona concreta, pidiéndole que lo sume a SU
// flota de confianza) — mismo criterio que whatsappShareUrl en Profile/Edit.vue.
const whatsappInviteUrl = computed(() => {
    if (!props.driverProfile) return null;

    const name = usePage().props.auth.user.full_name;
    const link = route('referrals.show', props.driverProfile.invite_code);
    const text =
        `¡Hola! Soy ${name} y ya estoy en Arka01  — la app donde cada quien arma su propia ` +
        `flota de conductores de confianza, sin desconocidos.\n\n` +
        `Únase a Arka01 y regístreme en su flota para que me busque cuando necesite una carrera:\n${link}`;

    return `https://wa.me/?text=${encodeURIComponent(text)}`;
});

// Mismo formulario sirve para crear el perfil por primera vez o para editarlo
// después: si ya existe, se precargan sus valores.
const form = useForm({
    // Cambio de número de WhatsApp (pedido explícito del usuario): en blanco
    // por defecto — el backend no toca el teléfono actual si no se completa.
    country_code: '+593',
    phone_local: '',
    vehicle_make: props.driverProfile?.vehicle_make ?? '',
    vehicle_model: props.driverProfile?.vehicle_model ?? '',
    vehicle_color: props.driverProfile?.vehicle_color ?? '',
    vehicle_type: props.driverProfile?.vehicle_type ?? '',
    vehicle_plate: props.driverProfile?.vehicle_plate ?? '',
    vehicle_year: props.driverProfile?.vehicle_year ?? '',
    // Pedido explícito del usuario: para que un cliente pueda filtrar
    // conductores por cantidad de pasajeros y cajuela al pedir una carrera.
    passenger_capacity: props.driverProfile?.passenger_capacity ?? '',
    has_trunk: props.driverProfile?.has_trunk ?? false,
    vehicle_amenities: props.driverProfile?.vehicle_amenities ?? [],
    rate_per_km: props.driverProfile?.rate_per_km ?? '',
    minimum_fare: props.driverProfile?.minimum_fare ?? '',
    max_request_distance_km: props.driverProfile?.max_request_distance_km ?? '',
    // Cargo por distancia de recogida (pedido explícito del usuario): el
    // conductor lo apaga o prende desde acá — con esto en false, nunca se le
    // calcula ni se le muestra en ninguna solicitud (ver PriceCalculator).
    pickup_surcharge_enabled: props.driverProfile?.pickup_surcharge_enabled ?? true,
    accepts_cash: props.driverProfile?.accepts_cash ?? true,
    accepts_transfer: props.driverProfile?.accepts_transfer ?? false,
    has_insurance: props.driverProfile?.has_insurance ?? false,
    is_public: props.driverProfile?.is_public ?? false,
    // Pedido explícito del usuario ("mejoremos la privacidad de los
    // conductores"): distinto de is_public de arriba — ese es el directorio
    // buscable (gateado por plan), esto es si su perfil individual
    // (profiles.show) muestra los detalles a quien no sea él ni un admin.
    // Empieza en true igual que en el backend: nadie pierde visibilidad de
    // golpe con este cambio, es una opción para ocultarse si lo prefiere.
    profile_public: props.driverProfile?.profile_public ?? true,
    driver_type: props.driverProfile?.driver_type ?? 'independent',
    profile_photo: null,
    identity_document: null,
    license_photo: null,
    police_record: null,
});

// Si el color ya guardado no está en la lista fija (ej. dato viejo cargado
// como texto libre, antes de este cambio), se agrega igual como opción para
// no perder el valor actual al abrir el formulario.
const vehicleColorOptions = computed(() => {
    const current = props.driverProfile?.vehicle_color;
    return current && !VEHICLE_COLORS.includes(current) ? [current, ...VEHICLE_COLORS] : VEHICLE_COLORS;
});

const assignedServiceCategory = computed(() => (
    props.serviceCategories[props.driverProfile?.service_category] ?? null
));
const assignedPublicCategory = computed(() => (
    props.publicDriverCategories[props.driverProfile?.public_category] ?? null
));
const profileAvailable = ref(Boolean(props.driverProfile?.is_available));

// Mismo criterio que DriverProfile::hasCompleteVehicleInfo() (backend) —
// duplicado acá nomás para el aviso, la validación real siempre la hace el
// servidor.
const vehicleInfoComplete = computed(() => {
    const p = props.driverProfile;
    return Boolean(
        p?.vehicle_make
        && p?.vehicle_model
        && p?.vehicle_color
        && p?.vehicle_type
        && p?.vehicle_plate
        && p?.vehicle_year
        && p?.passenger_capacity
        && p?.has_trunk !== null
    );
});

const missingVehicleLabels = computed(() => {
    const p = props.driverProfile ?? {};
    const fields = [
        ['vehicle_make', 'marca'],
        ['vehicle_model', 'modelo'],
        ['vehicle_color', 'color'],
        ['vehicle_type', 'tipo de vehículo'],
        ['vehicle_plate', 'placa'],
        ['vehicle_year', 'año'],
        ['passenger_capacity', 'cantidad de pasajeros'],
    ];
    const missing = fields.filter(([field]) => p[field] === null || p[field] === undefined || String(p[field]).trim() === '').map(([, label]) => label);
    if (p.has_trunk === null || p.has_trunk === undefined) missing.push('disponibilidad de cajuela');
    return missing;
});

const missingRequirementLabels = (section = null) => props.missingDriverRequirements
    .filter((item) => !section || item.section === section)
    .map((item) => item.label);
const missingSummary = (labels) => `Falta${labels.length === 1 ? '' : 'n'}: ${labels.join(', ')}.`;

// Los datos persistidos de identificación del vehículo quedan protegidos de
// forma individual. Esto permite completar un perfil antiguo al que todavía
// le falte un campo, sin volver a habilitar los datos que ya guardó.
function vehicleFieldLocked(field) {
    const profile = props.driverProfile;
    if (!profile) return false;
    if (field === 'has_trunk') return vehicleInfoComplete.value;

    const value = profile[field];
    return value !== null && value !== undefined && String(value).trim() !== '';
}

// El perfil reúne muchos datos necesarios, pero el conductor no necesita
// verlos todos al mismo tiempo. Un solo bloque abierto mantiene el contexto
// y permite volver rápido a la sección que realmente quiere corregir.
const activeProfileSection = ref(!props.driverProfile || !vehicleInfoComplete.value ? 'vehicle' : null);
const toggleProfileSection = (section) => {
    activeProfileSection.value = activeProfileSection.value === section ? null : section;
};

const completedStatusCount = computed(() => statusItems.value.filter((item) => item.ok).length);

// Cuentas bancarias (pedido explícito del usuario): form propio, aparte del
// principal, porque postea a un endpoint distinto
// (DriverBankAccountController::store()) — una entidad de varias filas no
// encaja en el mismo useForm() 1:1 del resto del perfil.
const bankAccountForm = useForm({
    account_holder_name: usePage().props.auth.user.full_name,
    identity_number: '',
    bank_name: '',
    account_type: 'ahorros',
    account_number: '',
});

// Selector de banco con "Otro" (pedido explícito del usuario): el
// desplegable maneja las opciones fijas, y solo cuando elige "Otro" aparece
// un campo de texto libre — bank_name (lo que de verdad se envía) sigue el
// valor de cualquiera de los dos según corresponda.
const selectedBank = ref('');
const customBankName = ref('');
watch([selectedBank, customBankName], () => {
    bankAccountForm.bank_name = selectedBank.value === 'Otro' ? customBankName.value : selectedBank.value;
});

function submitBankAccount() {
    bankAccountForm.post(route('driver.bank-accounts.store'), {
        preserveScroll: true,
        onSuccess: () => {
            bankAccountForm.reset();
            bankAccountForm.account_holder_name = usePage().props.auth.user.full_name;
            selectedBank.value = '';
            customBankName.value = '';
        },
    });
}

function markBankAccountFavorite(account) {
    router.patch(route('driver.bank-accounts.favorite', account.id), {}, { preserveScroll: true });
}

function deleteBankAccount(account) {
    confirmDialog(`¿Eliminar la cuenta ${account.bank_name} · ${account.account_number}?`, {
        danger: true,
        confirmLabel: 'Eliminar',
    }).then((confirmed) => {
        if (confirmed) router.delete(route('driver.bank-accounts.destroy', account.id), { preserveScroll: true });
    });
}

watch(
    () => bankAccountForm.errors,
    (errors) => {
        if (Object.keys(errors ?? {}).length) activeProfileSection.value = 'bank';
    },
    { deep: true }
);

// Si el servidor rechaza algún dato, se abre automáticamente el bloque que
// contiene ese campo. Así el error nunca queda escondido en un acordeón.
watch(
    () => form.errors,
    (errors) => {
        const keys = Object.keys(errors ?? {});
        if (!keys.length) return;

        if (keys.some((key) => ['country_code', 'phone_local'].includes(key))) {
            activeProfileSection.value = 'contact';
        } else if (keys.some((key) => key.startsWith('vehicle_') || ['passenger_capacity', 'has_trunk'].includes(key))) {
            activeProfileSection.value = 'vehicle';
        } else if (keys.some((key) => ['rate_per_km', 'minimum_fare', 'max_request_distance_km', 'accepts_cash', 'accepts_transfer'].includes(key))) {
            activeProfileSection.value = 'work';
        } else if (keys.some((key) => ['driver_type', 'profile_photo', 'identity_document', 'license_photo', 'police_record', 'has_insurance'].includes(key))) {
            activeProfileSection.value = 'verification';
        } else {
            activeProfileSection.value = 'visibility';
        }
    },
    { deep: true }
);

// "Tu estado" (pedido explícito del usuario: "si falta que el conductor
// cumpla con algo debés indicarle para que sepa dónde aparecerá y qué
// necesita para eso") — de un vistazo, qué está activo, qué falta y por qué.
const statusItems = computed(() => {
    const p = props.driverProfile;
    if (!p) return [];

    const registrationMissing = missingRequirementLabels();
    const firstMissingSection = props.missingDriverRequirements[0]?.section ?? null;
    const verificationMissing = missingRequirementLabels('verification');

    const items = [
        {
            label: 'Requisitos para conectarse',
            ok: props.canConnect,
            action: !props.canConnect && firstMissingSection ? 'section' : null,
            section: firstMissingSection,
            detail: props.canConnect
                ? 'Información completa y aprobación administrativa listas.'
                : registrationMissing.length
                  ? missingSummary(registrationMissing)
                  : props.connectionBlockReason || 'Su información aún no está habilitada para conectarse.',
        },
        {
            label: 'Datos del vehículo',
            ok: vehicleInfoComplete.value,
            action: vehicleInfoComplete.value ? null : 'section',
            section: 'vehicle',
            detail: vehicleInfoComplete.value
                ? 'Marca, modelo, características y capacidad completos.'
                : missingSummary(missingVehicleLabels.value),
        },
        {
            label: 'Disponible ahora',
            ok: profileAvailable.value,
            action: 'availability',
            detail: profileAvailable.value
                ? 'Los clientes lo ven en "Mi flota" y en el directorio si es público.'
                : 'Actívese aquí para empezar a recibir carreras.',
        },
    ];

    // Directorio público (sección 3.4): dos motivos posibles para no estar,
    // hay que distinguirlos para que sepa cuál de los dos le toca a él.
    const directoryVisible = p.is_public && props.planLimits.public_visibility;
    items.push({
        label: 'Visible en el directorio público',
        ok: directoryVisible,
        action: !props.planLimits.public_visibility ? 'plans' : (!p.is_public ? 'visibility' : null),
        detail: directoryVisible
            ? 'Cualquier cliente lo puede encontrar y agregarlo a su flota, no solo quien ya lo conoce.'
            : !props.planLimits.public_visibility
              ? 'Su plan actual no lo incluye — hace falta el plan Plus o superior.'
              : 'Su plan lo incluye, pero todavía no activó el toggle "Aparecer en el directorio público" de más abajo.',
    });

    // Insignia de verificado: depende de la aprobación del admin Y de que el
    // plan la incluya (fix reportado: antes se mostraba igual sin importar
    // el plan).
    const verifiedBadgeVisible = p.verification_status === 'approved' && props.planLimits.verified_badge;
    items.push({
        label: 'Verificación administrativa',
        ok: verifiedBadgeVisible,
        action: verificationMissing.length
            ? 'section'
            : (p.verification_status === 'approved' && !props.planLimits.verified_badge ? 'plans' : null),
        section: verificationMissing.length ? 'verification' : null,
        detail: verifiedBadgeVisible
            ? 'Se ve en su perfil y en el directorio público.'
            : verificationMissing.length
              ? missingSummary(verificationMissing)
            : p.verification_status === 'rejected'
              ? `Su verificación fue rechazada${p.verification_rejection_reason ? `: "${p.verification_rejection_reason}"` : ''} — corrija eso y vuelva a subir sus fotos más abajo.`
              : p.verification_status == null
                ? 'Todavía no subió sus documentos — complete la cédula, licencia, antecedente penal, seguro y foto de perfil más abajo.'
                : p.verification_status !== 'approved'
                  ? `Su verificación está "${VERIFICATION_LABELS[p.verification_status]}" — revise los documentos obligatorios más abajo.`
                  : 'Ya está verificado, pero su plan actual no incluye la insignia — hace falta un plan superior.',
    });

    return items;
});

const submit = () => {
    form.post(route('driver.profile.update'), { forceFormData: true });
};

// "Reactivar mi perfil de conductor" (pedido explícito del usuario): atajo
// de un solo toque para quien pausó antes — los datos siguen guardados, no
// hace falta volver a completar el formulario (aunque guardarlo también
// reactiva, ver DriverProfileController::update()).
function reactivate() {
    router.post(route('driver.profile.reactivate'));
}

function openVisibilitySettings() {
    activeProfileSection.value = 'visibility';
    nextTick(() => document.getElementById('driver-visibility-settings')?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
}

function openProfileSection(section) {
    activeProfileSection.value = section;
    nextTick(() => document.getElementById(`driver-${section}-settings`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
}

// Pedido explícito del usuario: "Instalar app" y "Pasarme a cliente" también
// acá, no solo en el menú del header — mismo criterio que Profile/Edit.vue.
async function installAppNow() {
    const accepted = await installApp();
    if (accepted) alert('¡Listo! Ya quedó instalada.');
}

async function switchToClient() {
    if (!(await confirmDialog('¿Pasar a cliente? Su perfil de conductor queda guardado — puede volver a activarlo cuando quiera.'))) return;
    router.post(route('driver.profile.deactivate'));
}

// Bug reportado por el usuario: un conductor que nunca subió ninguna foto
// terminaba igual con `verification_status = 'pending'` (el default de la
// columna al crearse la fila) y quedaba bloqueado para subirlas — la fuente
// real ahora es `null` ("sin documentos todavía"), ver la migración
// `driver_profiles_verification_status_nullable`.
const VERIFICATION_LABELS = {
    null: 'Sin documentos',
    pending: 'Pendiente de revisión',
    approved: 'Verificado',
    rejected: 'Rechazada, suba una foto más clara',
};
</script>

<template>
    <Head title="Mi perfil de conductor" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-arka-text leading-tight">
                {{ driverProfile ? 'Mi perfil de conductor' : 'Convertirme en conductor' }}
            </h2>
        </template>

        <div class="py-8 sm:py-10">
            <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Cabecera profesional: identidad, estado operativo y
                     clasificación del servicio en una sola lectura. -->
                <section class="overflow-hidden rounded-arka border border-arka-primary/20 bg-arka-card shadow-xl">
                    <div class="h-1.5 bg-gradient-to-r from-arka-primary via-arka-primary-bright to-arka-warning"></div>
                    <div class="grid gap-6 p-5 sm:p-7 lg:grid-cols-[1.35fr_1fr] lg:items-center">
                        <!-- Pedido explícito del usuario ("mejora esto el
                             response en el perfil del conductor"): en
                             pantallas angostas (`flex-col`, antes de `sm:`)
                             quedaba todo pegado a la izquierda con un avatar
                             grande de 24 — se ve mejor centrado como una
                             tarjeta de identidad, con el avatar un poco más
                             chico, y recién pasa a la fila horizontal
                             alineada a la izquierda desde `sm:` en adelante. -->
                        <div class="flex flex-col items-center gap-4 text-center sm:flex-row sm:items-center sm:gap-5 sm:text-left">
                            <div class="relative shrink-0">
                                <UserAvatar :user="$page.props.auth.user" size-class="h-20 w-20 text-2xl sm:h-24 sm:w-24 sm:text-3xl" />
                                <span
                                    v-if="driverProfile?.verification_status === 'approved'"
                                    class="absolute -bottom-1 -right-1 grid h-8 w-8 place-items-center rounded-full border-4 border-arka-card bg-arka-primary text-sm font-black text-arka-base"
                                    title="Conductor verificado"
                                >✓</span>
                            </div>
                            <!-- Bug real reportado por el usuario, con dos capturas
                                 seguidas ("aun se sigue saliendo de la card"): sacar
                                 `truncate` no alcanzaba — en móvil el contenedor
                                 padre es `flex-col items-center`, y `items-center`
                                 en el eje de un flex-col NO estira los hijos al
                                 ancho completo, cada uno queda del ancho de SU
                                 propio contenido. Sin `w-full` acá, este bloque de
                                 texto se armaba tan ancho como el nombre completo en
                                 una sola línea (nunca llegaba a necesitar wrapear) y
                                 el `overflow-hidden` de la tarjeta de más arriba
                                 cortaba en seco lo que sobraba — por eso `truncate`
                                 O `break-words` daba igual, ninguno de los dos
                                 llegaba a activarse. -->
                            <div class="min-w-0 w-full sm:w-auto">
                                <!-- `truncate` fuerza una sola línea y corta el nombre
                                     a la mitad ("GREGORIO ENRIQUE OSORIO ANDRA...") en
                                     vez de mostrarlo completo — acá no hay ningún
                                     motivo para una sola línea (es SU propio nombre,
                                     no una lista de muchos), así que se deja bajar
                                     de línea normal. -->
                                <div class="flex flex-wrap items-center justify-center gap-2 sm:justify-start">
                                    <h3 class="break-words text-xl font-bold text-arka-text sm:text-2xl">{{ $page.props.auth.user.full_name }}</h3>
                                    <DriverCategoryBadge :label="assignedPublicCategory?.label" />
                                    <span class="rounded-full bg-arka-primary/10 px-3 py-1 text-xs font-semibold text-arka-primary-bright">
                                        {{ assignedServiceCategory?.label ?? 'Categoría por asignar' }}
                                    </span>
                                </div>
                                <p class="mt-1 text-sm text-arka-text-muted">
                                    @{{ $page.props.auth.user.username }} · Socio #{{ $page.props.auth.user.member_code }}
                                </p>
                                <div class="mt-3 flex flex-wrap items-center justify-center gap-x-4 gap-y-2 text-sm text-arka-text-muted sm:justify-start">
                                    <RatingStars v-if="reviewCount > 0" :rating="averageRating" :count="reviewCount" readonly />
                                    <span v-else>Sin calificaciones todavía</span>
                                    <span v-if="driverProfile?.vehicle_make" class="inline-flex items-center gap-1.5">
                                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="3.5" y="6" width="17" height="12" rx="2" stroke-linecap="round" stroke-linejoin="round" />
                                            <circle cx="9" cy="12" r="1.75" stroke-linecap="round" stroke-linejoin="round" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 10.5h3M14 13.5h3" />
                                        </svg>
                                        {{ driverProfile.vehicle_make }} {{ driverProfile.vehicle_model }}
                                    </span>
                                </div>
                                <p v-if="assignedServiceCategory" class="mt-3 max-w-xl text-sm leading-relaxed text-arka-text-muted">
                                    {{ assignedServiceCategory.description }} Categoría revisada y asignada por administración.
                                </p>
                                <p v-else class="mt-3 max-w-xl text-sm leading-relaxed text-arka-text-muted">
                                    Complete las características del vehículo. Administración revisará la información y asignará la categoría adecuada.
                                </p>
                            </div>
                        </div>

                        <div class="space-y-3">
                        <div
                            role="status"
                            class="rounded-arka border p-4"
                            :class="driverProfile && canConnect && !driverProfile?.deactivated_at
                                ? 'border-arka-primary/30 bg-arka-primary/10'
                                : 'border-arka-warning/30 bg-arka-warning/10'"
                        >
                            <div class="flex items-start gap-3">
                                <span
                                    class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-lg font-bold"
                                    :class="driverProfile && canConnect && !driverProfile?.deactivated_at ? 'bg-arka-primary/20 text-arka-primary-bright' : 'bg-arka-warning/20 text-arka-warning'"
                                >{{ driverProfile && canConnect && !driverProfile?.deactivated_at ? '✓' : '!' }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold" :class="driverProfile && canConnect && !driverProfile?.deactivated_at ? 'text-arka-primary-bright' : 'text-arka-warning'">
                                        {{ driverProfile?.deactivated_at ? 'Perfil de conductor pausado' : (driverProfile && canConnect ? 'Perfil aprobado y listo' : 'Perfil pendiente de completar') }}
                                    </p>
                                    <p class="mt-1 text-sm leading-relaxed text-arka-text-muted">
                                        {{ driverProfile?.deactivated_at ? 'Sus datos siguen guardados. Reactívelo y vuelva a trabajar sin completar todo otra vez.' : (driverProfile && canConnect ? 'Active su disponibilidad en los indicadores de abajo para recibir carreras.' : (connectionBlockReason || 'Complete los datos para activar su perfil de conductor.')) }}
                                    </p>
                                    <PrimaryButton v-if="driverProfile?.deactivated_at" class="mt-3" @click="reactivate">Reactivar perfil</PrimaryButton>
                                </div>
                            </div>
                        </div>

                        <div v-if="driverProfile && whatsappBusinessNumber" class="rounded-arka border p-4" :class="whatsappSession && whatsappSession.status !== 'expired' ? 'border-arka-primary/20 bg-arka-base/40' : 'border-arka-warning/25 bg-arka-warning/5'">
                            <div class="flex items-start gap-3">
                                <svg class="mt-0.5 h-5 w-5 shrink-0 text-arka-primary-bright" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 11.5a8 8 0 0 1-11.8 7L4 20l1.5-4A8 8 0 1 1 20 11.5Z"/><path d="M8.5 8.5c.7 3.1 2.2 4.6 5 5" stroke-linecap="round"/></svg>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-arka-text">Avisos por WhatsApp</p>
                                    <p class="mt-1 text-xs leading-relaxed text-arka-text-muted">
                                        <template v-if="whatsappSession && whatsappSession.status !== 'expired'">Conectado · {{ whatsappTimeRemaining() }} restantes. Renueve antes de que venza.</template>
                                        <template v-else>Conéctelo para recibir solicitudes aunque la app esté cerrada.</template>
                                    </p>
                                    <a :href="whatsappOptInUrl" target="_blank" rel="noopener" class="mt-2 inline-flex min-h-9 items-center rounded-lg border border-arka-primary/30 px-3 py-1.5 text-xs font-semibold text-arka-primary-bright hover:bg-arka-primary/10">
                                        {{ whatsappSession && whatsappSession.status !== 'expired' ? 'Renovar WhatsApp' : 'Conectar WhatsApp' }}
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div v-if="driverProfile" class="flex flex-wrap gap-2">
                            <SecondaryButton v-if="!driverProfile.deactivated_at" @click="switchToClient">Pasarme a cliente</SecondaryButton>
                            <SecondaryButton v-if="canInstallApp" @click="installAppNow">Instalar app</SecondaryButton>
                        </div>
                        </div>
                    </div>
                </section>

                <div class="p-4 sm:p-8 bg-arka-card shadow sm:rounded-arka">
                    <header class="mb-6">
                        <!-- Pedido explícito del usuario ("pasarme a conductor
                             / pasarme a cliente, fácil"): si el perfil está
                             pausado, un atajo de un solo toque para volver —
                             sin esto tendría que revisar/reguardar todo el
                             formulario de abajo para reactivarse. -->
                        <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-arka-primary">Configuración</p>
                                <h2 class="mt-1 text-lg font-semibold text-arka-text">Información para trabajar</h2>
                                <p class="mt-1 text-sm text-arka-text-muted">Abra únicamente la sección que necesite revisar o actualizar.</p>
                            </div>
                            <span v-if="driverProfile" class="mt-2 text-xs font-semibold text-arka-text-muted sm:mt-0">
                                {{ completedStatusCount }} de {{ statusItems.length }} estados listos
                            </span>
                        </div>

                        <!-- Usuario y código de socio: ya se muestran arriba, en la
                             tarjeta de perfil — sin repetirlos acá. -->

                        <!-- "Tu estado" (pedido explícito del usuario): de un vistazo,
                             qué está activo y qué falta para cada cosa (disponible,
                             directorio público, insignia de verificado) — y por qué,
                             no solo "sí" o "no". -->
                        <div v-if="driverProfile" class="mt-4 overflow-hidden rounded-xl border border-arka-text-muted/10">
                            <div class="h-1 bg-arka-text-muted/10">
                                <div
                                    class="h-full bg-arka-primary transition-all"
                                    :style="{ width: `${(completedStatusCount / statusItems.length) * 100}%` }"
                                ></div>
                            </div>
                            <div class="grid gap-px bg-arka-text-muted/10 sm:grid-cols-2">
                            <div
                                v-for="item in statusItems"
                                :key="item.label"
                                class="bg-arka-card p-3 text-sm"
                            >
                                <p class="flex items-start gap-2 font-medium" :class="item.ok ? 'text-arka-primary-bright' : 'text-arka-warning'">
                                    <span class="mt-0.5 grid h-4 w-4 shrink-0 place-items-center rounded-full text-[10px]"
                                        :class="item.ok ? 'bg-arka-primary/20' : 'bg-arka-warning/20'"
                                    >{{ item.ok ? '✓' : '!' }}</span>
                                    {{ item.label }}
                                </p>
                                <p v-if="!item.ok" class="mt-1 pl-6 text-xs leading-relaxed text-arka-text-muted">{{ item.detail }}</p>
                                <div v-if="item.action && (!item.ok || item.action === 'availability')" class="mt-3 pl-6">
                                    <DriverAvailabilityToggle
                                        v-if="item.action === 'availability'"
                                        :initial-available="profileAvailable"
                                        :can-connect="canConnect && !driverProfile?.deactivated_at"
                                        :blocked-reason="driverProfile?.deactivated_at ? 'Reactive primero su perfil de conductor.' : connectionBlockReason"
                                        @update:available="profileAvailable = $event"
                                    />
                                    <Link
                                        v-else-if="item.action === 'plans'"
                                        :href="route('driver.plan.edit')"
                                        class="inline-flex min-h-9 items-center rounded-lg border border-arka-primary/30 px-3 py-1.5 text-xs font-semibold text-arka-primary-bright hover:bg-arka-primary/10"
                                    >
                                        Ir a los planes
                                    </Link>
                                    <button
                                        v-else-if="item.action === 'visibility'"
                                        type="button"
                                        class="inline-flex min-h-9 items-center rounded-lg border border-arka-primary/30 px-3 py-1.5 text-xs font-semibold text-arka-primary-bright hover:bg-arka-primary/10"
                                        @click="openVisibilitySettings"
                                    >
                                        Configurar visibilidad
                                    </button>
                                    <button
                                        v-else-if="item.action === 'section'"
                                        type="button"
                                        class="inline-flex min-h-9 items-center rounded-lg border border-arka-primary/30 px-3 py-1.5 text-xs font-semibold text-arka-primary-bright hover:bg-arka-primary/10"
                                        @click="openProfileSection(item.section)"
                                    >
                                        Ir a completar
                                    </button>
                                </div>
                            </div>
                            </div>
                        </div>
                    </header>

                    <form @submit.prevent="submit" class="space-y-6">
                        <!-- Cambio de número de WhatsApp (pedido explícito del
                             usuario): es el número que se valida contra el que
                             usa para conectarse por WhatsApp
                             (WhatsAppWebhookController) y al que le llegan los
                             avisos de carrera nueva — tiene que poder
                             corregirlo si se equivocó o cambió de celular. -->
                        <section id="driver-contact-settings" class="overflow-hidden rounded-2xl border border-arka-text-muted/10 bg-arka-base/25">
                            <button type="button" class="flex w-full items-center gap-3 p-4 text-left" @click="toggleProfileSection('contact')">
                                <SectionIcon name="phone" />
                                <span class="min-w-0 flex-1">
                                    <span class="block font-semibold text-arka-text">1. Contacto y WhatsApp</span>
                                    <span class="mt-0.5 block truncate text-xs text-arka-text-muted">
                                        {{ currentPhone ?? 'Número pendiente' }} · {{ phoneVerified ? 'Verificado' : 'Sin verificar' }}
                                    </span>
                                </span>
                                <svg class="h-5 w-5 shrink-0 text-arka-text-muted transition-transform" :class="activeProfileSection === 'contact' ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                                </svg>
                            </button>
                        <div v-show="activeProfileSection === 'contact'" class="border-t border-arka-text-muted/10 p-4">
                            <InputLabel value="Su número de WhatsApp" />
                            <p class="mt-1 text-sm text-arka-text-muted">
                                Actual: <span class="font-mono text-arka-text">{{ currentPhone ?? 'sin declarar' }}</span>
                                <span
                                    v-if="currentPhone"
                                    class="ml-2 text-xs px-2 py-0.5 rounded-arka"
                                    :class="phoneVerified ? 'bg-arka-primary/10 text-arka-primary-bright' : 'bg-arka-warning/10 text-arka-warning'"
                                >
                                    {{ phoneVerified ? 'Verificado' : 'Sin verificar' }}
                                </span>
                            </p>

                            <!-- Bug real reportado por el usuario (con capturas): el selector
                                 con el nombre del país entero aplastaba el campo del número en
                                 móvil — shrink-0 + w-28 lo deja angosto y fijo. -->
                            <div class="mt-2 flex gap-2">
                                <SearchableSelect
                                    id="country_code"
                                    class="w-28 shrink-0"
                                    v-model="form.country_code"
                                    :options="countryCodeOptions"
                                />

                                <TextInput
                                    id="phone_local"
                                    type="tel"
                                    class="block w-full min-w-0 flex-1"
                                    v-model="form.phone_local"
                                    autocomplete="tel-national"
                                    placeholder="Déjelo en blanco para no cambiarlo"
                                />
                            </div>
                            <p class="mt-1 text-xs text-arka-text-muted">
                                <template v-if="form.country_code === '+593'">9 dígitos, empieza en 9 — </template>
                                Sin el 0 inicial ni espacios. Si lo cambia, va a tener que verificarlo de nuevo por
                                WhatsApp.
                            </p>
                            <InputError class="mt-2" :message="form.errors.country_code" />
                            <InputError class="mt-1" :message="form.errors.phone_local" />
                        </div>
                        </section>

                        <section id="driver-vehicle-settings" class="overflow-hidden rounded-2xl border border-arka-text-muted/10 bg-arka-base/25">
                            <button type="button" class="flex w-full items-center gap-3 p-4 text-left" @click="toggleProfileSection('vehicle')">
                                <SectionIcon name="vehicle" />
                                <span class="min-w-0 flex-1">
                                    <span class="block font-semibold text-arka-text">2. Vehículo y comodidades</span>
                                    <span class="mt-0.5 block text-xs" :class="vehicleInfoComplete ? 'text-arka-primary' : 'text-arka-warning'">
                                        {{ vehicleInfoComplete ? 'Información obligatoria completa' : 'Faltan datos obligatorios' }}
                                    </span>
                                </span>
                                <svg class="h-5 w-5 shrink-0 text-arka-text-muted transition-transform" :class="activeProfileSection === 'vehicle' ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                                </svg>
                            </button>
                            <div v-show="activeProfileSection === 'vehicle'" class="space-y-5 border-t border-arka-text-muted/10 p-4 sm:p-5">

                        <div v-if="driverProfile" class="flex items-start gap-3 rounded-xl border border-arka-primary/20 bg-arka-primary/5 p-3 text-sm">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-arka-primary-bright" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="5" y="10" width="14" height="10" rx="2" />
                                <path stroke-linecap="round" d="M8 10V7a4 4 0 0 1 8 0v3" />
                            </svg>
                            <p class="text-arka-text-muted">
                                <span class="font-semibold text-arka-text">Datos protegidos.</span>
                                Lo que ya guardó no se puede modificar desde el perfil. Complete únicamente los campos que todavía estén pendientes.
                            </p>
                        </div>

                        <!-- Datos del vehículo, TODOS obligatorios (pedido explícito del
                             usuario): un cliente los usa para filtrar conductores por
                             cantidad de pasajeros y cajuela al pedir una carrera — un
                             dato a medias no sirve para eso. Sin completarlos no te
                             podés poner disponible (ver el aviso más abajo del switch
                             "Activarme"). -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel for="vehicle_make" value="Marca del vehículo" />
                                <TextInput
                                    id="vehicle_make"
                                    type="text"
                                    class="mt-1 block w-full"
                                    v-model="form.vehicle_make"
                                    :disabled="vehicleFieldLocked('vehicle_make')"
                                    required
                                />
                                <InputError class="mt-2" :message="form.errors.vehicle_make" />
                            </div>

                            <div>
                                <InputLabel for="vehicle_model" value="Modelo" />
                                <TextInput
                                    id="vehicle_model"
                                    type="text"
                                    class="mt-1 block w-full"
                                    v-model="form.vehicle_model"
                                    :disabled="vehicleFieldLocked('vehicle_model')"
                                    required
                                />
                                <InputError class="mt-2" :message="form.errors.vehicle_model" />
                            </div>

                            <div>
                                <InputLabel for="vehicle_color" value="Color" />
                                <select
                                    id="vehicle_color"
                                    v-model="form.vehicle_color"
                                    class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="vehicleFieldLocked('vehicle_color')"
                                    required
                                >
                                    <option value="" disabled>Elija un color</option>
                                    <option v-for="color in vehicleColorOptions" :key="color" :value="color">{{ color }}</option>
                                </select>
                                <InputError class="mt-2" :message="form.errors.vehicle_color" />
                            </div>

                            <div>
                                <InputLabel for="vehicle_type" value="Tipo de vehículo" />
                                <select
                                    id="vehicle_type"
                                    v-model="form.vehicle_type"
                                    class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="vehicleFieldLocked('vehicle_type')"
                                    required
                                >
                                    <option value="" disabled>Elija un tipo</option>
                                    <option v-for="(label, value) in vehicleTypes" :key="value" :value="value">{{ label }}</option>
                                </select>
                                <InputError class="mt-2" :message="form.errors.vehicle_type" />
                            </div>

                            <div>
                                <InputLabel for="vehicle_plate" value="Placa" />
                                <TextInput
                                    id="vehicle_plate"
                                    type="text"
                                    class="mt-1 block w-full"
                                    v-model="form.vehicle_plate"
                                    :disabled="vehicleFieldLocked('vehicle_plate')"
                                    required
                                />
                                <InputError class="mt-2" :message="form.errors.vehicle_plate" />
                            </div>

                            <div>
                                <InputLabel for="vehicle_year" value="Año" />
                                <TextInput
                                    id="vehicle_year"
                                    type="number"
                                    class="mt-1 block w-full"
                                    v-model="form.vehicle_year"
                                    :disabled="vehicleFieldLocked('vehicle_year')"
                                    required
                                />
                                <InputError class="mt-2" :message="form.errors.vehicle_year" />
                            </div>

                            <div>
                                <InputLabel for="passenger_capacity" value="Cantidad de pasajeros disponibles" />
                                <TextInput
                                    id="passenger_capacity"
                                    type="number"
                                    min="1"
                                    max="8"
                                    class="mt-1 block w-full"
                                    v-model="form.passenger_capacity"
                                    :disabled="vehicleFieldLocked('passenger_capacity')"
                                    required
                                />
                                <InputError class="mt-2" :message="form.errors.passenger_capacity" />
                            </div>
                        </div>

                        <label class="flex items-center" :class="vehicleFieldLocked('has_trunk') ? 'cursor-not-allowed opacity-60' : ''">
                            <Checkbox v-model:checked="form.has_trunk" :disabled="vehicleFieldLocked('has_trunk')" />
                            <span class="ms-2 text-sm text-arka-text">Tengo cajuela disponible para maletas</span>
                        </label>

                        <!-- Comodidades opcionales: ayudan al admin a evaluar
                             la categoría, pero no la asignan automáticamente. -->
                        <section class="rounded-arka border border-arka-primary/20 bg-arka-primary/5 p-4 sm:p-5">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h3 class="font-semibold text-arka-text">Comodidades y experiencia</h3>
                                    <p class="mt-1 text-sm text-arka-text-muted">
                                        Marque únicamente lo que ofrece actualmente. Son datos opcionales y serán visibles para administración.
                                    </p>
                                </div>
                                <span class="shrink-0 rounded-full bg-arka-card px-3 py-1 text-xs font-medium text-arka-primary-bright">
                                    {{ form.vehicle_amenities.length }} seleccionada{{ form.vehicle_amenities.length === 1 ? '' : 's' }}
                                </span>
                            </div>

                            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                <label
                                    v-for="(amenity, key) in vehicleAmenities"
                                    :key="key"
                                    class="flex cursor-pointer items-start gap-3 rounded-arka border p-3 transition"
                                    :class="form.vehicle_amenities.includes(key)
                                        ? 'border-arka-primary/50 bg-arka-primary/10'
                                        : 'border-arka-text-muted/15 bg-arka-card hover:border-arka-primary/30'"
                                >
                                    <Checkbox v-model:checked="form.vehicle_amenities" :value="key" class="mt-0.5" />
                                    <span>
                                        <span class="block text-sm font-medium text-arka-text">{{ amenity.label }}</span>
                                        <span class="mt-0.5 block text-xs leading-relaxed text-arka-text-muted">{{ amenity.description }}</span>
                                    </span>
                                </label>
                            </div>
                            <InputError class="mt-2" :message="form.errors.vehicle_amenities" />
                            <p class="mt-4 rounded-arka bg-arka-card/70 p-3 text-xs leading-relaxed text-arka-text-muted">
                                La categoría no se obtiene solo por marcar checks. Administración también revisa año, capacidad, documentos y estado general del vehículo.
                            </p>
                        </section>
                            </div>
                        </section>

                        <!-- Verificación de identidad: los documentos son privados y
                             solo pueden verlos el conductor y un administrador. -->
                        <section id="driver-verification-settings" class="overflow-hidden rounded-2xl border border-arka-text-muted/10 bg-arka-base/25">
                            <button type="button" class="flex w-full items-center gap-3 p-4 text-left" @click="toggleProfileSection('verification')">
                                <SectionIcon name="identity" />
                                <span class="min-w-0 flex-1">
                                    <span class="block font-semibold text-arka-text">3. Identidad y documentos</span>
                                    <span class="mt-0.5 block text-xs"
                                        :class="driverProfile?.verification_status === 'approved' ? 'text-arka-primary' : driverProfile?.verification_status === 'rejected' ? 'text-arka-danger' : 'text-arka-warning'"
                                    >
                                        {{ driverProfile ? VERIFICATION_LABELS[driverProfile.verification_status] : 'Documentos obligatorios pendientes' }}
                                    </span>
                                </span>
                                <svg class="h-5 w-5 shrink-0 text-arka-text-muted transition-transform" :class="activeProfileSection === 'verification' ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                                </svg>
                            </button>
                        <div v-show="activeProfileSection === 'verification'" class="space-y-4 border-t border-arka-text-muted/10 p-4 sm:p-5">
                            <div class="flex items-center justify-between">
                                <InputLabel value="Verificación" />
                                <span
                                    v-if="driverProfile"
                                    class="text-xs px-2 py-1 rounded-arka"
                                    :class="{
                                        'bg-arka-primary/10 text-arka-primary-bright': driverProfile.verification_status === 'approved',
                                        'bg-arka-warning/10 text-arka-warning': driverProfile.verification_status === 'pending',
                                        'bg-arka-danger/10 text-arka-danger': driverProfile.verification_status === 'rejected',
                                        'bg-arka-text-muted/10 text-arka-text-muted': driverProfile.verification_status == null,
                                    }"
                                >
                                    {{ VERIFICATION_LABELS[driverProfile.verification_status] }}
                                </span>
                            </div>

                            <!-- Pedido explícito del usuario: si se rechazó, el admin
                                 dejó un motivo puntual — mostrarlo acá es lo que le
                                 dice al conductor exactamente qué corregir. -->
                            <p
                                v-if="driverProfile?.verification_status === 'rejected' && driverProfile.verification_rejection_reason"
                                class="text-sm text-arka-danger bg-arka-danger/10 p-3 rounded-arka"
                            >
                                Motivo del rechazo: {{ driverProfile.verification_rejection_reason }}
                            </p>

                            <!-- Pedido explícito del usuario: mientras está "en
                                 revisión", no se puede subir una foto nueva — recién se
                                 habilita de nuevo si se rechaza. -->
                            <p v-if="driverProfile?.verification_status === 'pending'" class="text-sm text-arka-warning bg-arka-warning/10 p-3 rounded-arka">
                                Su documentación está en revisión — no puede reemplazar archivos hasta que un administrador la revise.
                            </p>

                            <div>
                                <InputLabel value="Tipo de conductor" />
                                <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <label
                                        v-for="option in [
                                            { value: 'independent', title: 'Conductor independiente', detail: 'Trabaja por cuenta propia.' },
                                            { value: 'public_transport', title: 'Transporte público', detail: 'Puede ser invitado por una cooperativa verificada.' },
                                        ]"
                                        :key="option.value"
                                        class="cursor-pointer rounded-arka border p-3 transition"
                                        :class="form.driver_type === option.value ? 'border-arka-primary bg-arka-primary/10' : 'border-arka-text-muted/20 bg-arka-surface'"
                                    >
                                        <input v-model="form.driver_type" type="radio" :value="option.value" class="sr-only" />
                                        <span class="block text-sm font-semibold text-arka-text">{{ option.title }}</span>
                                        <span class="mt-1 block text-xs text-arka-text-muted">{{ option.detail }}</span>
                                    </label>
                                </div>
                                <InputError class="mt-2" :message="form.errors.driver_type" />
                                <div v-if="form.driver_type === 'public_transport'" class="mt-3 rounded-arka border border-arka-primary/20 bg-arka-primary/5 p-3">
                                    <p class="text-sm font-semibold text-arka-primary-bright">Beneficios de ser Transporte Público Verificado</p>
                                    <ul class="mt-2 grid list-inside list-disc gap-1 text-xs text-arka-text-muted sm:grid-cols-2">
                                        <li>Mayor visibilidad y prioridad en búsquedas</li>
                                        <li>Etiqueta de confianza ante clientes</li>
                                        <li>Mayor credibilidad dentro de Arka01</li>
                                        <li>Posibilidad de pertenecer a cooperativas verificadas</li>
                                    </ul>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="profile_photo" value="Fotografía de perfil" />
                                    <img
                                        v-if="$page.props.auth.user.avatar_url"
                                        :src="$page.props.auth.user.avatar_url"
                                        class="mt-1 h-24 w-full rounded-arka object-cover"
                                        alt="Foto de perfil actual"
                                    />
                                    <input
                                        id="profile_photo"
                                        type="file"
                                        accept="image/*"
                                        class="mt-1 block w-full text-sm text-arka-text-muted file:mr-3 file:rounded-arka file:border-0 file:bg-arka-primary file:px-3 file:py-1.5 file:text-arka-base"
                                        @input="form.profile_photo = $event.target.files[0]"
                                    />
                                    <InputError class="mt-2" :message="form.errors.profile_photo" />
                                </div>

                                <div>
                                    <InputLabel for="identity_document" value="Cédula de identidad" />
                                    <a
                                        v-if="driverProfile?.identity_document_url"
                                        :href="driverProfile.identity_document_url"
                                        target="_blank"
                                        class="mt-2 block text-sm font-medium text-arka-primary-bright hover:underline"
                                    >Ver documento actual</a>
                                    <input
                                        id="identity_document"
                                        type="file"
                                        accept="image/*,application/pdf"
                                        :disabled="driverProfile?.verification_status === 'pending'"
                                        class="mt-1 block w-full text-sm text-arka-text-muted file:mr-3 file:rounded-arka file:border-0 file:bg-arka-primary file:px-3 file:py-1.5 file:text-arka-base disabled:opacity-50"
                                        @input="form.identity_document = $event.target.files[0]"
                                    />
                                    <InputError class="mt-2" :message="form.errors.identity_document" />
                                </div>

                                <div>
                                    <InputLabel for="license_photo" value="Licencia de conducir" />
                                    <a
                                        v-if="driverProfile?.license_photo_url"
                                        :href="driverProfile.license_photo_url"
                                        target="_blank"
                                        class="mt-2 block text-sm font-medium text-arka-primary-bright hover:underline"
                                    >Ver documento actual</a>
                                    <input
                                        id="license_photo"
                                        type="file"
                                        accept="image/*,application/pdf"
                                        :disabled="driverProfile?.verification_status === 'pending'"
                                        class="mt-1 block w-full text-sm text-arka-text-muted file:mr-3 file:py-1.5 file:px-3 file:rounded-arka file:border-0 file:bg-arka-primary file:text-arka-base disabled:opacity-50"
                                        @input="form.license_photo = $event.target.files[0]"
                                    />
                                    <InputError class="mt-2" :message="form.errors.license_photo" />
                                </div>

                                <div>
                                    <InputLabel for="police_record" value="Certificado de antecedentes penales" />
                                    <a
                                        v-if="driverProfile?.police_record_url"
                                        :href="driverProfile.police_record_url"
                                        target="_blank"
                                        class="mt-2 block text-sm font-medium text-arka-primary-bright hover:underline"
                                    >Ver documento actual</a>
                                    <input
                                        id="police_record"
                                        type="file"
                                        accept="image/*,application/pdf"
                                        :disabled="driverProfile?.verification_status === 'pending'"
                                        class="mt-1 block w-full text-sm text-arka-text-muted file:mr-3 file:py-1.5 file:px-3 file:rounded-arka file:border-0 file:bg-arka-primary file:text-arka-base disabled:opacity-50"
                                        @input="form.police_record = $event.target.files[0]"
                                    />
                                    <InputError class="mt-2" :message="form.errors.police_record" />
                                </div>
                            </div>
                            <p class="text-xs text-arka-text-muted">No solicitamos fotografía del vehículo. Los datos técnicos del auto se validan en el perfil.</p>

                            <!-- Pedido explícito del usuario: seguro que lo proteja a él,
                                 a los pasajeros y al vehículo — autodeclarado con un
                                 checkbox, sin documento adjunto (a diferencia de los 3
                                 de arriba). Forma parte de lo que evalúa administración. -->
                            <label class="mt-4 flex items-start gap-2">
                                <Checkbox v-model:checked="form.has_insurance" class="mt-0.5" />
                                <span class="text-sm text-arka-text">Cuento con un seguro vigente que me protege a mí, a los pasajeros y al vehículo</span>
                            </label>
                            <InputError class="mt-2" :message="form.errors.has_insurance" />
                        </div>
                        </section>

                        <!-- Cuentas bancarias (pedido explícito del usuario): el
                             cliente las ve (la favorita primero) cuando la carrera
                             es por transferencia y usted va en camino a
                             recogerlo — ver Ride/Show.vue. -->
                        <section id="driver-bank-settings" class="overflow-hidden rounded-2xl border border-arka-text-muted/10 bg-arka-base/25">
                            <button type="button" class="flex w-full items-center gap-3 p-4 text-left" @click="toggleProfileSection('bank')">
                                <SectionIcon name="bank" />
                                <span class="min-w-0 flex-1">
                                    <span class="block font-semibold text-arka-text">4. Cuentas bancarias</span>
                                    <span class="mt-0.5 block text-xs text-arka-text-muted">
                                        {{ bankAccounts.length ? `${bankAccounts.length} cuenta${bankAccounts.length === 1 ? '' : 's'} declarada${bankAccounts.length === 1 ? '' : 's'}` : 'Ninguna cuenta declarada' }}
                                    </span>
                                </span>
                                <svg class="h-5 w-5 shrink-0 text-arka-text-muted transition-transform" :class="activeProfileSection === 'bank' ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                                </svg>
                            </button>
                            <div v-show="activeProfileSection === 'bank'" class="space-y-5 border-t border-arka-text-muted/10 p-4 sm:p-5">
                                <p class="text-xs text-arka-text-muted">
                                    El cliente las ve cuando le toca pagar por transferencia y usted va en camino a recogerlo —
                                    la favorita aparece primero.
                                </p>

                                <div v-if="bankAccounts.length" class="space-y-2">
                                    <div
                                        v-for="account in bankAccounts"
                                        :key="account.id"
                                        class="flex items-center justify-between gap-3 rounded-xl border p-3"
                                        :class="account.is_favorite ? 'border-arka-primary bg-arka-primary/5' : 'border-arka-text-muted/15'"
                                    >
                                        <div class="min-w-0">
                                            <p class="flex items-center gap-1.5 text-sm font-medium text-arka-text">
                                                <span v-if="account.is_favorite" class="text-arka-primary" aria-label="Favorita">★</span>
                                                {{ account.bank_name }}
                                            </p>
                                            <p class="text-xs text-arka-text-muted">
                                                {{ account.account_holder_name }} ·
                                                {{ account.account_type === 'ahorros' ? 'Ahorros' : 'Corriente' }} · {{ account.account_number }}
                                            </p>
                                        </div>
                                        <div class="flex shrink-0 items-center gap-2">
                                            <button v-if="!account.is_favorite" type="button" class="text-xs font-medium text-arka-primary hover:underline" @click="markBankAccountFavorite(account)">Marcar favorita</button>
                                            <button type="button" class="text-xs font-medium text-arka-danger hover:underline" @click="deleteBankAccount(account)">Eliminar</button>
                                        </div>
                                    </div>
                                </div>

                                <form @submit.prevent="submitBankAccount" class="space-y-4 border-t border-arka-text-muted/10 pt-4">
                                    <p class="text-sm font-medium text-arka-text">Agregar una cuenta</p>
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <div class="sm:col-span-2">
                                            <InputLabel for="account_holder_name" value="Nombre del titular de la cuenta" />
                                            <TextInput id="account_holder_name" class="mt-1 block w-full" v-model="bankAccountForm.account_holder_name" maxlength="120" />
                                            <p class="mt-1 text-xs text-arka-text-muted">Use el nombre que está registrado en el banco. Puede cambiarlo si la cuenta pertenece a otra persona.</p>
                                            <InputError class="mt-1" :message="bankAccountForm.errors.account_holder_name" />
                                        </div>
                                        <div>
                                            <InputLabel for="identity_number" value="Cédula del titular" />
                                            <TextInput id="identity_number" class="mt-1 block w-full" v-model="bankAccountForm.identity_number" maxlength="10" />
                                            <InputError class="mt-1" :message="bankAccountForm.errors.identity_number" />
                                        </div>
                                        <div>
                                            <InputLabel for="bank_name" value="Banco" />
                                            <select id="bank_name" v-model="selectedBank" class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-arka-base text-arka-text focus:border-arka-primary focus:ring-arka-primary">
                                                <option value="" disabled>Seleccione un banco</option>
                                                <option v-for="bank in banks" :key="bank" :value="bank">{{ bank }}</option>
                                            </select>
                                            <TextInput
                                                v-if="selectedBank === 'Otro'"
                                                class="mt-2 block w-full"
                                                v-model="customBankName"
                                                placeholder="Nombre del banco o cooperativa"
                                            />
                                            <InputError class="mt-1" :message="bankAccountForm.errors.bank_name" />
                                        </div>
                                        <div>
                                            <InputLabel for="account_type" value="Tipo de cuenta" />
                                            <select id="account_type" v-model="bankAccountForm.account_type" class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-arka-base text-arka-text focus:border-arka-primary focus:ring-arka-primary">
                                                <option value="ahorros">Ahorros</option>
                                                <option value="corriente">Corriente</option>
                                            </select>
                                            <InputError class="mt-1" :message="bankAccountForm.errors.account_type" />
                                        </div>
                                        <div>
                                            <InputLabel for="account_number" value="Número de cuenta" />
                                            <TextInput id="account_number" class="mt-1 block w-full" v-model="bankAccountForm.account_number" />
                                            <InputError class="mt-1" :message="bankAccountForm.errors.account_number" />
                                        </div>
                                    </div>
                                    <SecondaryButton type="submit" :disabled="bankAccountForm.processing">Agregar cuenta</SecondaryButton>
                                </form>
                            </div>
                        </section>

                        <section id="driver-work-settings" class="overflow-hidden rounded-2xl border border-arka-text-muted/10 bg-arka-base/25">
                            <button type="button" class="flex w-full items-center gap-3 p-4 text-left" @click="toggleProfileSection('work')">
                                <SectionIcon name="rates" />
                                <span class="min-w-0 flex-1">
                                    <span class="block font-semibold text-arka-text">5. Tarifas y forma de trabajo</span>
                                    <span class="mt-0.5 block text-xs text-arka-text-muted">
                                        {{ form.rate_per_km !== '' ? `$${Number(form.rate_per_km).toFixed(2)}/km` : 'Tarifa pendiente' }} · {{ form.max_request_distance_km ? `${form.max_request_distance_km} km de cobertura` : 'Sin límite de cobertura' }}
                                    </span>
                                </span>
                                <svg class="h-5 w-5 shrink-0 text-arka-text-muted transition-transform" :class="activeProfileSection === 'work' ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                                </svg>
                            </button>
                            <div v-show="activeProfileSection === 'work'" class="space-y-5 border-t border-arka-text-muted/10 p-4 sm:p-5">
                        <!-- Tarifa y forma de pago: el conductor define su propio precio,
                             la plataforma no lo impone (sección 5 del alcance) -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <InputLabel for="rate_per_km" value="Tarifa por km (USD)" />
                                <TextInput
                                    id="rate_per_km"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    class="mt-1 block w-full"
                                    v-model="form.rate_per_km"
                                    required
                                />
                                <InputError class="mt-2" :message="form.errors.rate_per_km" />
                            </div>

                            <div>
                                <InputLabel for="minimum_fare" value="Tarifa mínima (USD, opcional)" />
                                <TextInput
                                    id="minimum_fare"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    :max="platformMinimumFare"
                                    class="mt-1 block w-full"
                                    v-model="form.minimum_fare"
                                />
                                <!-- Pedido explícito del usuario: que se le indique en su
                                     configuración que la plataforma no permite superar el
                                     tope general — puede poner una MENOR, esa sí se respeta
                                     en el cálculo del precio (ver PriceCalculator). -->
                                <p class="mt-1 text-xs text-arka-text-muted">
                                    No puede superar ${{ platformMinimumFare.toFixed(2) }} (tope de la plataforma). Si la deja en blanco, se usa ese tope.
                                </p>
                                <InputError class="mt-2" :message="form.errors.minimum_fare" />
                            </div>
                        </div>

                        <!-- Zona de cobertura (pedido explícito del usuario): evita que
                             lleguen solicitudes que no convienen por los km, aunque el
                             cliente sea de la propia flota (ej. vivir en Samborondón y que
                             llegue una del Guasmo). -->
                        <div>
                            <InputLabel for="max_request_distance_km" value="Distancia máxima para recibir solicitudes (km, opcional)" />
                            <TextInput
                                id="max_request_distance_km"
                                type="number"
                                step="1"
                                min="1"
                                class="mt-1 block w-full sm:w-1/2"
                                v-model="form.max_request_distance_km"
                                placeholder="Sin límite"
                            />
                            <p class="mt-1 text-xs text-arka-text-muted">
                                Si la deja en blanco, le siguen llegando solicitudes sin importar la distancia. Se
                                mide desde su ubicación actual — incluso las de sus clientes de confianza quedan
                                afuera si superan este límite.
                            </p>
                            <InputError class="mt-2" :message="form.errors.max_request_distance_km" />
                        </div>

                        <!-- Cargo por distancia de recogida (pedido explícito del
                             usuario): interruptor general, igual jerarquía que la
                             tarifa por km — con esto apagado, la función no existe
                             para este conductor en ninguna solicitud. -->
                        <div class="rounded-xl border border-arka-text-muted/15 p-3.5">
                            <label class="flex items-start gap-3">
                                <Checkbox v-model:checked="form.pickup_surcharge_enabled" class="mt-0.5" />
                                <span>
                                    <span class="block text-sm font-medium text-arka-text">Cobrar por distancia de recogida</span>
                                    <span class="mt-0.5 block text-xs text-arka-text-muted">
                                        Cuando el cliente esté a más de {{ pickupSurchargeThresholdKm }} km de usted, va a poder ver un
                                        cargo adicional (hasta el {{ pickupSurchargePercent }}%) y decidir si lo cobra, solicitud por
                                        solicitud. Apague esto si nunca quiere ver esa opción.
                                    </span>
                                </span>
                            </label>
                            <InputError class="mt-2" :message="form.errors.pickup_surcharge_enabled" />
                        </div>

                        <div>
                            <InputLabel value="Métodos de pago que acepta" />
                            <div class="mt-2 flex flex-wrap gap-3">
                                <label class="flex items-center rounded-xl border border-arka-text-muted/15 px-3 py-2.5">
                                    <Checkbox v-model:checked="form.accepts_cash" />
                                    <span class="ms-2 text-sm text-arka-text">Efectivo</span>
                                </label>
                                <label class="flex items-center rounded-xl border border-arka-text-muted/15 px-3 py-2.5">
                                    <Checkbox v-model:checked="form.accepts_transfer" />
                                    <span class="ms-2 text-sm text-arka-text">Transferencia</span>
                                </label>
                            </div>
                        </div>
                            </div>
                        </section>

                        <section id="driver-visibility-settings" class="overflow-hidden rounded-2xl border border-arka-text-muted/10 bg-arka-base/25">
                            <button type="button" class="flex w-full items-center gap-3 p-4 text-left" @click="toggleProfileSection('visibility')">
                                <SectionIcon name="visibility" />
                                <span class="min-w-0 flex-1">
                                    <span class="block font-semibold text-arka-text">Visibilidad e invitación</span>
                                    <span class="mt-0.5 block text-xs text-arka-text-muted">
                                        {{ form.profile_public ? 'Perfil individual visible' : 'Perfil individual privado' }} · {{ tierLabel(tier) }}
                                    </span>
                                </span>
                                <svg class="h-5 w-5 shrink-0 text-arka-text-muted transition-transform" :class="activeProfileSection === 'visibility' ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                                </svg>
                            </button>
                            <div v-show="activeProfileSection === 'visibility'" class="space-y-5 border-t border-arka-text-muted/10 p-4 sm:p-5">
                        <!-- Compartir perfil para que lo agreguen a una flota (pedido
                             explícito del usuario): mismo código/QR que ya usaba el
                             encabezado, reubicado acá — justo después de los
                             parámetros de la carrera — para que quien ya completó su
                             perfil como conductor lo comparta enseguida. El canvas se
                             ubica por id (`getElementById('invite-qr')`), así que
                             moverlo de lugar en la plantilla no afecta cómo se dibuja. -->
                        <div v-if="driverProfile" class="p-3 rounded-arka bg-arka-base/60">
                            <p class="text-sm text-arka-text">Comparta su perfil para que lo agreguen a una flota</p>
                            <div class="mt-2 flex items-center gap-4">
                                <canvas id="invite-qr" class="rounded-arka bg-white p-2"></canvas>
                                <div>
                                    <p class="text-sm text-arka-text-muted">
                                        Su código de invitación (compártalo o deje que le escaneen el QR):
                                    </p>
                                    <span class="font-mono text-lg text-arka-primary-bright">{{ driverProfile.invite_code }}</span>
                                    <a
                                        :href="whatsappInviteUrl"
                                        target="_blank"
                                        rel="noopener"
                                        class="mt-2 flex items-center gap-1.5 text-sm text-arka-primary hover:text-arka-primary-bright"
                                    >
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M12.04 2c-5.5 0-9.96 4.46-9.96 9.96 0 1.76.46 3.45 1.32 4.95L2 22l5.2-1.36a9.9 9.9 0 0 0 4.84 1.24h.01c5.5 0 9.96-4.46 9.96-9.96S17.54 2 12.04 2Zm0 18.2h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.09.81.82-3-.2-.31a8.2 8.2 0 0 1-1.26-4.4c0-4.55 3.7-8.24 8.25-8.24 2.2 0 4.27.86 5.83 2.42a8.18 8.18 0 0 1 2.41 5.82c0 4.55-3.7 8.23-8.26 8.23Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.4-.12-.56.13-.17.25-.65.81-.79.97-.15.17-.29.19-.54.06-.25-.12-1.05-.39-2-1.23-.74-.66-1.24-1.47-1.39-1.72-.14-.25-.02-.38.11-.51.11-.11.25-.29.37-.43.12-.14.16-.25.25-.41.08-.17.04-.31-.02-.44-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.42h-.48c-.17 0-.44.06-.67.31s-.88.86-.88 2.09.9 2.42 1.02 2.59c.13.17 1.77 2.7 4.29 3.79.6.26 1.07.41 1.43.53.6.19 1.15.16 1.58.1.48-.07 1.47-.6 1.68-1.18.21-.58.21-1.08.14-1.18-.06-.1-.23-.16-.48-.28Z" />
                                        </svg>
                                        Compartir por WhatsApp
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Medalla por puntos (pedido explícito del usuario): cada
                             carrera COMPLETADA por la app suma puntos según la
                             distancia — arreglar directo por fuera no da nada. A
                             partir de cierta medalla (hoy Oro) se habilita para el
                             directorio público, además del plan — ver más abajo. -->
                        <div class="p-3 rounded-arka bg-arka-base/60">
                            <p class="text-sm text-arka-text">
                                Su medalla actual:
                                <span class="ms-1 px-1.5 py-0.5 rounded text-xs font-medium" :class="tierColorClass(tier.color_key)">
                                    {{ tierLabel(tier) }}
                                </span>
                                <span class="text-xs text-arka-text-muted">({{ totalPoints }} punto{{ totalPoints === 1 ? '' : 's' }})</span>
                            </p>
                            <p class="mt-1 text-xs text-arka-text-muted">
                                Cada carrera que completa desde la app suma 1 o 2 puntos según la distancia.
                            </p>
                            <p v-if="nextPublicTier" class="mt-1 text-xs text-arka-text-muted">
                                Le faltan <strong class="text-arka-text">{{ nextPublicTier.min_points - totalPoints }}</strong> puntos
                                para
                                <span class="px-1 rounded" :class="tierColorClass(nextPublicTier.color_key)">{{ tierLabel(nextPublicTier) }}</span>
                                y poder aparecer en el directorio público.
                            </p>
                        </div>

                        <!-- "Mi cooperativa" (pedido explícito del usuario):
                             estado, desde cuándo, y que el acceso está
                             cubierto por la cooperativa — nunca mezclado con
                             el acceso profesional (plan pagado), que se
                             muestra aparte si también lo tiene. Un plan con
                             "multi_cooperative_enabled" puede dejarlo afiliado
                             a más de una a la vez — se listan todas, no solo
                             la primera. -->
                        <div v-if="driverAccess?.cooperatives?.length" class="space-y-2">
                            <div v-for="cooperative in driverAccess.cooperatives" :key="cooperative.id" class="p-3 rounded-arka bg-arka-base/60">
                                <p class="text-xs uppercase tracking-wider text-arka-text-muted">Mi cooperativa</p>
                                <p class="mt-1 text-sm font-medium text-arka-text">{{ cooperative.name }}</p>
                                <p class="mt-1 text-xs text-arka-text-muted">
                                    Estado: Activo · Acceso cubierto por la cooperativa
                                    <span v-if="cooperative.member_since">
                                        · Desde {{ new Date(cooperative.member_since).toLocaleDateString('es-EC', { day: 'numeric', month: 'short', year: 'numeric' }) }}
                                    </span>
                                </p>
                            </div>
                            <p v-if="driverAccess.professional_access" class="text-xs font-medium text-arka-primary">
                                Acceso profesional: Activo
                            </p>
                        </div>

                        <!-- Billetera cooperativa-conductor (pedido explícito
                             del usuario): saldo neto con su cooperativa —
                             carreras en efectivo donde se quedó con el margen
                             de la cooperativa, compensadas con las de
                             transferencia donde fue al revés. Bug real
                             reportado ("la billetera no está funcionando"):
                             con saldo 0 esta sección desaparecía sin ningún
                             aviso, como si la función no existiera — mismo
                             criterio que ya tiene Cooperative/DriverShow.vue
                             del otro lado. -->
                        <div v-if="cooperativeWallet && cooperativeWallet.balance !== 0" class="p-3 rounded-arka" :class="cooperativeWallet.balance > 0 ? 'bg-arka-warning/10' : 'bg-arka-primary/10'">
                            <p class="text-sm font-medium" :class="cooperativeWallet.balance > 0 ? 'text-arka-warning' : 'text-arka-primary'">
                                {{ cooperativeWallet.balance > 0
                                    ? `Le debe $${cooperativeWallet.balance.toFixed(2)} a ${cooperativeWallet.cooperative_name}`
                                    : `${cooperativeWallet.cooperative_name} le debe $${Math.abs(cooperativeWallet.balance).toFixed(2)}` }}
                            </p>
                            <p class="mt-1 text-xs text-arka-text-muted">
                                {{ cooperativeWallet.balance > 0
                                    ? 'Por carreras en efectivo cuyo margen le correspondía a la cooperativa.'
                                    : 'Por carreras por transferencia cuya parte le correspondía a usted.' }}
                            </p>
                        </div>
                        <div v-else-if="cooperativeWallet" class="p-3 rounded-arka bg-arka-base/60">
                            <p class="text-sm font-medium text-arka-text">Billetera con {{ cooperativeWallet.cooperative_name }}</p>
                            <p class="mt-1 text-xs text-arka-text-muted">Sin saldo pendiente por ahora.</p>
                        </div>

                        <!-- Directorio público (sección 3.4): habilitado desde el plan
                             Plus en adelante (sección 7.2) Y desde que se gana la
                             medalla correspondiente (pedido explícito del usuario, ver
                             arriba). Si el plan actual no lo incluye, se muestra
                             deshabilitado con la invitación a subir. -->
                        <div>
                            <label class="flex items-center" :class="{ 'opacity-50': !planLimits.public_visibility }">
                                <Checkbox v-model:checked="form.is_public" :disabled="!planLimits.public_visibility" />
                                <span class="ms-2 text-sm text-arka-text">
                                    Aparecer en el directorio público de conductores
                                </span>
                            </label>
                            <p class="mt-1 text-xs text-arka-text-muted">
                                Cualquier cliente va a poder encontrarlo y agregarlo a su flota, aunque no lo
                                conozca todavía. Solo se muestra a partir de la medalla que habilite el directorio.
                            </p>
                            <p v-if="!planLimits.public_visibility" class="mt-1 text-xs text-arka-warning">
                                Disponible desde el plan Plus.
                                <a :href="route('driver.plan.edit')" class="underline hover:text-arka-primary-bright">
                                    Ver planes
                                </a>
                            </p>
                        </div>

                        <!-- Pedido explícito del usuario ("mejoremos la privacidad de
                             los conductores"): distinto del directorio de arriba —
                             ese es "que me encuentren buscando"; esto es "qué ve
                             alguien que ya tiene mi enlace" (compartido por QR o por
                             WhatsApp, ver "Compartir mi perfil"). Sin gateo por plan:
                             es control de privacidad, no una ventaja paga. -->
                        <div>
                            <label class="flex items-center">
                                <Checkbox v-model:checked="form.profile_public" />
                                <span class="ms-2 text-sm text-arka-text">
                                    Habilitar mi perfil individual al público
                                </span>
                            </label>
                            <p class="mt-1 text-xs text-arka-text-muted">
                                Si lo apaga, quien abra su enlace de perfil (por QR o WhatsApp) va a ver su nombre
                                nomás, sin vehículo, tarifa ni comentarios — usted y un admin siguen viendo todo
                                normal.
                            </p>
                        </div>
                            </div>
                        </section>

                        <div class="sticky bottom-20 z-20 flex items-center gap-4 rounded-2xl border border-arka-primary/20 bg-arka-card/95 p-3 shadow-xl backdrop-blur sm:static sm:border-0 sm:bg-transparent sm:p-0 sm:shadow-none">
                            <PrimaryButton class="min-h-11 flex-1 justify-center sm:flex-none" :disabled="form.processing">
                                {{ driverProfile ? 'Guardar cambios' : 'Activar perfil de conductor' }}
                            </PrimaryButton>

                            <Transition
                                enter-active-class="transition ease-in-out"
                                enter-from-class="opacity-0"
                                leave-active-class="transition ease-in-out"
                                leave-to-class="opacity-0"
                            >
                                <p v-if="form.recentlySuccessful" class="text-sm text-arka-text-muted">Guardado.</p>
                            </Transition>
                        </div>
                    </form>
                </div>

                <!-- Medición local y orientativa: no envía telemetría al
                     servidor. El conductor puede consultarla al final de su
                     perfil sin ocupar espacio permanente en la cabecera. -->
                <div v-if="driverProfile" class="mt-6 rounded-arka border border-arka-text-muted/10 bg-arka-card p-4 shadow">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h3 class="font-medium text-arka-text">Uso aproximado de datos</h3>
                            <p class="mt-1 text-xs text-arka-text-muted">Se guarda únicamente en este dispositivo y no se envía a Arka01.</p>
                        </div>
                        <SessionDataUsage :user-id="$page.props.auth.user.id" />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
