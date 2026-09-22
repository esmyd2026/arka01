<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { App } from '@capacitor/app';
import { Browser } from '@capacitor/browser';
import { exchangeGoogleCode, getDeviceId, login } from '../services/auth';
import { API_BASE_URL } from '../apiBase';
import MobileBrand from '../components/MobileBrand.vue';
import MobileThemeToggle from '../components/MobileThemeToggle.vue';
// Copia liviana (JPEG, redimensionada) solo para el bundle móvil: el
// original PNG del sitio web pesa ~625 KB y aquí se ve detrás de un
// degradado oscuro, así que la compresión agresiva no se nota.
import backgroundUrl from '../assets/img/fondo-login.jpg';

const router=useRouter(), loginField=ref(''), password=ref(''), error=ref(null), loading=ref(false), showPassword=ref(false), googleLoading=ref(false);
let appUrlListener=null;
async function submit(){loading.value=true;error.value=null;try{await login(loginField.value,password.value);router.replace({name:'home'})}catch(e){error.value=e.message}finally{loading.value=false}}
// Bug real reportado por el usuario (captura del botón "Continuar con
// Google" girando para siempre): cuando Google rechaza el authorization
// code (vencido o ya usado), el servidor vuelve a este mismo esquema pero
// SIN `code` (con `error` en su lugar, ver GoogleAuthController::
// failedGoogleAuth()) — antes esta función no hacía nada con esa vuelta,
// así que `googleLoading` quedaba en true para siempre. Ahora cualquier
// vuelta sin código apaga el spinner y muestra el motivo.
async function handleAppUrl(url){if(!url?.startsWith('com.arka01.app://auth/google'))return;await Browser.close().catch(()=>{});const params=new URL(url).searchParams;const code=params.get('code');if(!code){googleLoading.value=false;error.value=params.get('error')||'No se pudo iniciar sesión con Google. Inténtalo de nuevo.';return}googleLoading.value=true;error.value=null;try{await exchangeGoogleCode(code);router.replace({name:'home'})}catch(e){error.value=e.message}finally{googleLoading.value=false}}
async function openGoogle(){googleLoading.value=true;error.value=null;try{const deviceId=await getDeviceId();await Browser.open({url:`${API_BASE_URL}/auth/google/redirect?mobile=1&platform=android&device_id=${encodeURIComponent(deviceId)}`})}catch(e){googleLoading.value=false;error.value='No se pudo abrir Google. Inténtalo de nuevo.'}}
function openExternal(path){Browser.open({url:`${API_BASE_URL}${path}`})}
function openForgotPassword(){openExternal('/forgot-password')}
onMounted(async()=>{appUrlListener=await App.addListener('appUrlOpen',({url})=>handleAppUrl(url));const launch=await App.getLaunchUrl();if(launch?.url)handleAppUrl(launch.url)});
onBeforeUnmount(()=>appUrlListener?.remove());
</script>

<template>
    <main class="auth-screen" :style="{ backgroundImage: `linear-gradient(rgba(4,35,27,.58),rgba(4,35,27,.8)),url('${backgroundUrl}')` }">
        <div class="theme"><MobileThemeToggle /></div>
        <section class="auth-wrap">
            <div class="brand-wrap"><MobileBrand /></div>
            <form class="auth-card mobile-card" @submit.prevent="submit">
                <header>
                    <span class="access-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M14 8V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2-2v-2M10 12h11m-3-3 3 3-3 3" /></svg></span>
                    <div><p class="mobile-eyebrow">Acceso seguro</p><h1>Bienvenido de nuevo</h1><p>Ingresa a tu cuenta de Arka01.</p></div>
                </header>

                <label class="mobile-field">
                    <span>Teléfono, correo o usuario</span>
                    <div class="input-wrap"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"/><path d="M5 20a7 7 0 0 1 14 0"/></svg><input v-model="loginField" class="mobile-input" autocomplete="username" autocapitalize="none" placeholder="Tu correo, teléfono o usuario"></div>
                </label>
                <label class="mobile-field">
                    <span>Contraseña</span>
                    <div class="input-wrap password-wrap"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><input v-model="password" class="mobile-input" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" placeholder="Tu contraseña"><button type="button" @click="showPassword = !showPassword">{{ showPassword ? 'Ocultar' : 'Ver' }}</button></div>
                </label>
                <button type="button" class="forgot-link" @click="openForgotPassword">¿Olvidó su contraseña?</button>
                <p v-if="error" class="mobile-alert" role="alert">{{ error }}</p>
                <button class="mobile-button" type="submit" :disabled="loading || !loginField || !password"><span v-if="loading" class="mobile-spinner"></span>{{ loading ? 'Ingresando…' : 'INICIAR SESIÓN →' }}</button>
                <div class="divider"><span></span><small>O CONTINÚE CON</small><span></span></div>
                <button class="google-button" type="button" :disabled="googleLoading" @click="openGoogle"><span v-if="googleLoading" class="mobile-spinner"></span><svg v-else viewBox="0 0 24 24"><path fill="#4285F4" d="M21.6 12.2c0-.7-.1-1.4-.2-2H12v3.8h5.4a4.6 4.6 0 0 1-2 3v2.5h3.2c1.9-1.8 3-4.3 3-7.3Z"/><path fill="#34A853" d="M12 22c2.7 0 5-.9 6.6-2.4L15.4 17c-.9.6-2 1-3.4 1a5.8 5.8 0 0 1-5.5-4H3.2v2.6A10 10 0 0 0 12 22Z"/><path fill="#FBBC05" d="M6.5 14a6 6 0 0 1 0-4V7.4H3.2A10 10 0 0 0 2 12c0 1.6.4 3.2 1.2 4.6L6.5 14Z"/><path fill="#EA4335" d="M12 6c1.5 0 2.8.5 3.8 1.5l2.9-2.8A9.7 9.7 0 0 0 12 2a10 10 0 0 0-8.8 5.4L6.5 10A5.8 5.8 0 0 1 12 6Z"/></svg>Continuar con Google</button>
                <p class="register-copy">¿No tiene cuenta? <RouterLink :to="{ name:'register' }">Crear cuenta</RouterLink></p>
            </form>
            <footer><button type="button" @click="openExternal('/terminos')">Términos</button><span>·</span><button type="button" @click="openExternal('/privacidad')">Privacidad</button></footer>
        </section>
    </main>
