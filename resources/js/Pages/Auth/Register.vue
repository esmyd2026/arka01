<script setup>
import { computed, ref, watch } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

// Misma lista que RegisteredUserController::COUNTRY_CODES — es una lista fija
// de indicativos telefónicos reales, no un catálogo de negocio que necesite
// pantalla de mantenimiento (a diferencia de planes, tarifas o zonas).
// Bug real reportado por el usuario (con capturas): en móvil, mostrar el
// nombre del país entero en el selector ya cerrado le dejaba casi nada de
// ancho al campo del número, que quedaba aplastado en una cajita minúscula
// — shortLabel (ver SearchableSelect.vue) resuelve eso sin perder el nombre
// completo en la lista desplegable, donde sí hay espacio de sobra.
const countryCodes = [
    { code: '+593', label: '🇪🇨 +593 Ecuador', shortLabel: '🇪🇨 +593' },
    { code: '+51', label: '🇵🇪 +51 Perú', shortLabel: '🇵🇪 +51' },
    { code: '+57', label: '🇨🇴 +57 Colombia', shortLabel: '🇨🇴 +57' },
    { code: '+58', label: '🇻🇪 +58 Venezuela', shortLabel: '🇻🇪 +58' },
    { code: '+56', label: '🇨🇱 +56 Chile', shortLabel: '🇨🇱 +56' },
    { code: '+54', label: '🇦🇷 +54 Argentina', shortLabel: '🇦🇷 +54' },
];
const countryCodeOptions = countryCodes.map((c) => ({ value: c.code, label: c.label, shortLabel: c.shortLabel }));

// Pedido explícito del usuario: "Crear mi círculo" en Welcome.vue linkea acá
// con ?tipo=cliente — ya declaró la intención en ese botón, no hace falta
// volver a preguntarle el tipo de cuenta en el primer paso.
const preselectedAccountType = new URLSearchParams(window.location.search).get('tipo');
const validPreselection = ['cliente', 'conductor', 'cooperativa'].includes(preselectedAccountType) ? preselectedAccountType : '';

// Trazabilidad de referidos (pedido explícito del usuario): quién compartió
// el enlace que trajo a esta cuenta nueva — viaja oculto en el formulario,
// nadie lo escribe a mano (ver Referral/Show.vue, RegisteredUserController::store()).
const referrerId = new URLSearchParams(window.location.search).get('ref');

const form = useForm({
    account_type: validPreselection,
    first_name: '',
    last_name: '',
    email: '',
    country_code: '+593',
    phone_local: '',
    password: '',
    password_confirmation: '',
    ref: referrerId || null,
    lat: null,
    lng: null,
});

// Pedido explícito del usuario: "ver de dónde se registran las personas,
// por su ubicación" — se pide apenas se abre la pantalla, en silencio, para
// que ya esté lista para cuando mande el formulario en el último paso (no
// es un paso más del asistente, no agrega fricción). Si el navegador la
// niega, no la soporta, o tarda demasiado, el registro sigue igual — nunca
// bloquea nada (ver RegisteredUserController::store()).
if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
        (position) => {
            form.lat = position.coords.latitude;
            form.lng = position.coords.longitude;
        },
        () => {
            // Denegada, no disponible, o timeout — no pasa nada, se registra igual.
        },
        { timeout: 8000, maximumAge: 300000 }
    );
}

// Pedido explícito del usuario ("la gente se pierde" entre iniciar sesión y
// crear cuenta): si el correo o el teléfono ya tienen cuenta (mensajes
// puntuales de RegisteredUserController::store()), se ofrece el atajo a
// iniciar sesión en vez de dejar a la persona en un callejón sin salida.
const showsAccountExistsError = computed(
    () =>
        (form.errors.email ?? '').includes('¿Ya tiene una cuenta?') ||
        (form.errors.phone_local ?? '').includes('¿Ya tiene una cuenta?')
);

// Registro guiado paso a paso (consideración agregada al alcance: el usuario
// pidió explícitamente que no sea un formulario largo de una sola vez, sino
// que vaya "registrando poco a poco" con feedback de progreso) — un dato por
// pantalla, empezando por el tipo de cuenta porque cambia a dónde va apenas
// termina (ver RegisteredUserController::store()).
const STEPS = ['account_type', 'name', 'email', 'phone', 'password'];
const currentStep = ref(validPreselection ? 1 : 0);

// Mismo ícono de "ojito" que ya usa Auth/Login.vue (pedido explícito del
// usuario) — un solo toggle para las dos contraseñas, no tiene sentido
// mostrar una sí y la otra no cuando lo que se está comparando es que
// coincidan.
const showPassword = ref(false);
const isLastStep = computed(() => currentStep.value === STEPS.length - 1);

