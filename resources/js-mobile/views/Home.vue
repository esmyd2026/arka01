<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { Geolocation } from '@capacitor/geolocation';
import { getStoredUser, fetchCurrentUser } from '../services/auth';
import { fetchActiveRide } from '../services/activeRide';
import MobileShell from '../components/MobileShell.vue';
import MobileMap from '../components/MobileMap.vue';

const router = useRouter();
const user = ref(null);
const checkingSession = ref(true);
const activeRide = ref(null);
const currentPosition = ref(null);
const mapRef = ref(null);

async function locateForMap() {
    try {
        const permission = await Geolocation.checkPermissions();
        if (permission.location !== 'granted' && permission.coarseLocation !== 'granted') return;
        const position = await Geolocation.getCurrentPosition({ enableHighAccuracy: true, timeout: 12000 });
        currentPosition.value = { lat: position.coords.latitude, lng: position.coords.longitude };
        mapRef.value?.setView(currentPosition.value, 15);
    } catch (_) { /* El mapa conserva Guayaquil como respaldo visual. */ }
}

function openRideRequest(scheduled = false) {
    router.push({ name: 'request-ride', query: scheduled ? { programar: '1' } : {} });
}

onMounted(async () => {
    locateForMap();
    user.value = await getStoredUser();

    const fresh = await fetchCurrentUser();
    checkingSession.value = false;

    if (!fresh) {
        router.replace({ name: 'login' });
        return;
    }
    user.value = fresh;

    // Si ya tiene una carrera programada/en curso (cliente o conductor),
    // se lo ofrece de una en vez de que tenga que recordarlo o volver a
    // pedir/aceptar otra por error.
    try {
        activeRide.value = await fetchActiveRide();
    } catch (e) {
        // Sin bloquear el resto de Inicio si esto falla.
    }
});

</script>

<template>
    <MobileShell v-if="user" :role="user.role" :user-name="user.name">
        <main :class="user.role === 'cliente' ? 'client-home' : 'mobile-page home-page'">
            <section v-if="user.role !== 'cliente'" class="welcome">
                <p class="mobile-eyebrow">{{ user.role === 'conductor' ? 'Panel del conductor' : user.role === 'cooperativa' ? 'Panel de cooperativa' : 'Movilidad de confianza' }}</p>
                <h1 class="mobile-title">Hola, {{ user.name?.split(' ')[0] }}</h1>
                <p class="welcome-copy">{{ user.role === 'conductor' ? 'Conéctate cuando estés listo para recibir carreras.' : user.role === 'cooperativa' ? 'Gestiona tu cuenta y la red de transporte de tu organización.' : '¿A dónde quieres ir hoy?' }}</p>
            </section>

            <section v-if="user.role === 'cliente'" class="home-map">
                <MobileMap ref="mapRef" :center="currentPosition" :zoom="15" />
                <div class="nearby-pill"><span></span> Tu red de confianza, cerca de ti</div>
                <button class="locate-button" aria-label="Centrar en mi ubicación" @click="locateForMap">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>
                </button>
                <section class="destination-sheet">
                    <h1>¿A dónde vamos?</h1>
                    <p>Tu ubicación actual será el punto de partida.</p>
                    <button class="destination-search" @click="openRideRequest(false)">
                        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="6"/><path d="m16 16 4 4"/></svg>
                        <span>Buscar destino</span><b>›</b>
                    </button>
                    <small>Consejo: escribe la avenida o calle principal y la transversal. Después podrás ajustar el punto en el mapa.</small>
                    <div class="destination-actions">
                        <button @click="router.push({ name: 'saved-routes' })">＋ Agregar</button>
                        <button class="schedule-button" @click="openRideRequest(true)">
                            <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg>
                            Programar
                        </button>
                    </div>
                </section>
            </section>

            <button v-if="activeRide" class="active-trip mobile-card" @click="router.push({ name: 'active-ride', params: { id: activeRide.id } })">
                <span class="active-trip__icon">
                    <svg viewBox="0 0 24 24"><path d="M5 16h14l-1.5-6a2 2 0 0 0-2-1.5h-7A2 2 0 0 0 6.5 10L5 16Zm2 0v2m10-2v2" /></svg>
                </span>
                <span class="active-trip__copy"><small>Carrera en curso</small><strong>Continuar seguimiento</strong></span>
                <span aria-hidden="true">›</span>
            </button>

            <template v-if="user.role === 'conductor'">
                <section class="primary-action mobile-card driver-action">
                    <div><p class="mobile-eyebrow">Estado de servicio</p><h2>Controla tu disponibilidad</h2><p>Tu ubicación solo se comparte mientras estás conectado.</p></div>
                    <button class="mobile-button" @click="router.push({ name: 'driver-status' })">Revisar disponibilidad</button>
                </section>
            </template>
            <section v-else-if="user.role === 'cooperativa'" class="primary-action mobile-card">
                <div><p class="mobile-eyebrow">Cuenta institucional</p><h2>Completa el perfil de la cooperativa</h2><p>Revisa los datos de contacto, el plan y el estado de tu cuenta desde el perfil.</p></div>
                <button class="mobile-button" @click="router.push({ name: 'profile' })">Gestionar mi cuenta</button>
            </section>
        </main>
    </MobileShell>

    <main v-else-if="checkingSession" class="loading-screen"><span class="mobile-spinner"></span><p>Preparando tu inicio…</p></main>
