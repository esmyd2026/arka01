<script setup>
import { computed, ref } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

// Misma lista que RegisteredUserController::COUNTRY_CODES — es una lista fija
// de indicativos telefónicos reales, no un catálogo de negocio que necesite
// pantalla de mantenimiento (a diferencia de planes, tarifas o zonas).
const countryCodes = [
    { code: '+593', label: '🇪🇨 +593 Ecuador' },
    { code: '+51', label: '🇵🇪 +51 Perú' },
    { code: '+57', label: '🇨🇴 +57 Colombia' },
    { code: '+58', label: '🇻🇪 +58 Venezuela' },
    { code: '+1', label: '🇺🇸 +1 EE.UU./Canadá' },
    { code: '+34', label: '🇪🇸 +34 España' },
];

const form = useForm({
    account_type: '',
    name: '',
    email: '',
    country_code: '+593',
    phone_local: '',
    password: '',
    password_confirmation: '',
});

// Registro guiado paso a paso (consideración agregada al alcance: el usuario
// pidió explícitamente que no sea un formulario largo de una sola vez, sino
// que vaya "registrando poco a poco" con feedback de progreso) — un dato por
// pantalla, empezando por el tipo de cuenta porque cambia a dónde va apenas
// termina (ver RegisteredUserController::store()).
const STEPS = ['account_type', 'name', 'email', 'phone', 'password'];
const currentStep = ref(0);
const isLastStep = computed(() => currentStep.value === STEPS.length - 1);

// Feedback en vivo de qué le falta a la contraseña (más intuitivo que
// enterarse recién al mandar el formulario) — mismas reglas que el backend
// (Rules\Password::defaults()->min(8)->mixedCase()->numbers()).
const passwordChecks = computed(() => ({
    length: form.password.length >= 8,
    mixedCase: /[a-z]/.test(form.password) && /[A-Z]/.test(form.password),
    number: /\d/.test(form.password),
}));

// Validación mínima del lado del cliente para habilitar "Siguiente" — la
// validación real (unicidad de correo/teléfono, reglas completas) sigue
// siendo del backend al mandar el formulario en el último paso.
const stepIsValid = computed(() => {
    switch (STEPS[currentStep.value]) {
        case 'account_type':
            return form.account_type !== '';
        case 'name':
            return form.name.trim().length > 0;
        case 'email':
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email);
        case 'phone':
            return /^[0-9]{7,10}$/.test(form.phone_local);
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
    name: 1,
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
                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
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
                </div>
                <InputError class="mt-2" :message="form.errors.account_type" />
            </div>

            <!-- Paso 2: nombre -->
            <div v-else-if="STEPS[currentStep] === 'name'">
                <InputLabel for="name" value="¿Cómo se llama?" />
                <TextInput
                    id="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                    @keydown.enter.prevent="goNext"
                />
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

                <div class="mt-1 flex gap-2">
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
                        required
                        autofocus
                        autocomplete="tel-national"
                        placeholder="991234567"
                        @keydown.enter.prevent="goNext"
                    />
                </div>
                <p class="mt-1 text-xs text-arka-text-muted">Sin el 0 inicial ni espacios — solo los dígitos.</p>

                <InputError class="mt-2" :message="form.errors.country_code" />
                <InputError class="mt-1" :message="form.errors.phone_local" />
            </div>

            <!-- Paso 5: contraseña -->
            <div v-else-if="STEPS[currentStep] === 'password'">
                <InputLabel for="password" value="Elegí una contraseña" />

                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autofocus
                    autocomplete="new-password"
                />

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
                        type="password"
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