</template>

<style scoped>
.auth-screen{position:relative;height:100dvh;min-height:0;padding:calc(1.15rem + env(safe-area-inset-top)) 1rem calc(1.2rem + env(safe-area-inset-bottom));display:grid;align-items:center;overflow-x:hidden;overflow-y:auto;overscroll-behavior-y:contain;-webkit-overflow-scrolling:touch;background-size:cover;background-position:center}.theme{position:absolute;z-index:2;top:calc(.8rem + env(safe-area-inset-top));right:1rem}.auth-wrap{width:100%;max-width:430px;margin:auto}.brand-wrap{margin-bottom:1.1rem;display:flex;justify-content:center;filter:brightness(0) invert(1)}.auth-card{padding:1.35rem;display:grid;gap:.9rem;background:#fff;color:#18231e}.auth-card header{display:grid;grid-template-columns:auto 1fr;align-items:center;gap:.75rem}.access-icon{width:2.7rem;height:2.7rem;display:grid;place-items:center;border:1px solid rgba(23,139,98,.25);border-radius:.8rem;background:rgba(23,139,98,.09);color:#178b62}.access-icon svg{width:1.4rem;height:1.4rem;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.auth-card header h1{margin:.2rem 0 .15rem;font-size:1.4rem;letter-spacing:-.03em}.auth-card header p:last-child{margin:0;color:#68756f;font-size:.8rem}.auth-card .mobile-field>span{color:#18231e}.input-wrap{position:relative}.input-wrap>svg{position:absolute;z-index:1;left:.85rem;top:50%;width:1.15rem;height:1.15rem;transform:translateY(-50%);fill:none;stroke:#6c7f76;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}.auth-card .mobile-input{padding-left:2.55rem;border-color:#b9c8c0;background:#edf3ff;color:#18231e}.auth-card .mobile-input::placeholder{color:#68756f}.password-wrap button{position:absolute;right:.5rem;top:50%;transform:translateY(-50%);border:0;background:transparent;color:#178b62;font-size:.72rem;font-weight:800}.password-wrap input{padding-right:4rem}.forgot-link{justify-self:end;border:0;background:transparent;color:#178b62;font-size:.76rem}.auth-card .mobile-button{color:#fff}.divider{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:.7rem;color:#68756f}.divider span{height:1px;background:#d8dfdb}.divider small{font-size:.62rem;letter-spacing:.1em}.google-button{min-height:3.1rem;display:flex;align-items:center;justify-content:center;gap:.65rem;border:1px solid #ccd5d0;border-radius:.85rem;background:#fff;color:#223029;font-weight:750}.google-button svg{width:1.25rem;height:1.25rem}.register-copy{margin:.2rem 0 0;text-align:center;color:#68756f;font-size:.78rem}.register-copy a{color:#178b62;font-weight:800;text-decoration:none}footer{margin-top:1rem;display:flex;justify-content:center;gap:.55rem;color:#fff;font-size:.72rem}footer button{border:0;padding:.3rem;background:transparent;color:inherit;text-decoration:underline;text-underline-offset:.18rem}
@media(max-height:720px){.auth-screen{align-items:start}.brand-wrap{margin-bottom:.75rem}.auth-card{gap:.72rem;padding:1.1rem}}
</style>