</template>

<style scoped>
.home-page { display: grid; gap: 1.15rem; }
.client-home{position:fixed;z-index:1;inset:calc(4rem + env(safe-area-inset-top)) 0 calc(4.35rem + env(safe-area-inset-bottom));overflow:hidden;background:#e7efeb}
.welcome { padding: .35rem .25rem .2rem; }
.welcome-copy { margin: .5rem 0 0; color: var(--arka-muted); font-size: .9rem; }
.active-trip { width: 100%; border-color: rgba(52,211,153,.22); color: var(--arka-text); text-align: left; }
.active-trip { min-height: 4.7rem; padding: .85rem 1rem; display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: .8rem; background:var(--arka-status-panel); }
.client-home>.active-trip{position:absolute;z-index:4;top:4.5rem;right:.85rem;left:.85rem}
.active-trip__icon { width: 2.5rem; height: 2.5rem; display: grid; place-items: center; border-radius: .85rem; background: var(--arka-primary-soft); color: var(--arka-primary); }
.active-trip svg, .mobile-button svg { width: 1.35rem; height: 1.35rem; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
.active-trip__copy { display: grid; gap: .15rem; }.active-trip__copy small { color: var(--arka-primary); font-size: .7rem; font-weight: 800; text-transform: uppercase; }.active-trip__copy strong { font-size: .95rem; }
.primary-action { padding: 1.2rem; display: grid; gap: 1.15rem; overflow: hidden; background:var(--arka-success-panel); }
.primary-action h2 { margin: .35rem 0 0; font-size: 1.15rem; letter-spacing: -.025em; }.primary-action p:not(.mobile-eyebrow) { margin: .45rem 0 0; color: var(--arka-muted); font-size: .83rem; line-height: 1.5; }
.driver-action { border-color: rgba(52,211,153,.24); }
.loading-screen { min-height: 100dvh; display: grid; place-content: center; justify-items: center; gap: .75rem; color: var(--arka-muted); }
.home-map { position:absolute;inset:0;overflow:hidden; }
.home-map :deep(.mobile-map) { position: absolute; inset: 0; }
.nearby-pill { position: absolute; z-index:2; top: .75rem; left: .75rem; width: max-content; max-width: calc(100% - 4.75rem); padding: .55rem .8rem; border-radius: 999px; background: rgba(31,45,39,.82); color: #fff; box-shadow: 0 6px 16px rgba(0,0,0,.18);backdrop-filter:blur(8px); font-size: .72rem; font-weight: 750;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.nearby-pill span { width: .5rem; height: .5rem; margin-right: .35rem; display: inline-block; border-radius: 50%; background: #39d7a0; }
.locate-button{position:absolute;z-index:2;top:.65rem;right:.75rem;width:3rem;height:3rem;display:grid;place-items:center;border:0;border-radius:50%;background:var(--arka-elevated);color:var(--arka-muted);box-shadow:var(--arka-shadow)}.locate-button svg{width:1.25rem;height:1.25rem;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round}
.destination-sheet{position:absolute;z-index:3;right:.85rem;bottom:.85rem;left:.85rem;padding:1rem;border:1px solid var(--arka-border);border-radius:1.4rem;background:color-mix(in srgb,var(--arka-elevated) 96%,transparent);color:var(--arka-text);box-shadow:0 12px 34px rgba(16,24,23,.16);backdrop-filter:blur(14px)}.destination-sheet h1{margin:0;font-size:1.35rem;letter-spacing:-.035em}.destination-sheet>p{margin:.18rem 0 .75rem;color:var(--arka-muted);font-size:.72rem}.destination-search{width:100%;min-height:3.25rem;padding:.7rem .85rem;display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:.7rem;border:0;border-radius:1rem;background:var(--arka-field);color:var(--arka-muted);text-align:left}.destination-search svg{width:1.15rem;height:1.15rem;fill:none;stroke:currentColor;stroke-width:2}.destination-search b{color:var(--arka-primary);font-size:1.3rem}.destination-sheet>small{display:block;margin:.45rem .25rem 0;color:var(--arka-muted);font-size:.57rem;line-height:1.45}.destination-actions{margin-top:.65rem;display:flex;align-items:center;justify-content:space-between}.destination-actions>button{min-height:2.25rem;border:0;background:transparent;color:var(--arka-muted);font-size:.7rem;font-weight:700}.destination-actions .schedule-button{padding:0 .85rem;display:flex;align-items:center;gap:.4rem;border:1px solid color-mix(in srgb,var(--arka-primary) 35%,transparent);border-radius:999px;background:var(--arka-primary-soft);color:var(--arka-text)}.schedule-button svg{width:1rem;height:1rem;fill:none;stroke:var(--arka-primary);stroke-width:2}
:root:not(.dark) .primary-action { background: var(--arka-card); }
</style>