// Feedback en vivo de qué le falta a la contraseña (más intuitivo que
// enterarse recién al mandar el formulario) — mismas reglas que el backend
// (Rules\Password::defaults()->min(8)->mixedCase()->numbers()).
const passwordChecks = computed(() => ({
    length: form.password.length >= 8,
    mixedCase: /[a-z]/.test(form.password) && /[A-Z]/.test(form.password),
    number: /\d/.test(form.password),
}));

// Mismo criterio que App\Rules\ValidPhoneNumberLocal (backend): para Ecuador
// un celular real tiene 9 dígitos y empieza en 9 (sin el 0 inicial, que ya
// lo reemplaza el código de país), y se descartan números "de relleno"
// obvios como 999999999. Pedido explícito del usuario, con ejemplos reales
// que antes pasaban el formato viejo sin ser un celular de verdad.
function isValidPhoneLocal(value, countryCode) {
    if (countryCode !== '+593') return /^[0-9]{7,10}$/.test(value);
    return /^9\d{8}$/.test(value) && !/^(\d)\1{8}$/.test(value);
}

// Bug real reportado por el usuario (con captura: el campo dejaba escribir
// más de 20 dígitos aunque un celular ecuatoriano son 9): antes solo se
// invalidaba el paso al mandar el formulario, nada impedía seguir tecleando
// de más. Ahora se sanea en cada tecla — saca todo lo que no sea dígito, el
// 0 inicial si es Ecuador (ya lo reemplaza el código de país, sección de
// teléfono del alcance) y corta al máximo real de ese país.
const phoneMaxLength = computed(() => (form.country_code === '+593' ? 9 : 10));

function sanitizePhoneLocal() {
    let digits = form.phone_local.replace(/\D/g, '');
    if (form.country_code === '+593') digits = digits.replace(/^0+/, '');
    form.phone_local = digits.slice(0, phoneMaxLength.value);
}

// Si cambia de país con un número ya escrito (ej. de +593 a +51), lo vuelve
// a sanear contra el nuevo máximo — un número de 10 dígitos de otro país no
// debería quedar cortado en 9 solo porque antes tenía elegido Ecuador.
watch(() => form.country_code, sanitizePhoneLocal);

// Validación mínima del lado del cliente para habilitar "Siguiente" — la
// validación real (unicidad de correo/teléfono, reglas completas) sigue
// siendo del backend al mandar el formulario en el último paso.
const stepIsValid = computed(() => {
    switch (STEPS[currentStep.value]) {
        case 'account_type':
            return form.account_type !== '';
        case 'name':
            return form.first_name.trim().length > 0 && form.last_name.trim().length > 0;
        case 'email':
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email);
        case 'phone':
            return isValidPhoneLocal(form.phone_local, form.country_code);
        case 'password':
            return passwordChecks.value.length && passwordChecks.value.mixedCase && passwordChecks.value.number
                && form.password === form.password_confirmation;
        default:
            return true;
    }
});

// Mensaje de aliento según cuánto falta (pedido explícito del usuario: "que
// vaya diciendo ya casi terminamos").
const progressPercent = computed(() => Math.round(((currentStep.value + 1) / STEPS.length) * 100));
const encouragement = computed(() => {
    if (isLastStep.value) return '¡Ya casi terminamos! Un último paso.';
    if (currentStep.value === 0) return 'Empecemos por lo básico.';
    if (progressPercent.value >= 60) return 'Vas muy bien, seguí así.';
    return `Paso ${currentStep.value + 1} de ${STEPS.length}.`;
});

function goNext() {
    if (!stepIsValid.value) return;
    if (isLastStep.value) {
        submit();
        return;
    }
    currentStep.value++;
}

function goBack() {
    if (currentStep.value > 0) currentStep.value--;
}

// Si el backend rechaza algo (ej. correo ya registrado, solo se sabe al
// mandar el formulario), hay que llevar al usuario de vuelta al paso donde
// está ese campo — si no, el error queda invisible detrás de una pantalla
// que ya no muestra ese input.
const FIELD_STEP = {
    account_type: 0,
    name: 1, // compatibilidad con una respuesta de backend anterior
    first_name: 1,
    last_name: 1,
    email: 2,
    country_code: 3,
    phone_local: 3,
    password: 4,
    password_confirmation: 4,
};

