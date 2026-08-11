<script setup>
import { computed, nextTick, watch } from 'vue';
import QRCode from 'qrcode';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { buildWhatsAppOptInUrl } from '@/Utils/whatsapp';
import { tierColorClass, tierLabel } from '@/Utils/tierBadge';

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
});

const WHATSAPP_STATUS_LABEL = { active: 'Activa', expiring_soon: 'Próxima a vencer', expired: 'Expirada' };

// Misma lista que RegisteredUserController::COUNTRY_CODES.
const countryCodes = [
    { code: '+593', label: '🇪🇨 +593 Ecuador' },
    { code: '+51', label: '🇵🇪 +51 Perú' },
    { code: '+57', label: '🇨🇴 +57 Colombia' },
    { code: '+58', label: '🇻🇪 +58 Venezuela' },
    { code: '+1', label: '🇺🇸 +1 EE.UU./Canadá' },
    { code: '+34', label: '🇪🇸 +34 España' },
];

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
const drawInviteQr = async () => {
    if (!props.driverProfile) return;

    await nextTick();
    const canvas = document.getElementById('invite-qr');
    if (canvas) {
        await QRCode.toCanvas(canvas, props.driverProfile.invite_code, { width: 160, margin: 1 });
    }
};

watch(() => props.driverProfile?.invite_code, drawInviteQr, { immediate: true });

// Mismo formulario sirve para crear el perfil por primera vez o para editarlo
// después: si ya existe, se precargan sus valores.
const form = useForm({
    // Cambio de número de WhatsApp (pedido explícito del usuario): en blanco
    // por defecto — el backend no toca el teléfono actual si no se completa.
    country_code: '+593',
    phone_local: '',
    license_number: props.driverProfile?.license_number ?? '',
    vehicle_make: props.driverProfile?.vehicle_make ?? '',
    vehicle_model: props.driverProfile?.vehicle_model ?? '',
    vehicle_color: props.driverProfile?.vehicle_color ?? '',
    vehicle_plate: props.driverProfile?.vehicle_plate ?? '',
    vehicle_year: props.driverProfile?.vehicle_year ?? '',
    // Pedido explícito del usuario: para que un cliente pueda filtrar
    // conductores por cantidad de pasajeros y cajuela al pedir una carrera.
    passenger_capacity: props.driverProfile?.passenger_capacity ?? '',
    has_trunk: props.driverProfile?.has_trunk ?? false,
    rate_per_km: props.driverProfile?.rate_per_km ?? '',
    minimum_fare: props.driverProfile?.minimum_fare ?? '',
    max_request_distance_km: props.driverProfile?.max_request_distance_km ?? '',
    accepts_cash: props.driverProfile?.accepts_cash ?? true,
    accepts_transfer: props.driverProfile?.accepts_transfer ?? false,
    is_public: props.driverProfile?.is_public ?? false,
    license_photo: null,
    vehicle_photo: null,
});

// Si el color ya guardado no está en la lista fija (ej. dato viejo cargado
// como texto libre, antes de este cambio), se agrega igual como opción para
// no perder el valor actual al abrir el formulario.
const vehicleColorOptions = computed(() => {
    const current = props.driverProfile?.vehicle_color;
    return current && !VEHICLE_COLORS.includes(current) ? [current, ...VEHICLE_COLORS] : VEHICLE_COLORS;
});

// Mismo criterio que DriverProfile::hasCompleteVehicleInfo() (backend) —
// duplicado acá nomás para el aviso, la validación real siempre la hace el
// servidor.
const vehicleInfoComplete = computed(() => {
    const p = props.driverProfile;
    return Boolean(p?.vehicle_make && p?.vehicle_model && p?.vehicle_color && p?.vehicle_plate && p?.vehicle_year && p?.passenger_capacity);
});

