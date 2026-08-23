<script setup>
import { computed, nextTick, ref } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { buildSessionRecoveryWhatsAppUrl } from '@/Utils/whatsapp';

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
});

const sessionRecoveryWhatsAppUrl = computed(() => buildSessionRecoveryWhatsAppUrl(props.whatsappBusinessNumber));

const form = useForm({
    login: props.loginHint ?? '',
    password: '',
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

        <div v-if="status" class="mb-4 font-medium text-sm text-arka-primary-bright">
            {{ status }}
        </div>

        <form @submit.prevent="submit">
            <div>
                <!-- Login múltiple (consideración agregada al alcance): un mismo
                     campo acepta teléfono, correo o el usuario autogenerado
                     (ej. jperez) — el backend resuelve cuál es. -->
                <InputLabel for="login" value="Teléfono, correo o usuario" />

                <TextInput
                    id="login"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.login"
                    required
                    autofocus
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.login" />

                <!-- Pedido explícito del usuario ("la gente se pierde"): si
                     no existe ninguna cuenta con ese dato, ofrecer crear una
                     en vez de dejarlo sin salida. Sin tipo=cliente forzado
                     (bug real reportado: no dejaba elegir conductor) — acá no
                     hay forma de saber qué quiere ser, así que Auth/Register.vue
                     arranca en el primer paso y se lo pregunta. -->
                <p v-if="showsAccountNotFoundError" class="mt-2 text-sm">
                    <Link :href="route('register')" class="text-arka-primary hover:text-arka-primary-bright font-medium">
                        Crear una cuenta →
                    </Link>
                </p>

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

            <div class="mt-4">
                <InputLabel for="password" value="Contraseña" />

                <div class="relative mt-1">
                    <TextInput
                        id="password"
                        ref="passwordInput"
                        :type="showPassword ? 'text' : 'password'"
                        class="block w-full pr-10"
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

            <div class="flex items-center justify-end mt-4">
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="underline text-sm text-arka-text-muted hover:text-arka-text rounded-arka focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-arka-card focus:ring-arka-primary"
                >
                    ¿Olvidó su contraseña?
                </Link>

                <PrimaryButton class="ms-4" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Iniciar sesión
                </PrimaryButton>
            </div>
        </form>

        <!-- Alternativa al usuario y contraseña de siempre — solo aparece si
             ya se completaron las credenciales de Google en .env. -->
        <template v-if="$page.props.googleLoginEnabled">
            <div class="mt-8 flex items-center gap-3">
                <div class="flex-1 h-px bg-arka-text-muted/20" />
                <span class="text-xs text-arka-text-muted">o</span>
                <div class="flex-1 h-px bg-arka-text-muted/20" />
            </div>

            <!-- Bug real reportado por el usuario ("se ve mal así chico"):
                 el botón tenía casi la mitad del alto/tamaño de texto del de
                 Auth/Register.vue, sin borde, con el ícono chico — quedaba
                 apretado. Mejorado a un tamaño legible con borde sutil
                 (mismo criterio visual que el botón oficial de Google), pero
                 sin llegar a w-full como en Register.vue — pedido explícito
                 del usuario: no tan grande que opaque a "Iniciar sesión". -->
            <div class="mt-5 flex justify-center">
                <a
                    :href="route('auth.google.redirect')"
                    class="flex items-center justify-center gap-2.5 px-5 py-2.5 rounded-arka bg-white border border-gray-300 text-gray-700 text-sm font-medium shadow-sm hover:bg-gray-50 hover:shadow transition"
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
    </GuestLayout>
</template>
