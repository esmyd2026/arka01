<script setup>
import { computed, ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import MobileBrand from './MobileBrand.vue';
import MobileThemeToggle from './MobileThemeToggle.vue';
import { initPushNotifications } from '../services/push';

const props = defineProps({ role: { type: String, default: 'cliente' }, userName: { type: String, default: '' } });
const route = useRoute();
const router = useRouter();

// Notificaciones push nativas (pedido explícito del usuario: "para la
// carreras o solicitudes"): MobileShell está en toda pantalla autenticada,
// así que es el único lugar que hace falta tocar — el candado de módulo
// dentro de initPushNotifications() evita pedir permiso/registrar de nuevo
// en cada navegación (MobileShell se remonta en cada pantalla).
onMounted(() => initPushNotifications(router));
const quickOpen = ref(false);
const initial = computed(() => props.userName?.trim()?.charAt(0)?.toUpperCase() || 'A');
const isDriver = computed(() => props.role === 'conductor');
const isCooperative = computed(() => props.role === 'cooperativa');
const navItems = computed(() => isDriver.value ? [
    { name: 'home', label: 'Inicio', icon: 'home' },
    { name: 'incoming-rides', label: 'Solicitudes', icon: 'people' },
    { name: 'quick', label: 'Más', icon: 'plus', primary: true },
    { name: 'driver-clients', label: 'Clientes', icon: 'clients' },
    { name: 'profile', label: 'Perfil', icon: 'profile' },
] : isCooperative.value ? [
    { name: 'home', label: 'Inicio', icon: 'home' },
    { name: 'cooperatives', label: 'Red', icon: 'people' },
    { name: 'quick', label: 'Gestionar', icon: 'plus', primary: true },
    { name: 'plan', label: 'Plan', icon: 'car' },
    { name: 'profile', label: 'Perfil', icon: 'profile' },
] : [
    { name: 'home', label: 'Inicio', icon: 'home' },
    { name: 'fleet', label: 'Flotas', icon: 'people' },
    { name: 'quick', label: 'Pedir', icon: 'plus', primary: true },
    { name: 'ride-history', label: 'Carreras', icon: 'car' },
    { name: 'profile', label: 'Perfil', icon: 'profile' },
]);
function active(item) {
    if (route.name === item.name) return true;
    if (route.name === 'active-ride') return item.name === (isDriver.value ? 'incoming-rides' : 'ride-history');
    return item.name === 'ride-history' && route.name === 'ride-status';
}
const quickActions = computed(() => isDriver.value ? [
    { name:'driver-status', label:'Conectarme', copy:'Activa o pausa tu disponibilidad', icon:'●' },
    { name:'incoming-rides', label:'Solicitudes', copy:'Revisa carreras disponibles', icon:'➤' },
    { name:'driver-clients', label:'Mis clientes', copy:'Invitaciones y cartera de confianza', icon:'◎' },
    { name:'driver-profile', label:'Perfil de conductor', copy:'Vehículo, tarifa y documentos', icon:'◇' },
    { name:'driver-stats', label:'Indicadores', copy:'Ganancias, medallas e historial', icon:'▥' },
    { name:'cooperative-invitations', label:'Cooperativas', copy:'Vínculos e invitaciones', icon:'⌂' },
    { name:'trust-circle', label:'Círculo de confianza', copy:'Familiares con quienes compartes tu viaje', icon:'♡' },
    { name:'express-routes', label:'Expresos', copy:'Rutas fijas de tus clientes', icon:'ϟ' },
    { name:'support', label:'Soporte', copy:'Ayuda y contacto', icon:'?' },
] : isCooperative.value ? [
    { name:'profile', label:'Datos de la cuenta', copy:'Identidad y seguridad', icon:'◇' },
    { name:'cooperatives', label:'Directorio', copy:'Consulta la red de cooperativas', icon:'⌂' },
    { name:'plan', label:'Plan institucional', copy:'Beneficios y límites disponibles', icon:'★' },
    { name:'support', label:'Soporte', copy:'Ayuda para completar la validación', icon:'?' },
] : [
    { name:'request-ride', label:'Pedir carrera', copy:'Ahora o programada', icon:'+' },
    { name:'fleet', label:'Mis flotas', copy:'Conductores de confianza', icon:'◎' },
    { name:'directory', label:'Buscar conductores', copy:'Directorio público verificado', icon:'⌕' },
    { name:'cooperatives', label:'Cooperativas', copy:'Explora y únete a una cooperativa', icon:'⌂' },
    { name:'saved-routes', label:'Mis rutas', copy:'Destinos frecuentes', icon:'⌖' },
    { name:'van-trips', label:'Van y buseta', copy:'Viajes compartidos por asientos', icon:'▥' },
    { name:'express-routes', label:'Expresos', copy:'Publica una ruta fija', icon:'ϟ' },
    { name:'trust-circle', label:'Círculo de confianza', copy:'Familiares que verán tu viaje', icon:'♡' },
    { name:'trusted-contacts', label:'Contactos de confianza', copy:'Personas para alertas SOS', icon:'☎' },
    { name:'coupons', label:'Cupones', copy:'Beneficios disponibles', icon:'%' },
    { name:'plan', label:'Mi plan', copy:'Suscripción y beneficios', icon:'★' },
    { name:'support', label:'Soporte', copy:'Ayuda y contacto', icon:'?' },
]);
function openAction(name){quickOpen.value=false;router.push({name})}
</script>

<template>
    <div class="app-shell">
        <header class="app-header">
            <MobileBrand compact />
            <div class="header-actions">
                <MobileThemeToggle />
                <RouterLink class="app-avatar" :to="{ name: 'profile' }" aria-label="Abrir perfil">{{ initial }}</RouterLink>
            </div>
        </header>
        <div class="app-viewport">
            <slot />
        </div>
        <nav class="bottom-nav" aria-label="Navegación principal">
            <template v-for="item in navItems" :key="item.name">
            <button v-if="item.primary" type="button" class="nav-item primary" :aria-expanded="quickOpen" aria-label="Abrir accesos rápidos" @click="quickOpen=true">
                <span class="nav-icon">
                    <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                </span><span>{{ item.label }}</span>
            </button>
            <RouterLink v-else :to="{ name: item.name }" class="nav-item" :class="{ active: active(item) }">
                <span class="nav-icon">
                    <svg v-if="item.icon === 'home'" viewBox="0 0 24 24"><path d="m4 10 8-6 8 6v9a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1v-9Z"/></svg>
                    <svg v-else-if="item.icon === 'people'" viewBox="0 0 24 24"><path d="M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm7-1a2.5 2.5 0 1 0 0-5M3 19a6 6 0 0 1 12 0m1-6a5 5 0 0 1 5 5"/></svg>
                    <svg v-else-if="item.icon === 'clients'" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3 19a6 6 0 0 1 12 0m2-12h4m-2-2v4"/></svg>
                    <svg v-else-if="item.icon === 'car'" viewBox="0 0 24 24"><path d="M5 16h14l-1.5-6a2 2 0 0 0-2-1.5h-7A2 2 0 0 0 6.5 10L5 16Zm2 0v2m10-2v2M8 12h8"/></svg>
                    <svg v-else viewBox="0 0 24 24"><circle cx="12" cy="8" r="3"/><path d="M5 21a7 7 0 0 1 14 0"/></svg>
                </span><span>{{ item.label }}</span>
            </RouterLink>
            </template>
        </nav>
        <div v-if="quickOpen" class="quick-backdrop" @click.self="quickOpen=false">
            <section class="quick-sheet" aria-label="Accesos rápidos">
                <span class="sheet-handle"></span><div class="quick-heading"><div><p>ACCESOS RÁPIDOS</p><h2>{{ isDriver || isCooperative ? '¿Qué necesitas gestionar?' : '¿Qué quieres hacer?' }}</h2></div><button @click="quickOpen=false" aria-label="Cerrar">×</button></div>
                <div class="quick-grid"><button v-for="action in quickActions" :key="action.name" @click="openAction(action.name)"><span>{{ action.icon }}</span><div><strong>{{ action.label }}</strong><small>{{ action.copy }}</small></div><b>›</b></button></div>
            </section>
        </div>
    </div>
</template>

<style scoped>
.app-shell{height:100dvh;min-height:0;display:flex;flex-direction:column;overflow:hidden;background:var(--arka-bg)}
.app-header{position:relative;z-index:30;flex:0 0 auto;height:calc(4rem + env(safe-area-inset-top));padding:env(safe-area-inset-top) 1rem 0;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--arka-border);background:var(--arka-chrome);backdrop-filter:blur(18px)}
.app-viewport{position:relative;flex:1 1 auto;min-width:0;min-height:0;overflow-x:hidden;overflow-y:auto;overscroll-behavior-y:contain;-webkit-overflow-scrolling:touch;scrollbar-gutter:stable}
.header-actions{display:flex;align-items:center;gap:.55rem}.app-avatar{width:2.25rem;height:2.25rem;display:grid;place-items:center;border:1px solid rgba(23,139,98,.25);border-radius:50%;background:var(--arka-primary);color:#fff;font-size:.8rem;font-weight:800;text-decoration:none}
.bottom-nav{position:relative;z-index:50;flex:0 0 auto;min-height:calc(4.35rem + env(safe-area-inset-bottom));padding:.35rem .3rem env(safe-area-inset-bottom);display:grid;grid-template-columns:repeat(5,1fr);border-top:1px solid var(--arka-border);background:var(--arka-chrome);backdrop-filter:blur(20px)}
.nav-item{min-width:0;border:0;background:transparent;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.15rem;color:var(--arka-muted);font-size:.62rem;font-weight:700;text-decoration:none}.nav-icon{width:1.4rem;height:1.4rem;display:grid;place-items:center}.nav-icon svg{width:1.3rem;height:1.3rem;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}.nav-item.active{color:var(--arka-primary)}
.nav-item.primary .nav-icon{width:3.4rem;height:3.4rem;margin-top:-1.25rem;border-radius:50%;background:var(--arka-primary);color:#fff;box-shadow:0 8px 18px rgba(23,139,98,.3)}.nav-item.primary .nav-icon svg{width:1.65rem;height:1.65rem;stroke-width:2.4}.nav-item.primary>span:last-child{margin-top:.05rem}
.quick-backdrop{position:fixed;z-index:90;inset:0;display:flex;align-items:flex-end;background:rgba(3,12,8,.62);backdrop-filter:blur(3px)}.quick-sheet{width:100%;max-width:560px;max-height:78dvh;margin:0 auto;padding:.55rem 1rem calc(1rem + env(safe-area-inset-bottom));overflow:auto;border:1px solid var(--arka-border);border-radius:1.5rem 1.5rem 0 0;background:var(--arka-card);box-shadow:0 -18px 50px rgba(0,0,0,.24)}.sheet-handle{display:block;width:2.5rem;height:.24rem;margin:0 auto .8rem;border-radius:999px;background:var(--arka-border)}.quick-heading{display:flex;align-items:start;justify-content:space-between;gap:1rem}.quick-heading p{margin:0;color:var(--arka-primary);font-size:.62rem;font-weight:850;letter-spacing:.14em}.quick-heading h2{margin:.2rem 0 .8rem;font-size:1.15rem}.quick-heading>button{width:2rem;height:2rem;border:0;border-radius:50%;background:var(--arka-surface);color:var(--arka-muted);font-size:1.2rem}.quick-grid{display:grid;grid-template-columns:1fr 1fr;gap:.55rem}.quick-grid>button{min-width:0;padding:.75rem;display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:.55rem;border:1px solid var(--arka-border);border-radius:1rem;background:var(--arka-card-strong);color:var(--arka-text);text-align:left}.quick-grid>button>span{width:2rem;height:2rem;display:grid;place-items:center;border-radius:.65rem;background:var(--arka-primary-soft);color:var(--arka-primary);font-weight:900}.quick-grid div{min-width:0;display:grid;gap:.12rem}.quick-grid strong{font-size:.76rem}.quick-grid small{color:var(--arka-muted);font-size:.61rem;line-height:1.3}.quick-grid b{color:var(--arka-primary)}
@media(max-width:370px){.quick-grid{grid-template-columns:1fr}}
</style>
