<script setup>
import { computed, nextTick, ref } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { buildResendCodeWhatsAppUrl, buildSessionRecoveryWhatsAppUrl } from '@/Utils/whatsapp';

const props = defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    // Bug reportado por el usuario: al volver de un login con Google
    // bloqueado por sesión única, no había forma de saber a qué cuenta
    // pedirle el código (el campo "login" del formulario nunca se llegó a
    // escribir, a diferencia del camino de contraseña) — ver
    // AuthenticatedSessionController::create() y GoogleAuthController.
    loginHint: {
        type: String,
        default: null,
    },
    // Pedido explícito del usuario: para armar el link de "escríbanos
    // primero" (ver buildSessionRecoveryWhatsAppUrl más abajo).
    whatsappBusinessNumber: {
        type: String,
        default: null,
    },
    referrerId: {
        type: String,
        default: null,
    },
});

const sessionRecoveryWhatsAppUrl = computed(() => buildSessionRecoveryWhatsAppUrl(props.whatsappBusinessNumber));
// Pedido explícito del usuario: si el código por WhatsApp no llega,
// escribirle al bot con "No me llegó el código" abre la ventana de 24h y se
// lo manda como texto libre — ver
// App\Services\Chatbot\IntentActionHandlers\ResendVerificationCodeHandler.
const resendCodeWhatsAppUrl = computed(() => buildResendCodeWhatsAppUrl(props.whatsappBusinessNumber));

// Encuesta corta (pedido explícito del usuario) — mismo criterio que el
// banner del Home, ver Survey/Show.vue.
const surveyDone = ref(typeof window !== 'undefined' && window.localStorage.getItem('arka01_survey_done') === '1');

