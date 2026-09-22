<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { App } from '@capacitor/app';
import { Browser } from '@capacitor/browser';
import { exchangeGoogleCode, getDeviceId, register } from '../services/auth';
import { API_BASE_URL } from '../apiBase';
import MobileBrand from '../components/MobileBrand.vue';
import MobileThemeToggle from '../components/MobileThemeToggle.vue';
import backgroundUrl from '../assets/img/fondo-login.jpg';

const router = useRouter();
const accountType = ref('cliente');
const firstName = ref('');
const lastName = ref('');
const email = ref('');
const countryCode = ref('+593');
const phoneLocal = ref('');
const password = ref('');
const passwordConfirmation = ref('');
const error = ref(null);
const loading = ref(false);
const googleLoading = ref(false);
const step = ref(1);
const showPassword = ref(false);
const countryCodes = ['+593', '+51', '+57', '+58', '+56', '+54'];
let appUrlListener = null;

const roleCopy = computed(() => ({
    cliente: { label: 'Pasajero', copy: 'Crea tu flota y solicita viajes.', icon: '♙' },
    conductor: { label: 'Conductor', copy: 'Gestiona tus clientes y recibe carreras.', icon: '▱' },
    cooperativa: { label: 'Cooperativa', copy: 'Administra conductores y unidades.', icon: '⌂' },
}[accountType.value]));

const stepCopy = computed(() => [
    'Elige cómo quieres usar Arka01.',
    'Completa tus datos de contacto.',
    'Protege tu cuenta para terminar.',
][step.value - 1]);

const canAdvance = computed(() => {
    if (step.value === 1) return Boolean(accountType.value);
    if (step.value === 2) return Boolean(firstName.value.trim() && lastName.value.trim() && email.value.trim() && phoneLocal.value.trim());
    return password.value.length >= 8 && password.value === passwordConfirmation.value;
});

function nextStep() {
    error.value = null;
    if (!canAdvance.value) return;
    step.value = Math.min(3, step.value + 1);
    document.querySelector('.register-screen')?.scrollTo({ top: 0, behavior: 'smooth' });
}

function previousStep() {
    error.value = null;
    step.value = Math.max(1, step.value - 1);
    document.querySelector('.register-screen')?.scrollTo({ top: 0, behavior: 'smooth' });
}

// Bug real reportado por el usuario (captura del botón "Continuar con
// Google" girando para siempre): cuando Google rechaza el authorization
// code (vencido o ya usado), el servidor vuelve a este mismo esquema pero
// SIN `code` (con `error` en su lugar, ver GoogleAuthController::
// failedGoogleAuth()) — antes esta función no hacía nada con esa vuelta,
// así que `googleLoading` quedaba en true para siempre. Ahora cualquier
// vuelta sin código apaga el spinner y muestra el motivo.
async function handleAppUrl(url) {
    if (!url?.startsWith('com.arka01.app://auth/google')) return;
    await Browser.close().catch(() => {});
    const params = new URL(url).searchParams;
    const code = params.get('code');
    if (!code) {
        googleLoading.value = false;
        error.value = params.get('error') || 'No se pudo iniciar sesión con Google. Inténtalo de nuevo.';
        return;
    }
    googleLoading.value = true;
    error.value = null;
    try {
        await exchangeGoogleCode(code);
        router.replace({ name: 'home' });
    } catch (e) {
        error.value = e.message;
    } finally {
        googleLoading.value = false;
    }
}

async function openGoogle() {
    googleLoading.value = true;
    error.value = null;
    try {
        const deviceId = await getDeviceId();
        await Browser.open({ url: `${API_BASE_URL}/auth/google/redirect?mobile=1&platform=android&account_type=${accountType.value}&device_id=${encodeURIComponent(deviceId)}` });
    } catch (_) {
        googleLoading.value = false;
        error.value = 'No se pudo abrir Google. Inténtalo de nuevo.';
    }
}

function openExternal(path) {
    Browser.open({ url: `${API_BASE_URL}${path}` });
}

async function submit() {
    if (step.value < 3) return nextStep();
    if (!canAdvance.value) return;
    loading.value = true;
    error.value = null;
    try {
        const createdUser = await register({
            account_type: accountType.value,
            first_name: firstName.value,
            last_name: lastName.value,
            email: email.value,
            country_code: countryCode.value,
            phone_local: phoneLocal.value,
            password: password.value,
            password_confirmation: passwordConfirmation.value,
        });
        router.replace({ name: createdUser.role === 'cooperativa' ? 'profile' : 'home' });
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    appUrlListener = await App.addListener('appUrlOpen', ({ url }) => handleAppUrl(url));
    const launch = await App.getLaunchUrl();
    if (launch?.url) handleAppUrl(launch.url);
});
onBeforeUnmount(() => appUrlListener?.remove());
</script>

