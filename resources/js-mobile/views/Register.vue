<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { App } from '@capacitor/app';
import { Browser } from '@capacitor/browser';
import { exchangeGoogleCode, getDeviceId, register } from '../services/auth';
import { API_BASE_URL } from '../apiBase';
import MobileBrand from '../components/MobileBrand.vue';
import MobileThemeToggle from '../components/MobileThemeToggle.vue';
// Copia liviana (JPEG, redimensionada) solo para el bundle móvil: el
// original PNG del sitio web pesa ~625 KB y aquí se ve detrás de un
// degradado oscuro, así que la compresión agresiva no se nota.
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
const canContinue = computed(() => firstName.value.trim() && lastName.value.trim() && email.value.trim() && phoneLocal.value.trim());

// Mismos países que el registro web (RegisteredUserController::COUNTRY_CODES)
// — Sudamérica, el mercado real de la app.
const countryCodes = ['+593', '+51', '+57', '+58', '+56', '+54'];
let appUrlListener = null;

async function handleAppUrl(url) {
    if (!url?.startsWith('com.arka01.app://auth/google')) return;
    const code = new URL(url).searchParams.get('code');
    if (!code) return;
    googleLoading.value = true;
    try { await Browser.close().catch(() => {}); await exchangeGoogleCode(code); router.replace({ name: 'home' }); }
    catch (e) { error.value = e.message; }
    finally { googleLoading.value = false; }
}

async function openGoogle() {
    googleLoading.value = true; error.value = null;
    try {
        const deviceId = await getDeviceId();
        await Browser.open({ url: `${API_BASE_URL}/auth/google/redirect?mobile=1&platform=android&account_type=${accountType.value}&device_id=${encodeURIComponent(deviceId)}` });
    } catch (_) { googleLoading.value = false; error.value = 'No se pudo abrir Google. Inténtalo de nuevo.'; }
}

onMounted(async () => { appUrlListener = await App.addListener('appUrlOpen', ({ url }) => handleAppUrl(url)); const launch = await App.getLaunchUrl(); if (launch?.url) handleAppUrl(launch.url); });
onBeforeUnmount(() => appUrlListener?.remove());