// "Tu estado" (pedido explícito del usuario: "si falta que el conductor
// cumpla con algo debés indicarle para que sepa dónde aparecerá y qué
// necesita para eso") — de un vistazo, qué está activo, qué falta y por qué.
const statusItems = computed(() => {
    const p = props.driverProfile;
    if (!p) return [];

    const items = [
        {
            label: 'Datos del vehículo completos',
            ok: vehicleInfoComplete.value,
            detail: vehicleInfoComplete.value
                ? 'Puede ponerse disponible desde Inicio.'
                : 'Le faltan datos del vehículo (marca, modelo, color, placa, año o pasajeros) — complételos abajo y guarde.',
        },
        {
            label: 'Disponible ahora',
            ok: p.is_available,
            detail: p.is_available
                ? 'Los clientes lo ven en "Mi flota" y en el directorio si es público.'
                : 'Actívese desde el switch "Activarme" en Inicio (necesita los datos del vehículo completos).',
        },
    ];

    // Directorio público (sección 3.4): dos motivos posibles para no estar,
    // hay que distinguirlos para que sepa cuál de los dos le toca a él.
    const directoryVisible = p.is_public && props.planLimits.public_visibility;
    items.push({
        label: 'Visible en el directorio público',
        ok: directoryVisible,
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
        label: 'Insignia de verificado',
        ok: verifiedBadgeVisible,
        detail: verifiedBadgeVisible
            ? 'Se ve en su perfil y en el directorio público.'
            : p.verification_status === 'rejected'
              ? `Su verificación fue rechazada${p.verification_rejection_reason ? `: "${p.verification_rejection_reason}"` : ''} — corrija eso y vuelva a subir sus fotos más abajo.`
              : p.verification_status == null
                ? 'Todavía no subió sus documentos — suba una foto de su licencia y del vehículo más abajo.'
                : p.verification_status !== 'approved'
                  ? `Su verificación está "${VERIFICATION_LABELS[p.verification_status]}" — suba una foto de su licencia y del vehículo más abajo.`
                  : 'Ya está verificado, pero su plan actual no incluye la insignia — hace falta un plan superior.',
    });

    return items;
});