<template>
    <main class="register-screen" :style="{ backgroundImage: `linear-gradient(rgba(4,35,27,.58),rgba(4,35,27,.8)),url('${backgroundUrl}')` }">
        <div class="theme-toggle"><MobileThemeToggle /></div>
        <section class="register-wrap">
            <div class="brand-line">
                <MobileBrand compact dark-background />
                <RouterLink :to="{ name: 'login' }">Iniciar sesión</RouterLink>
            </div>

            <form class="register-card mobile-card" @submit.prevent="submit">
                <div class="progress-copy">
                    <span>Paso {{ step }} de 3</span><strong>{{ Math.round(step / 3 * 100) }}%</strong>
                </div>
                <div class="progress-track"><i :style="{ width: `${step / 3 * 100}%` }"></i></div>

                <header>
                    <p class="mobile-eyebrow">Únete a Arka01</p>
                    <h1>Crear una cuenta</h1>
                    <p>{{ stepCopy }}</p>
                </header>

                <section v-if="step === 1" class="form-step">
                    <h2>¿Cómo quieres usar Arka01?</h2>
                    <button v-for="type in ['cliente','conductor','cooperativa']" :key="type" type="button" class="role-option" :class="{ active: accountType === type }" @click="accountType = type">
                        <span>{{ ({ cliente:'♙', conductor:'▱', cooperativa:'⌂' })[type] }}</span>
                        <p><strong>{{ ({ cliente:'Pasajero', conductor:'Conductor', cooperativa:'Cooperativa' })[type] }}</strong><small>{{ ({ cliente:'Crea tu flota y solicita viajes.', conductor:'Gestiona tus clientes y recibe carreras.', cooperativa:'Administra conductores y unidades.' })[type] }}</small></p>
                        <b>{{ accountType === type ? '✓' : '›' }}</b>
                    </button>
                    <button class="mobile-button" type="button" @click="nextStep">Continuar como {{ roleCopy.label }} →</button>
                    <div class="divider"><span></span><small>O</small><span></span></div>
                    <button class="google-button" type="button" :disabled="googleLoading" @click="openGoogle"><span v-if="googleLoading" class="mobile-spinner"></span><strong v-else>G</strong> Continuar con Google</button>
                    <p class="login-copy">¿Ya tienes cuenta? <RouterLink :to="{ name:'login' }">Iniciar sesión</RouterLink></p>
                </section>

                <section v-else-if="step === 2" class="form-step">
                    <div class="name-grid">
                        <label class="mobile-field"><span>Nombre</span><input v-model="firstName" class="mobile-input" autocomplete="given-name" placeholder="Tu nombre" required /></label>
                        <label class="mobile-field"><span>Apellido</span><input v-model="lastName" class="mobile-input" autocomplete="family-name" placeholder="Tu apellido" required /></label>
                    </div>
                    <label class="mobile-field"><span>Correo electrónico</span><input v-model="email" class="mobile-input" type="email" autocomplete="email" autocapitalize="none" placeholder="nombre@correo.com" required /></label>
                    <label class="mobile-field"><span>Número de teléfono</span><div class="phone"><select v-model="countryCode" class="mobile-input" aria-label="Código de país"><option v-for="code in countryCodes" :key="code" :value="code">{{ code }}</option></select><input v-model="phoneLocal" class="mobile-input" inputmode="numeric" autocomplete="tel-national" placeholder="991234567" required /></div></label>
                    <p class="field-hint">Usaremos este número para la seguridad de tu cuenta y avisos de viaje.</p>
                    <div class="form-actions"><button type="button" class="back-button" @click="previousStep">← Atrás</button><button class="mobile-button" type="submit" :disabled="!canAdvance">Continuar →</button></div>
                    <div class="divider"><span></span><small>O</small><span></span></div>
                    <button class="google-button" type="button" :disabled="googleLoading" @click="openGoogle"><span v-if="googleLoading" class="mobile-spinner"></span><strong v-else>G</strong> Continuar con Google</button>
                </section>

                <section v-else class="form-step">
                    <div class="account-summary"><span>{{ roleCopy.icon }}</span><p><small>Cuenta seleccionada</small><strong>{{ roleCopy.label }}</strong></p><button type="button" @click="step = 1">Cambiar</button></div>
                    <label class="mobile-field"><span>Contraseña</span><div class="password-field"><input v-model="password" class="mobile-input" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" placeholder="Mínimo 8 caracteres" required /><button type="button" @click="showPassword = !showPassword">{{ showPassword ? 'Ocultar' : 'Ver' }}</button></div></label>
                    <label class="mobile-field"><span>Confirmar contraseña</span><input v-model="passwordConfirmation" class="mobile-input" type="password" autocomplete="new-password" placeholder="Repite tu contraseña" required /></label>
                    <p class="password-rule" :class="{ ready: password.length >= 8 && password === passwordConfirmation }">✓ 8 caracteres y ambas contraseñas iguales</p>
                    <p v-if="error" class="mobile-alert">{{ error }}</p>
                    <div class="form-actions"><button type="button" class="back-button" @click="previousStep">← Atrás</button><button class="mobile-button" type="submit" :disabled="loading || !canAdvance"><span v-if="loading" class="mobile-spinner"></span>{{ loading ? 'Creando cuenta…' : 'Crear mi cuenta →' }}</button></div>
                    <div class="divider"><span></span><small>O</small><span></span></div>
                    <button class="google-button" type="button" :disabled="googleLoading" @click="openGoogle"><span v-if="googleLoading" class="mobile-spinner"></span><strong v-else>G</strong> Continuar con Google</button>
                    <p class="terms-copy">Al crear tu cuenta aceptas los <button type="button" @click="openExternal('/terminos')">Términos</button> y la <button type="button" @click="openExternal('/privacidad')">Política de privacidad</button>.</p>
                </section>
            </form>
        </section>
    </main>