const submit = () => {
    form.post(route('register'), {
        onError: (errors) => {
            const erroredFields = Object.keys(errors);
            const steps = erroredFields.map((field) => FIELD_STEP[field] ?? STEPS.length - 1);
            if (steps.length) currentStep.value = Math.min(...steps);
        },
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Crear cuenta" />

        <!-- Progreso: barra + mensaje de aliento (pedido explícito del usuario). -->
        <div class="mb-6">
            <div class="flex items-center justify-between text-xs text-arka-text-muted mb-1.5">
                <span>Paso {{ currentStep + 1 }} de {{ STEPS.length }}</span>
                <span>{{ progressPercent }}%</span>
            </div>
            <div class="h-1.5 w-full rounded-full bg-arka-text-muted/15 overflow-hidden">
                <div
                    class="h-full bg-arka-primary transition-all duration-300"
                    :style="{ width: `${progressPercent}%` }"
                />
            </div>
            <p class="mt-2 text-sm text-arka-primary-bright font-medium">{{ encouragement }}</p>
        </div>

        <form @submit.prevent="goNext">
            <!-- Paso 1: tipo de cuenta (pedido explícito del usuario: elegir esto
                 primero, antes que cualquier otro dato). -->
            <div v-if="STEPS[currentStep] === 'account_type'">
                <InputLabel value="¿Qué tipo de cuenta necesita?" />
                <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <button
                        type="button"
                        class="p-4 rounded-arka border-2 text-start transition"
                        :class="form.account_type === 'cliente' ? 'border-arka-primary bg-arka-primary/10' : 'border-arka-text-muted/20 hover:border-arka-text-muted/40'"
                        @click="form.account_type = 'cliente'"
                    >
                        <svg class="h-7 w-7 text-arka-primary mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="8" r="3.5" stroke-linecap="round" stroke-linejoin="round" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 20a7.5 7.5 0 0 1 15 0" />
                        </svg>
                        <p class="font-medium text-arka-text">Pasajero</p>
                        <p class="text-xs text-arka-text-muted mt-0.5">Armo mi flota de conductores de confianza y pido carreras.</p>
                    </button>

                    <button
                        type="button"
                        class="p-4 rounded-arka border-2 text-start transition"
                        :class="form.account_type === 'conductor' ? 'border-arka-primary bg-arka-primary/10' : 'border-arka-text-muted/20 hover:border-arka-text-muted/40'"
                        @click="form.account_type = 'conductor'"
                    >
                        <svg class="h-7 w-7 text-arka-primary mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l2.5-6.5A2 2 0 0 1 8.35 8.2h7.3a2 2 0 0 1 1.85 1.3L20 16" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16h16v2.5a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1V17H7v1.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V16Z" />
                        </svg>
                        <p class="font-medium text-arka-text">Conductor</p>
                        <p class="text-xs text-arka-text-muted mt-0.5">Manejo mi propio vehículo y recibo carreras de mis clientes.</p>
                    </button>

                    <button
                        type="button"
                        class="p-4 rounded-arka border-2 text-start transition"
                        :class="form.account_type === 'cooperativa' ? 'border-arka-primary bg-arka-primary/10' : 'border-arka-text-muted/20 hover:border-arka-text-muted/40'"
                        @click="form.account_type = 'cooperativa'"
                    >
                        <svg class="h-7 w-7 text-arka-primary mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M5 21V8l7-4 7 4v13M9 12h2m2 0h2m-6 4h2m2 0h2" />
                        </svg>
                        <p class="font-medium text-arka-text">Cooperativa</p>
                        <p class="text-xs text-arka-text-muted mt-0.5">Registro una organización de transporte y administro sus conductores.</p>
                    </button>
                </div>
                <!-- Transparencia (pedido explícito del usuario: pedir la
                     ubicación al registrarse) — el navegador va a mostrar su
                     propio permiso nativo de todas formas; esto solo explica
                     para qué, sin ocupar espacio de por sí (pedido explícito
                     del usuario: "ocultá esto o dejalo como más
                     información") — colapsado por defecto. -->
                <details class="mt-3 text-xs text-arka-text-muted">
                    <summary class="cursor-pointer select-none hover:text-arka-text">📍 ¿Por qué nos pide la ubicación?</summary>
                    <p class="mt-1">
                        Es posible que el navegador le pida acceso a su ubicación — nos ayuda a completar su ciudad
                        automáticamente. Si prefiere no compartirla, puede seguir igual.
                    </p>
                </details>
                <InputError class="mt-2" :message="form.errors.account_type" />
            </div>

            <!-- Paso 2: nombres separados para evitar que el sistema tenga
                 que adivinar cuál parte corresponde al apellido. En la base
                 se conserva `users.name` como nombre completo para mantener
                 compatibilidad con todos los perfiles actuales. -->
            <div v-else-if="STEPS[currentStep] === 'name'">
                <p class="mb-3 text-sm font-medium text-arka-text">¿Cómo se llama?</p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel for="first_name" value="Nombre" />
                        <TextInput
                            id="first_name"
                            v-model="form.first_name"
                            type="text"
                            class="mt-1 block w-full"
                            required
                            autofocus
                            autocomplete="given-name"
                        />
                        <InputError class="mt-2" :message="form.errors.first_name" />
                    </div>
                    <div>
                        <InputLabel for="last_name" value="Apellido" />
                        <TextInput
                            id="last_name"
                            v-model="form.last_name"
                            type="text"
                            class="mt-1 block w-full"
                            required
                            autocomplete="family-name"
                            @keydown.enter.prevent="goNext"
                        />
                        <InputError class="mt-2" :message="form.errors.last_name" />
                    </div>
                </div>
                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <!-- Paso 3: correo -->
            <div v-else-if="STEPS[currentStep] === 'email'">
                <InputLabel for="email" value="¿Cuál es su correo electrónico?" />
                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                    @keydown.enter.prevent="goNext"
                />
                <InputError class="mt-2" :message="form.errors.email" />

                <!-- Pedido explícito del usuario ("la gente se pierde"): si
                     ese correo ya tiene cuenta, ofrecer iniciar sesión en vez
                     de dejarlo sin salida. -->
                <p v-if="showsAccountExistsError" class="mt-2 text-sm">
                    <Link :href="route('login')" class="text-arka-primary hover:text-arka-primary-bright font-medium">
                        Iniciar sesión →
                    </Link>
                </p>
            </div>

            <!-- Paso 4: teléfono -->
            <div v-else-if="STEPS[currentStep] === 'phone'">
                <!-- El teléfono es obligatorio: es lo que permite que otros usuarios
                     te encuentren para invitarte a su flota (sección 3.2 del alcance).
                     Se verifica por WhatsApp después de crear la cuenta (consideración
                     de seguridad agregada al alcance), por eso pedimos el código de
                     país por separado — hace falta el número completo en formato
                     internacional para poder mandar el mensaje. -->
                <InputLabel for="phone_local" value="¿Cuál es su número de teléfono?" />

                <!-- Bug real reportado por el usuario (con capturas): en móvil, el
                     selector de país con el nombre completo le quitaba casi todo
                     el ancho al campo del número, que quedaba aplastado en una
                     cajita minúscula — shrink-0 + w-28 lo mantiene angosto y
                     fijo, min-w-0 en el input deja que sí se achique el select
                     (no el número) si hiciera falta. -->
                <div class="mt-1 flex gap-2">
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
                        required
                        autofocus
                        autocomplete="tel-national"
                        placeholder="991234567"
                        :maxlength="phoneMaxLength"
                        @input="sanitizePhoneLocal"
                        @keydown.enter.prevent="goNext"
                    />
                </div>
                <!-- Pedido explícito del usuario: validar que sea un celular
                     ecuatoriano real (9 dígitos, empieza en 9) y no cualquier
                     cadena — ver App\Rules\ValidPhoneNumberLocal (backend) e
                     isValidPhoneLocal() acá arriba (mismo criterio, en vivo). -->
                <p class="mt-1 text-xs text-arka-text-muted">
                    <template v-if="form.country_code === '+593'">9 dígitos, empieza en 9 — sin el 0 inicial ni espacios.</template>
                    <template v-else>Sin el 0 inicial ni espacios — solo los dígitos.</template>
                </p>

                <InputError class="mt-2" :message="form.errors.country_code" />
                <InputError class="mt-1" :message="form.errors.phone_local" />

                <!-- Pedido explícito del usuario ("la gente se pierde"): si
                     ese teléfono ya tiene cuenta, ofrecer iniciar sesión en
                     vez de dejarlo sin salida. -->
                <p v-if="showsAccountExistsError" class="mt-2 text-sm">
                    <Link :href="route('login')" class="text-arka-primary hover:text-arka-primary-bright font-medium">
                        Iniciar sesión →
                    </Link>
                </p>
            </div>

            <!-- Paso 5: contraseña -->
            <div v-else-if="STEPS[currentStep] === 'password'">
                <InputLabel for="password" value="Elegí una contraseña" />

                <div class="relative mt-1">
                    <TextInput
                        id="password"
                        :type="showPassword ? 'text' : 'password'"
                        class="block w-full pr-10"
                        v-model="form.password"
                        required
                        autofocus
                        autocomplete="new-password"
                    />
                    <button
                        type="button"
                        class="absolute inset-y-0 right-0 flex items-center px-3 text-arka-text-muted hover:text-arka-text focus:outline-none"
                        :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                        tabindex="-1"
                        @click="showPassword = !showPassword"
                    >
                        <svg v-if="showPassword" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.58 10.58a2 2 0 0 0 2.83 2.83M9.88 4.24A9.53 9.53 0 0 1 12 4c5 0 9 4 10 8-.32 1.13-.88 2.24-1.62 3.24M6.53 6.53C4.6 7.83 3.15 9.71 2 12c1 4 5 8 10 8 1.35 0 2.63-.28 3.78-.79" />
                        </svg>
                        <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2 12s4-8 10-8 10 8 10 8-4 8-10 8-10-8-10-8Z" />
                            <circle cx="12" cy="12" r="3" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                </div>

                <!-- Feedback en vivo (más intuitivo que un solo mensaje de error
                     genérico recién al mandar el formulario). -->
                <ul v-if="form.password.length" class="mt-2 space-y-1 text-xs">
                    <li :class="passwordChecks.length ? 'text-arka-primary-bright' : 'text-arka-text-muted'">
                        {{ passwordChecks.length ? '✓' : '·' }} Al menos 8 caracteres
                    </li>
                    <li :class="passwordChecks.mixedCase ? 'text-arka-primary-bright' : 'text-arka-text-muted'">
                        {{ passwordChecks.mixedCase ? '✓' : '·' }} Mayúsculas y minúsculas
                    </li>
                    <li :class="passwordChecks.number ? 'text-arka-primary-bright' : 'text-arka-text-muted'">
                        {{ passwordChecks.number ? '✓' : '·' }} Al menos un número
                    </li>
                </ul>

                <InputError class="mt-2" :message="form.errors.password" />

                <div class="mt-4">
                    <InputLabel for="password_confirmation" value="Confirmá la contraseña" />
                    <TextInput
                        id="password_confirmation"
                        :type="showPassword ? 'text' : 'password'"
                        class="mt-1 block w-full"
                        v-model="form.password_confirmation"
                        required
                        autocomplete="new-password"
                        @keydown.enter.prevent="goNext"
                    />
                    <InputError class="mt-2" :message="form.errors.password_confirmation" />
                </div>
            </div>

            <div class="flex items-center justify-between mt-6">
                <SecondaryButton v-if="currentStep > 0" type="button" @click="goBack">
                    &larr; Atrás
                </SecondaryButton>
                <Link
                    v-else
                    :href="route('login')"
                    class="underline text-sm text-arka-text-muted hover:text-arka-text rounded-arka focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-arka-card focus:ring-arka-primary"
                >
                    ¿Ya tiene cuenta?
                </Link>

                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="!stepIsValid || form.processing"
                >
                    {{ isLastStep ? 'Crear cuenta' : 'Siguiente →' }}
                </PrimaryButton>
            </div>
        </form>

        <template v-if="currentStep === 0 && $page.props.googleLoginEnabled">
            <div class="mt-6 flex items-center gap-3">
                <div class="flex-1 h-px bg-arka-text-muted/20" />
                <span class="text-xs text-arka-text-muted">o</span>
                <div class="flex-1 h-px bg-arka-text-muted/20" />
            </div>

            <a
                :href="route('auth.google.redirect')"
                class="mt-4 flex items-center justify-center gap-3 w-full py-2.5 rounded-arka bg-white text-gray-700 text-sm font-medium hover:bg-gray-100 transition"
            >
                <svg class="h-5 w-5" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M23.52 12.27c0-.85-.08-1.67-.22-2.45H12v4.64h6.47a5.53 5.53 0 0 1-2.4 3.63v3h3.87c2.27-2.09 3.58-5.17 3.58-8.82Z" />
                    <path fill="#34A853" d="M12 24c3.24 0 5.95-1.07 7.94-2.91l-3.87-3c-1.08.72-2.45 1.15-4.07 1.15-3.13 0-5.78-2.11-6.73-4.96H1.28v3.1A12 12 0 0 0 12 24Z" />
                    <path fill="#FBBC05" d="M5.27 14.28A7.2 7.2 0 0 1 4.89 12c0-.79.14-1.56.38-2.28v-3.1H1.28A12 12 0 0 0 0 12c0 1.94.46 3.77 1.28 5.38l3.99-3.1Z" />
                    <path fill="#EA4335" d="M12 4.77c1.76 0 3.35.61 4.6 1.8l3.44-3.44C17.94 1.19 15.24 0 12 0A12 12 0 0 0 1.28 6.62l3.99 3.1C6.22 6.88 8.87 4.77 12 4.77Z" />
                </svg>
                Continuar con Google
            </a>
        </template>
    </GuestLayout>
</template>