async function submit() {
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
</script>

<template>
    <main class="register-screen" :style="{ backgroundImage: `linear-gradient(rgba(4,35,27,.58),rgba(4,35,27,.78)),url('${backgroundUrl}')` }">
        <div class="theme-toggle"><MobileThemeToggle /></div>
        <section class="register-wrap">
            <div class="brand-line"><MobileBrand compact dark-background /><RouterLink :to="{ name: 'login' }">Iniciar sesión</RouterLink></div>
            <header><p class="mobile-eyebrow">Únete a Arka01</p><h1 class="mobile-title">Crear una cuenta</h1><p>{{ step === 1 ? 'Cuéntanos quién eres y cómo usarás la aplicación.' : 'Crea una contraseña segura para terminar.' }}</p></header>

            <div class="register-progress"><span :class="{ active: step >= 1 }">1</span><i :class="{ active: step === 2 }"></i><span :class="{ active: step === 2 }">2</span></div>

            <form class="register-form mobile-card" @submit.prevent="step === 1 ? step = 2 : submit()">
                <div v-show="step === 1" class="form-step">
                <fieldset class="account-type">
                    <legend>Quiero usar Arka01 como</legend>
                    <button type="button" :class="{ active: accountType === 'cliente' }" @click="accountType = 'cliente'"><strong>Cliente</strong><small>Solicitar viajes</small></button>
                    <button type="button" :class="{ active: accountType === 'conductor' }" @click="accountType = 'conductor'"><strong>Conductor</strong><small>Recibir carreras</small></button>
                    <button type="button" class="cooperative-option" :class="{ active: accountType === 'cooperativa' }" @click="accountType = 'cooperativa'"><strong>Cooperativa</strong><small>Administrar conductores y unidades</small></button>
                </fieldset>

                <div class="name-grid">
                    <label class="mobile-field"><span>Nombre</span><input v-model="firstName" class="mobile-input" autocomplete="given-name" required /></label>
                    <label class="mobile-field"><span>Apellido</span><input v-model="lastName" class="mobile-input" autocomplete="family-name" required /></label>
                </div>
                <label class="mobile-field"><span>Correo electrónico</span><input v-model="email" class="mobile-input" type="email" autocomplete="email" autocapitalize="none" required /></label>
                <label class="mobile-field"><span>Teléfono</span><div class="phone"><select v-model="countryCode" class="mobile-input"><option v-for="code in countryCodes" :key="code" :value="code">{{ code }}</option></select><input v-model="phoneLocal" class="mobile-input" inputmode="numeric" autocomplete="tel-national" required /></div></label>
                <button class="mobile-button" type="submit" :disabled="!canContinue">Continuar</button>
                <div class="divider"><span></span><small>O</small><span></span></div>
                <button class="google-button" type="button" :disabled="googleLoading" @click="openGoogle"><span v-if="googleLoading" class="mobile-spinner"></span><strong v-else>G</strong> Continuar con Google</button>
                </div>

                <div v-show="step === 2" class="form-step">
                <div class="account-summary"><span>{{ accountType === 'conductor' ? 'C' : accountType === 'cooperativa' ? 'CO' : 'A' }}</span><p><small>Cuenta como</small><strong>{{ accountType === 'conductor' ? 'Conductor' : accountType === 'cooperativa' ? 'Cooperativa' : 'Cliente' }}</strong></p><button type="button" @click="step = 1">Cambiar</button></div>
                <label class="mobile-field"><span>Contraseña</span><input v-model="password" class="mobile-input" type="password" autocomplete="new-password" :required="step === 2" /></label>
                <label class="mobile-field"><span>Confirmar contraseña</span><input v-model="passwordConfirmation" class="mobile-input" type="password" autocomplete="new-password" :required="step === 2" /></label>

                <p v-if="error" class="mobile-alert">{{ error }}</p>
                <button class="mobile-button" type="submit" :disabled="loading"><span v-if="loading" class="mobile-spinner"></span>{{ loading ? 'Creando cuenta…' : 'Crear mi cuenta' }}</button>
                <p class="terms-copy">Al continuar aceptas los términos y la política de privacidad de Arka01.</p>
                <button class="back-step" type="button" @click="step = 1">Volver a mis datos</button>
                </div>
            </form>
        </section>
    </main>
</template>

<style scoped>
.register-screen { min-height: 100dvh; padding: calc(1rem + env(safe-area-inset-top)) 1rem calc(1.25rem + env(safe-area-inset-bottom)); }.register-wrap { width: 100%; max-width: 480px; margin: 0 auto; }.brand-line { min-height: 3rem; display: flex; align-items: center; justify-content: space-between; }.brand-line a { color: var(--arka-primary); font-size: .78rem; font-weight: 800; text-decoration: none; }.register-wrap > header { margin: 1.5rem 0 1.1rem; }.register-wrap > header h1 { margin-top: .35rem; }.register-wrap > header > p:last-child { margin: .55rem 0 0; color: var(--arka-muted); font-size: .82rem; line-height: 1.5; }
.register-screen { position:relative; background-size:cover; background-position:center; }.theme-toggle{position:absolute;right:1rem;top:calc(.7rem + env(safe-area-inset-top))}.brand-line a{color:#fff}.register-wrap>header,.register-wrap>header h1{color:#fff}.register-wrap>header>p:last-child{color:rgba(255,255,255,.82)}
.register-progress { margin: 0 auto 1rem; display: flex; align-items: center; justify-content: center; }.register-progress span { width: 1.7rem; height: 1.7rem; display: grid; place-items: center; border: 1px solid var(--arka-border); border-radius: 50%; color: var(--arka-muted); font-size: .65rem; font-weight: 800; }.register-progress span.active { border-color: var(--arka-primary); background: var(--arka-primary); color: #062016; }.register-progress i { width: 3.5rem; height: 1px; background: var(--arka-border); }.register-progress i.active { background: var(--arka-primary); }
.register-form { padding: 1rem; display: grid; gap: .85rem; }.form-step { display: grid; gap: .85rem; }.account-type { margin: 0; padding: 0; display: grid; grid-template-columns: 1fr 1fr; gap: .55rem; border: 0; }.account-type legend { grid-column: 1/-1; margin-bottom: .1rem; color: var(--arka-text); font-size: .8rem; font-weight: 700; }.account-type button { min-height: 3.8rem; display: grid; place-content: center; gap: .12rem; border: 1px solid var(--arka-border); border-radius: .9rem; background: rgba(7,17,13,.5); color: var(--arka-muted); }.account-type button strong { font-size: .84rem; }.account-type button small { font-size: .65rem; }.account-type button.active { border-color: rgba(52,211,153,.5); background: var(--arka-primary-soft); color: var(--arka-primary); }.name-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }.phone { display: grid; grid-template-columns: 6rem 1fr; gap: .5rem; }.terms-copy { margin: 0; color: #688076; text-align: center; font-size: .65rem; line-height: 1.45; }.account-summary { min-height: 4rem; padding: .65rem; display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: .65rem; border: 1px solid var(--arka-border); border-radius: .9rem; background: rgba(7,17,13,.5); }.account-summary > span { width: 2.3rem; height: 2.3rem; display: grid; place-items: center; border-radius: .75rem; background: var(--arka-primary-soft); color: var(--arka-primary); font-weight: 800; }.account-summary p { margin: 0; display: grid; gap: .12rem; }.account-summary small { color: var(--arka-muted); font-size: .62rem; }.account-summary strong { font-size: .8rem; }.account-summary button, .back-step { border: 0; background: transparent; color: var(--arka-primary); font-size: .68rem; font-weight: 800; }.back-step { min-height: 2rem; color: var(--arka-muted); }
.register-form{background:#fff;color:#18231e}.account-type button,.account-summary{background:#f7faf8}.divider{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:.7rem;color:#68756f}.divider span{height:1px;background:#d8dfdb}.google-button{min-height:3.1rem;display:flex;align-items:center;justify-content:center;gap:.65rem;border:1px solid #ccd5d0;border-radius:.85rem;background:#fff;color:#223029;font-weight:750}.google-button strong{color:#4285f4;font-size:1.2rem}
.account-type .cooperative-option{grid-column:1/-1}
.register-form .mobile-field>span{color:#18231e}.register-form .mobile-input{border-color:#c7d2cc;background:#f7faf8;color:#18231e}.register-form .mobile-input::placeholder{color:#68756f}.register-form .mobile-button{color:#fff}
</style>