const form = useForm({
    login: props.loginHint ?? '',
    password: '',
    ref: props.referrerId,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};

// Sesión única por cuenta (pedido explícito del usuario, caso real: "no sé
// dónde dejé loguiada mi sesión y no me deja entrar desde otro navegador") —
// el mensaje exacto lo tira App\Exceptions\ActiveSessionExistsException; si
// aparece ese, se ofrece este atajo en vez de que la única salida sea
// esperar a que esa sesión venza sola (hasta 2 horas, y ni eso si la pestaña
// vieja sigue "viva" en segundo plano haciendo pings).
// Cubre los dos caminos que pueden traer este mismo error: el formulario de
// contraseña (form.errors.login) y la vuelta del login con Google, que no
// tiene formulario — llega como 'status' (ver GoogleAuthController).
const showsSessionBlockedError = computed(
    () =>
        (form.errors.login ?? '').startsWith('Ya tiene una sesión activa') ||
        (props.status ?? '').startsWith('Ya tiene una sesión activa')
);

// Pedido explícito del usuario ("la gente se pierde" entre iniciar sesión y
// crear cuenta): si el dato no corresponde a ninguna cuenta (mensaje puntual
// de LoginRequest::authenticate()), se ofrece el atajo a crear una en vez de
// dejar a la persona en un callejón sin salida.
const showsAccountNotFoundError = computed(() => (form.errors.login ?? '').startsWith('No encontramos una cuenta con ese dato'));

// Pedido explícito del usuario: no basta con decir "cree una cuenta" — el
// atajo tiene que ser DINÁMICO, sin hacerlo escribir el teléfono de nuevo
// (mismo criterio que ya usa Auth/Register.vue al revés: cuando detecta que
// el teléfono/correo ya tiene cuenta, ofrece "Iniciar sesión" de una). Si lo
// que escribió tiene forma de teléfono, Auth/Register.vue ya sabe leer
// "telefono" de la URL y arrancar con eso precargado.
const registerLink = computed(() => route('register', {
    ref: props.referrerId,
    ...(looksLikePhone.value ? { telefono: form.login.trim() } : {}),
}));

// Login por código de WhatsApp (pedido explícito del usuario: "que
// simplemente sea con el numero de telefono... y que cuando inicien
// sesion inicien con el numero tambien") — alternativa a la contraseña,
// misma forma que el widget de liberar sesión de acá abajo. Solo aparece si
// lo que escribió en "login" tiene forma de teléfono: no tendría sentido
// ofrecer un código por WhatsApp para un usuario o correo.
const looksLikePhone = computed(() => /^\+?\d{7,15}$/.test(form.login.trim().replace(/[\s-]/g, '')));
const phoneLoginStep = ref('idle'); // 'idle' | 'code-sent'
const phoneLoginCode = ref('');
const phoneLoginStatus = ref('');
const phoneLoginError = ref('');
const phoneLoginSending = ref(false);

async function requestPhoneLoginCode() {
    phoneLoginSending.value = true;
    phoneLoginError.value = '';

    try {
        const { data } = await window.axios.post(route('phone-login.request'), { login: form.login });
        phoneLoginStatus.value = data.message;
        phoneLoginStep.value = 'code-sent';
    } catch {
        phoneLoginError.value = 'No pudimos enviar el código — intente de nuevo en un rato.';
    } finally {
        phoneLoginSending.value = false;
    }
}

function confirmPhoneLogin() {
    router.post(route('phone-login.confirm'), { login: form.login, code: phoneLoginCode.value }, {
        onError: (errors) => {
            phoneLoginError.value = errors.code ?? 'Ese código no es válido o ya venció.';
        },
    });
}

// Último recurso (pedido explícito del usuario, caso real: "cuando pido el
// código no llega... la experiencia es muy mala") — escribirle a soporte sin
// tener que entrar primero, desde acá mismo. Aparece junto al widget de
// código, para cuando ni WhatsApp ni el respaldo por correo funcionaron.
const supportFormOpen = ref(false);
const supportMessage = ref('');
const supportStatus = ref('');
const supportError = ref('');
const supportSending = ref(false);
const supportSent = ref(false);
// Pedido explícito del usuario ("no debemos dejar sin opción al cliente si
// no consiguió una cuenta"): si soporte tampoco encontró ninguna cuenta con
// ese dato, no puede ser un callejón sin salida — se distingue de un error
// de verdad (ej. falla de red) para ofrecer el atajo dinámico a crear cuenta
// en vez de solo un texto en rojo.
const supportAccountNotFound = ref(false);

function openSupportForm() {
    supportFormOpen.value = true;
    supportStatus.value = '';
    supportError.value = '';
    supportAccountNotFound.value = false;
}

async function sendSupportMessage() {
    supportSending.value = true;
    supportError.value = '';
    supportAccountNotFound.value = false;

    try {
        const { data } = await window.axios.post(route('login-support.store'), {
            login: form.login,
            message: supportMessage.value,
        });

        if (data.ok) {
            supportStatus.value = data.message;
            supportSent.value = true;
        } else {
            supportError.value = data.message;
            supportAccountNotFound.value = true;
        }
    } catch {
        supportError.value = 'No pudimos enviar su mensaje — intente de nuevo en un rato.';
    } finally {
        supportSending.value = false;
    }
}

const takeoverStep = ref('idle'); // 'idle' | 'code-sent'
const takeoverCode = ref('');
const takeoverStatus = ref('');
const takeoverError = ref('');
const takeoverSending = ref(false);

async function requestTakeoverCode() {
    takeoverSending.value = true;
    takeoverError.value = '';

    try {
        const { data } = await window.axios.post(route('session-takeover.request'), { login: form.login });
        takeoverStatus.value = data.message;
        takeoverStep.value = 'code-sent';
    } catch {
        takeoverError.value = 'No pudimos enviar el código — intente de nuevo en un rato.';
    } finally {
        takeoverSending.value = false;
    }
}

const passwordInput = ref(null);
const showPassword = ref(false);

async function confirmTakeover() {
    takeoverSending.value = true;
    takeoverError.value = '';

    try {
        const { data } = await window.axios.post(route('session-takeover.confirm'), {
            login: form.login,
            code: takeoverCode.value,
        });
        takeoverStatus.value = data.message;
        takeoverStep.value = 'done';
        form.clearErrors('login');
        // Pedido explícito del usuario ("no hubo feedback luego que cerró la
        // sesión con éxito"): además del mensaje, se lleva el foco directo a
        // la contraseña — queda claro que ya puede seguir y no hay que
        // buscar qué tocar después.
        await nextTick();
        passwordInput.value?.focus();
    } catch (error) {
        takeoverError.value = error.response?.data?.errors?.code?.[0] ?? 'Ese código no es válido o ya venció.';
    } finally {
        takeoverSending.value = false;
    }
}
</script>

<template>
    <GuestLayout>
        <Head title="Iniciar sesión" />

        <div class="mb-6 flex items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-arka-primary/25 bg-arka-primary/10 text-arka-primary">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 4.5h4A1.5 1.5 0 0 1 19.5 6v12a1.5 1.5 0 0 1-1.5 1.5h-4M11 8.5 15 12l-4 3.5M15 12H4" />
                </svg>
            </span>
            <div>
                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-arka-primary">Acceso seguro</p>
                <h1 class="mt-0.5 text-xl font-bold text-arka-text">Bienvenido de nuevo</h1>
                <p class="mt-0.5 text-xs text-arka-text-muted">Ingrese a su cuenta de Arka01.</p>
            </div>
        </div>

        <div v-if="status" class="mb-4 rounded-arka border border-arka-primary/30 bg-arka-primary/10 px-3 py-2.5 text-sm font-medium text-arka-primary-bright">
            {{ status }}
        </div>

        <form @submit.prevent="submit">
            <div>
                <!-- Login múltiple (consideración agregada al alcance): un mismo
                     campo acepta teléfono, correo o el usuario autogenerado
                     (ej. jperez) — el backend resuelve cuál es. -->
                <InputLabel for="login" value="Teléfono, correo o usuario" />

                <div class="relative mt-1.5">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center ps-3.5 text-arka-text-muted">
                        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <circle cx="12" cy="8" r="3.5" />
                            <path stroke-linecap="round" d="M5 20a7 7 0 0 1 14 0" />
                        </svg>
                    </span>
                    <TextInput
                        id="login"
                        type="text"
                        class="block min-h-12 w-full border-arka-primary/30 bg-arka-card ps-10 text-sm hover:border-arka-primary/55"
                        v-model="form.login"
                        required
                        autofocus
                        autocomplete="username"
                        placeholder="Ej. usuario, correo o teléfono"
                    />
                </div>

                <InputError class="mt-2" :message="form.errors.login" />

                <!-- Pedido explícito del usuario ("la gente se pierde"): si
                     no existe ninguna cuenta con ese dato, ofrecer crear una
                     en vez de dejarlo sin salida. Sin tipo=cliente forzado
                     (bug real reportado: no dejaba elegir conductor) — acá no
                     hay forma de saber qué quiere ser, así que Auth/Register.vue
                     arranca en el primer paso y se lo pregunta. -->
                <p v-if="showsAccountNotFoundError" class="mt-2 text-sm">
                    <Link :href="registerLink" class="text-arka-primary hover:text-arka-primary-bright font-medium">
                        Crear una cuenta →
                    </Link>
                </p>

                <!-- Login por código de WhatsApp (pedido explícito del
                     usuario): alternativa a la contraseña para cualquier
                     cuenta con teléfono verificado — solo tiene sentido
                     ofrecerlo si lo que escribió tiene forma de teléfono. -->
                <p v-if="looksLikePhone && phoneLoginStep === 'idle' && !showsSessionBlockedError" class="mt-2 text-sm">
                    <button
                        type="button"
                        class="text-arka-primary hover:text-arka-primary-bright font-medium underline"
                        :disabled="phoneLoginSending"
                        @click="requestPhoneLoginCode"
                    >
                        {{ phoneLoginSending ? 'Enviando…' : 'Prefiero un código por WhatsApp' }}
                    </button>
                </p>

                <div v-if="phoneLoginStep === 'code-sent'" class="mt-3 p-3 rounded-arka bg-arka-base/60 text-sm">
                    <p class="text-arka-primary-bright">{{ phoneLoginStatus }}</p>
                    <div class="mt-2 flex gap-2">
                        <TextInput
                            type="text"
                            inputmode="numeric"
                            maxlength="6"
                            class="block w-32"
                            v-model="phoneLoginCode"
                            placeholder="Código"
                            @keydown.enter.prevent="confirmPhoneLogin"
                        />
                        <SecondaryButton :disabled="phoneLoginCode.length !== 6" @click="confirmPhoneLogin">
                            Confirmar
                        </SecondaryButton>
                    </div>
                    <p v-if="phoneLoginError" class="mt-2 text-xs text-arka-danger">{{ phoneLoginError }}</p>

                    <!-- Pedido explícito del usuario: "usariamos las dos
                         manera, principalmente la de la plantilla, pero si
                         no funciona... que lo mande al whatsapp al bot con
                         ese mensaje 'no me llego el codigo'" — escribirle al
                         bot abre la ventana de 24h y el código se manda como
                         texto libre, sin depender de la plantilla. -->
                    <a
                        v-if="resendCodeWhatsAppUrl"
                        :href="resendCodeWhatsAppUrl"
                        target="_blank"
                        rel="noopener"
                        class="mt-3 flex items-center justify-center gap-2 rounded-arka border border-arka-primary/30 bg-arka-primary/10 px-3 py-2 text-xs font-medium text-arka-primary-bright hover:bg-arka-primary/15"
                    >
                        Escribirle al WhatsApp de Arka01: "No me llegó el código" →
                    </a>

                    <!-- Último recurso (pedido explícito del usuario, caso
                         real: "cuando pido el código no llega"): escribirle a
                         soporte sin tener que entrar primero. -->
                    <template v-if="!supportSent">
                        <button
                            v-if="!supportFormOpen"
                            type="button"
                            class="mt-3 block text-xs text-arka-text-muted hover:text-arka-text underline"
                            @click="openSupportForm"
                        >
                            ¿Tampoco le llegó por correo? Escribirle a soporte
                        </button>

                        <div v-else class="mt-3">
                            <TextInput
                                type="text"
                                class="block w-full"
                                v-model="supportMessage"
                                placeholder="Cuéntenos qué le pasó — un admin lo va a revisar"
                                maxlength="1000"
                            />
                            <div class="mt-2 flex items-center gap-2">
                                <SecondaryButton :disabled="supportSending || !supportMessage.trim()" @click="sendSupportMessage">
                                    {{ supportSending ? 'Enviando…' : 'Enviar a soporte' }}
                                </SecondaryButton>
                            </div>
                            <p v-if="supportError" class="mt-2 text-xs text-arka-danger">{{ supportError }}</p>

                            <!-- Pedido explícito del usuario: "no debemos
                                 dejar sin opción al cliente si no consiguió
                                 una cuenta" — si de verdad no existe ninguna
                                 cuenta con este dato, el atajo dinámico a
                                 crear una (con el teléfono ya precargado, ver
                                 registerLink) en vez de un callejón sin salida. -->
                            <p v-if="supportAccountNotFound" class="mt-2 text-sm">
                                <Link :href="registerLink" class="text-arka-primary hover:text-arka-primary-bright font-medium">
                                    Crear una cuenta →
                                </Link>
                            </p>
                        </div>
                    </template>
                    <p v-else class="mt-3 text-xs text-arka-primary-bright">{{ supportStatus }}</p>
                </div>

                <!-- Sesión única por cuenta (pedido explícito del usuario,
                     caso real: "no sé dónde dejé loguiada mi sesión") — atajo
                     para cerrar esa otra sesión sin esperar a que venza sola. -->
                <div v-if="showsSessionBlockedError" class="mt-3 p-3 rounded-arka bg-arka-base/60 text-sm">
                    <template v-if="takeoverStep === 'idle'">
                        <p class="text-arka-text-muted">
                            ¿Es usted, desde otro dispositivo? Podemos enviarle un código para cerrar esa sesión.
                        </p>
                        <!-- Pedido explícito del usuario: para que el código llegue por
                             WhatsApp (más rápido que el correo), primero hay que
                             escribirle al número oficial — abre la ventana de 24h, y el
                             "bot" confirma que ya se puede pedir el código de una
                             (ver WhatsAppWebhookController::receive()). Paso opcional:
                             si ya tenía la ventana abierta, puede saltar directo a
                             "Pedir código". -->
                        <a
                            v-if="sessionRecoveryWhatsAppUrl"
                            :href="sessionRecoveryWhatsAppUrl"
                            target="_blank"
                            rel="noopener"
                            class="mt-2 block text-xs text-arka-primary hover:text-arka-primary-bright underline"
                        >
                            1. Escríbanos por WhatsApp primero →
                        </a>
                        <SecondaryButton class="mt-2" :disabled="takeoverSending" @click="requestTakeoverCode">
                            {{ takeoverSending ? 'Enviando…' : (sessionRecoveryWhatsAppUrl ? '2. Pedir código' : 'Pedir código') }}
                        </SecondaryButton>
                    </template>

                    <template v-else-if="takeoverStep === 'code-sent'">
                        <p class="text-arka-primary-bright">{{ takeoverStatus }}</p>
                        <p class="mt-1 text-xs text-arka-text-muted">
                            Por WhatsApp si tenía la ventana de 24h abierta, si no por correo.
                        </p>
                        <div class="mt-2 flex gap-2">
                            <TextInput
                                type="text"
                                inputmode="numeric"
                                maxlength="6"
                                class="block w-32"
                                v-model="takeoverCode"
                                placeholder="Código"
                            />
                            <SecondaryButton :disabled="takeoverSending || takeoverCode.length !== 6" @click="confirmTakeover">
                                Confirmar
                            </SecondaryButton>
                        </div>
                    </template>

                    <!-- Pedido explícito del usuario: feedback claro de que
                         ya se cerró la otra sesión (antes esto pasaba
                         desapercibido) — ícono + mensaje destacado, no un
                         renglón de texto más. -->
                    <div v-else class="flex items-start gap-2">
                        <span class="text-arka-primary-bright shrink-0">✓</span>
                        <p class="text-arka-primary-bright">
                            {{ takeoverStatus }} Ingrese su contraseña y continúe.
                        </p>
                    </div>

                    <p v-if="takeoverError" class="mt-2 text-xs text-arka-danger">{{ takeoverError }}</p>
                </div>
            </div>

            <div v-if="phoneLoginStep !== 'code-sent'" class="mt-5">
                <InputLabel for="password" value="Contraseña" />

                <div class="relative mt-1.5">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center ps-3.5 text-arka-text-muted">
                        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <rect x="5" y="10" width="14" height="10" rx="2" />
                            <path stroke-linecap="round" d="M8 10V7a4 4 0 0 1 8 0v3" />
                        </svg>
                    </span>
                    <TextInput
                        id="password"
                        ref="passwordInput"
                        :type="showPassword ? 'text' : 'password'"
                        class="block min-h-12 w-full border-arka-primary/30 bg-arka-card ps-10 pe-11 text-sm hover:border-arka-primary/55"
                        v-model="form.password"
                        required
                        autocomplete="current-password"
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

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div v-if="phoneLoginStep !== 'code-sent'" class="mt-3 flex justify-end">
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="rounded text-xs font-medium text-arka-primary hover:text-arka-primary-bright focus:outline-none focus:ring-2 focus:ring-arka-primary"
                >
                    ¿Olvidó su contraseña?
                </Link>
            </div>

            <PrimaryButton
                v-if="phoneLoginStep !== 'code-sent'"
                class="mt-5 min-h-12 w-full justify-center text-sm shadow-lg shadow-arka-primary/15"
                :class="{ 'opacity-50': form.processing }"
                :disabled="form.processing"
            >
                {{ form.processing ? 'Ingresando…' : 'Iniciar sesión' }}
                <svg v-if="!form.processing" class="ms-2 h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" /></svg>
            </PrimaryButton>
        </form>

        <!-- Alternativa al usuario y contraseña de siempre — solo aparece si
             ya se completaron las credenciales de Google en .env. -->
        <template v-if="$page.props.googleLoginEnabled">
            <div class="mt-7 flex items-center gap-3">
                <div class="flex-1 h-px bg-arka-text-muted/20" />
                <span class="text-[10px] font-semibold uppercase tracking-[0.14em] text-arka-text-muted">O continúe con</span>
                <div class="flex-1 h-px bg-arka-text-muted/20" />
            </div>

            <!-- Bug real reportado por el usuario ("se ve mal así chico"):
                 el botón tenía casi la mitad del alto/tamaño de texto del de
                 Auth/Register.vue, sin borde, con el ícono chico — quedaba
                 apretado. Mejorado a un tamaño legible con borde sutil
                 (mismo criterio visual que el botón oficial de Google), pero
                 sin llegar a w-full como en Register.vue — pedido explícito
                 del usuario: no tan grande que opaque a "Iniciar sesión". -->
            <div class="mt-4">
                <a
                    :href="route('auth.google.redirect')"
                    class="flex min-h-12 w-full items-center justify-center gap-2.5 rounded-arka border border-gray-300 bg-white px-5 py-3 text-sm font-semibold text-gray-700 shadow-sm transition hover:-translate-y-0.5 hover:bg-gray-50 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-arka-primary"
                >
                    <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24">
                        <path fill="#4285F4" d="M23.52 12.27c0-.85-.08-1.67-.22-2.45H12v4.64h6.47a5.53 5.53 0 0 1-2.4 3.63v3h3.87c2.27-2.09 3.58-5.17 3.58-8.82Z" />
                        <path fill="#34A853" d="M12 24c3.24 0 5.95-1.07 7.94-2.91l-3.87-3c-1.08.72-2.45 1.15-4.07 1.15-3.13 0-5.78-2.11-6.73-4.96H1.28v3.1A12 12 0 0 0 12 24Z" />
                        <path fill="#FBBC05" d="M5.27 14.28A7.2 7.2 0 0 1 4.89 12c0-.79.14-1.56.38-2.28v-3.1H1.28A12 12 0 0 0 0 12c0 1.94.46 3.77 1.28 5.38l3.99-3.1Z" />
                        <path fill="#EA4335" d="M12 4.77c1.76 0 3.35.61 4.6 1.8l3.44-3.44C17.94 1.19 15.24 0 12 0A12 12 0 0 0 1.28 6.62l3.99 3.1C6.22 6.88 8.87 4.77 12 4.77Z" />
                    </svg>
                    Continuar con Google
                </a>
            </div>
        </template>

        <!-- Encuesta corta de conductor/pasajero (pedido explícito del
             usuario: "no necesita tener usuario... debe estar en el home y
             en el login... al lado izquierdo") — mismo criterio de
             localStorage que el banner del Home para no insistir a quien ya
             respondió. -->
        <p v-if="!surveyDone" class="mt-5 border-t border-arka-border pt-4 text-center text-xs">
            <Link :href="route('survey.show')" class="text-arka-primary hover:text-arka-primary-bright font-medium">
                Cuentanos tu experiencia con Arka01 (2 min) →
            </Link>
        </p>
    </GuestLayout>
</template>