</template>

<style scoped>
.register-screen{height:100dvh;min-height:0;padding:calc(.8rem + env(safe-area-inset-top)) 1rem calc(1.25rem + env(safe-area-inset-bottom));overflow-x:hidden;overflow-y:auto;overscroll-behavior-y:contain;-webkit-overflow-scrolling:touch;background-size:cover;background-position:center}.register-wrap{width:100%;max-width:480px;margin:0 auto}.theme-toggle{position:absolute;z-index:2;right:1rem;top:calc(.65rem + env(safe-area-inset-top))}.brand-line{min-height:3.2rem;padding-right:3.5rem;display:flex;align-items:center;justify-content:space-between}.brand-line a{color:#fff;font-size:.78rem;font-weight:800;text-decoration:none}
.register-card{margin-top:1.25rem;padding:1.2rem;display:grid;gap:1rem;background:#fff;color:#18231e}.progress-copy{display:flex;justify-content:space-between;color:#52625a;font-size:.7rem}.progress-copy strong{font-weight:700}.progress-track{height:.28rem;overflow:hidden;border-radius:999px;background:#e1e7e4}.progress-track i{display:block;height:100%;border-radius:inherit;background:#178b62;transition:width .2s}.register-card header{padding:.2rem 0 .35rem}.register-card header h1{margin:.3rem 0 .2rem;font-size:1.55rem;letter-spacing:-.035em}.register-card header>p:last-child{margin:0;color:#68756f;font-size:.82rem}.form-step{display:grid;gap:.85rem}.form-step h2{margin:0;font-size:.9rem}
.role-option{width:100%;min-height:4.4rem;padding:.65rem .75rem;display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:.75rem;border:1px solid #ccd7d1;border-radius:1rem;background:#fff;color:#25322c;text-align:left}.role-option>span{width:2.65rem;height:2.65rem;display:grid;place-items:center;border-radius:50%;background:#e8f8f1;color:#178b62;font-size:1.25rem}.role-option p{margin:0;display:grid;gap:.12rem}.role-option small{color:#68756f;font-size:.7rem;line-height:1.35}.role-option b{color:#587068}.role-option.active{border-color:#178b62;box-shadow:0 0 0 1px #178b62}.role-option.active b{width:1.35rem;height:1.35rem;display:grid;place-items:center;border-radius:50%;background:#178b62;color:#fff}
.name-grid{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}.phone{display:grid;grid-template-columns:6.4rem 1fr;gap:.5rem}.field-hint,.password-rule{margin:-.25rem 0 0;color:#68756f;font-size:.68rem;line-height:1.4}.password-rule.ready{color:#178b62}.password-field{position:relative}.password-field input{padding-right:4rem}.password-field button{position:absolute;right:.5rem;top:50%;transform:translateY(-50%);border:0;background:transparent;color:#178b62;font-size:.7rem;font-weight:800}.form-actions{display:grid;grid-template-columns:auto 1fr;gap:.55rem}.back-button{min-width:5.8rem;border:1px solid #ccd7d1;border-radius:.9rem;background:#fff;color:#25322c;font-weight:750}.divider{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:.7rem;color:#68756f}.divider span{height:1px;background:#d8dfdb}.google-button{min-height:3.1rem;display:flex;align-items:center;justify-content:center;gap:.65rem;border:1px solid #ccd5d0;border-radius:.85rem;background:#fff;color:#223029;font-weight:750}.google-button strong{color:#4285f4;font-size:1.2rem}.login-copy,.terms-copy{margin:0;text-align:center;color:#68756f;font-size:.7rem;line-height:1.5}.login-copy a,.terms-copy a{color:#178b62;font-weight:800;text-decoration:none}.register-card .mobile-field>span{color:#18231e}.register-card .mobile-input{border-color:#c7d2cc;background:#f7faf8;color:#18231e}.register-card .mobile-button{color:#fff}
.terms-copy button{border:0;padding:0;background:transparent;color:#178b62;font:inherit;font-weight:800;text-decoration:underline}
@media(max-width:360px){.name-grid{grid-template-columns:1fr}.register-card{padding:1rem}}
</style>