const submit = () => {
    form.post(route('driver.profile.update'), { forceFormData: true });
};

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

        <div class="py-12">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="p-4 sm:p-8 bg-arka-card shadow sm:rounded-arka">
                    <header class="mb-6">
                        <p class="text-sm text-arka-text-muted">
                            Cualquier usuario puede ser cliente y conductor a la vez. Complete estos datos para que
                            otros clientes puedan invitarlo a sus flotas.
                        </p>

                        <!-- "Tu estado" (pedido explícito del usuario): de un vistazo,
                             qué está activo y qué falta para cada cosa (disponible,
                             directorio público, insignia de verificado) — y por qué,
                             no solo "sí" o "no". -->
                        <div v-if="driverProfile" class="mt-4 space-y-2">
                            <div
                                v-for="item in statusItems"
                                :key="item.label"
                                class="p-3 rounded-arka text-sm"
                                :class="item.ok ? 'bg-arka-primary/10' : 'bg-arka-warning/10'"
                            >
                                <p class="font-medium" :class="item.ok ? 'text-arka-primary-bright' : 'text-arka-warning'">
                                    {{ item.ok ? '✓' : '○' }} {{ item.label }}
                                </p>
                                <p class="mt-0.5 text-arka-text-muted">{{ item.detail }}</p>
                            </div>
                        </div>

                        <div v-if="driverProfile" class="mt-4 flex items-center gap-4">
                            <canvas id="invite-qr" class="rounded-arka bg-white p-2"></canvas>
                            <div>
                                <p class="text-sm text-arka-text-muted">
                                    Su código de invitación (compártalo o deje que le escaneen el QR):
                                </p>
                                <span class="font-mono text-lg text-arka-primary-bright">{{ driverProfile.invite_code }}</span>
                            </div>
                        </div>

                        <!-- Avisos de carrera nueva por WhatsApp (pedido explícito
                             del usuario): estado de la ventana de 24h + link para
                             abrirla o renovarla escribiéndole al número oficial.
                             Mismo lenguaje de "pasos" que el widget de recuperar
                             sesión del login, para que se sienta el mismo mecanismo
                             en toda la app (escribir primero, el bot lo conecta solo). -->
                        <div v-if="driverProfile && whatsappBusinessNumber" class="mt-4 p-3 rounded-arka border border-arka-text-muted/20">
                            <p class="text-sm font-medium text-arka-text">Avisos de carrera nueva por WhatsApp</p>
                            <p class="mt-1 text-sm text-arka-text-muted">
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
                                class="mt-2 inline-block text-sm text-arka-primary hover:text-arka-primary-bright"
                            >
                                1. {{ whatsappSession && whatsappSession.status !== 'expired' ? 'Renovar por WhatsApp' : 'Conectar WhatsApp' }} &rarr;
                            </a>
                            <p class="mt-1 text-xs text-arka-text-muted">
                                2. Apenas lo mande, queda conectado solo — sin nada más que hacer acá.
                            </p>
                        </div>
                    </header>

                    <form @submit.prevent="submit" class="space-y-6">
                        <!-- Cambio de número de WhatsApp (pedido explícito del
                             usuario): es el número que se valida contra el que
                             usa para conectarse por WhatsApp
                             (WhatsAppWebhookController) y al que le llegan los
                             avisos de carrera nueva — tiene que poder
                             corregirlo si se equivocó o cambió de celular. -->
                        <div class="border-b border-arka-text-muted/10 pb-6">
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

                            <div class="mt-2 flex gap-2">
                                <select
                                    id="country_code"
                                    class="rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text"
                                    v-model="form.country_code"
                                >
                                    <option v-for="country in countryCodes" :key="country.code" :value="country.code">
                                        {{ country.label }}
                                    </option>
                                </select>

                                <TextInput
                                    id="phone_local"
                                    type="tel"
                                    class="block w-full"
                                    v-model="form.phone_local"
                                    autocomplete="tel-national"
                                    placeholder="Déjelo en blanco para no cambiarlo"
                                />
                            </div>
                            <p class="mt-1 text-xs text-arka-text-muted">
                                Sin el 0 inicial ni espacios. Si lo cambia, va a tener que verificarlo de nuevo por
                                WhatsApp.
                            </p>
                            <InputError class="mt-2" :message="form.errors.country_code" />
                            <InputError class="mt-1" :message="form.errors.phone_local" />
                        </div>

                        <div>
                            <InputLabel for="license_number" value="Número de licencia" />
                            <TextInput
                                id="license_number"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="form.license_number"
                                required
                            />
                            <InputError class="mt-2" :message="form.errors.license_number" />
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
                                    required
                                />
                                <InputError class="mt-2" :message="form.errors.vehicle_model" />
                            </div>

                            <div>
                                <InputLabel for="vehicle_color" value="Color" />
                                <select
                                    id="vehicle_color"
                                    v-model="form.vehicle_color"
                                    class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text"
                                    required
                                >
                                    <option value="" disabled>Elija un color</option>
                                    <option v-for="color in vehicleColorOptions" :key="color" :value="color">{{ color }}</option>
                                </select>
                                <InputError class="mt-2" :message="form.errors.vehicle_color" />
                            </div>

                            <div>
                                <InputLabel for="vehicle_plate" value="Placa" />
                                <TextInput
                                    id="vehicle_plate"
                                    type="text"
                                    class="mt-1 block w-full"
                                    v-model="form.vehicle_plate"
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
                                    required
                                />
                                <InputError class="mt-2" :message="form.errors.passenger_capacity" />
                            </div>
                        </div>

                        <label class="flex items-center">
                            <Checkbox v-model:checked="form.has_trunk" />
                            <span class="ms-2 text-sm text-arka-text">Tengo cajuela disponible para maletas</span>
                        </label>

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

                        <!-- Verificación visible antes de subir (sección 8): foto de la
                             licencia y del vehículo, revisadas por un admin. -->
                        <div class="space-y-4 border-t border-arka-text-muted/10 pt-4">
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
                                Su documentación está en revisión — no puede subir fotos nuevas hasta que un admin la revise.
                            </p>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="license_photo" value="Foto de su licencia" />
                                    <img
                                        v-if="driverProfile?.license_photo_url"
                                        :src="driverProfile.license_photo_url"
                                        class="mt-1 h-24 w-full object-cover rounded-arka"
                                        alt="Licencia actual"
                                    />
                                    <input
                                        id="license_photo"
                                        type="file"
                                        accept="image/*"
                                        :disabled="driverProfile?.verification_status === 'pending'"
                                        class="mt-1 block w-full text-sm text-arka-text-muted file:mr-3 file:py-1.5 file:px-3 file:rounded-arka file:border-0 file:bg-arka-primary file:text-arka-base disabled:opacity-50"
                                        @input="form.license_photo = $event.target.files[0]"
                                    />
                                    <InputError class="mt-2" :message="form.errors.license_photo" />
                                </div>

                                <div>
                                    <InputLabel for="vehicle_photo" value="Foto del vehículo" />
                                    <img
                                        v-if="driverProfile?.vehicle_photo_url"
                                        :src="driverProfile.vehicle_photo_url"
                                        class="mt-1 h-24 w-full object-cover rounded-arka"
                                        alt="Vehículo actual"
                                    />
                                    <input
                                        id="vehicle_photo"
                                        type="file"
                                        accept="image/*"
                                        :disabled="driverProfile?.verification_status === 'pending'"
                                        class="mt-1 block w-full text-sm text-arka-text-muted file:mr-3 file:py-1.5 file:px-3 file:rounded-arka file:border-0 file:bg-arka-primary file:text-arka-base disabled:opacity-50"
                                        @input="form.vehicle_photo = $event.target.files[0]"
                                    />
                                    <InputError class="mt-2" :message="form.errors.vehicle_photo" />
                                </div>
                            </div>
                        </div>

                        <div>
                            <InputLabel value="Métodos de pago que acepta" />
                            <div class="mt-2 space-y-2">
                                <label class="flex items-center">
                                    <Checkbox v-model:checked="form.accepts_cash" />
                                    <span class="ms-2 text-sm text-arka-text">Efectivo</span>
                                </label>
                                <label class="flex items-center">
                                    <Checkbox v-model:checked="form.accepts_transfer" />
                                    <span class="ms-2 text-sm text-arka-text">Transferencia</span>
                                </label>
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

                        <div class="flex items-center gap-4">
                            <PrimaryButton :disabled="form.processing">
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
            </div>
        </div>
    </AuthenticatedLayout>
</template>
