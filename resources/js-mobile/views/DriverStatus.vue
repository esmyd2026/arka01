<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { Geolocation } from '@capacitor/geolocation';
import { fetchDriverStatus, updateDriverLocation } from '../services/driver';
import { getStoredUser } from '../services/auth';
import MobileShell from '../components/MobileShell.vue';

// Mismo patrón que resources/js/Components/DriverAvailabilityToggle.vue
// (roadmap Hito 5): mientras está conectado, manda su ubicación cada ~15s
// vía watchPosition; al desconectarse, corta el watch ANTES de mandar el
// último ping (mismo orden que la web, evita el bug real que documenta ese
// componente: un ping de encendido colándose después del de apagado).
const router = useRouter();

const loading = ref(true);
const available = ref(false);
const canConnect = ref(true);
const blockedReason = ref('');
const ratePerKm = ref(null);
const error = ref('');
const sending = ref(false);
const user = ref(null);

let watchId = null;
let lastSentAt = 0;
const MIN_SECONDS_BETWEEN_UPDATES = 15;
let lastKnownPosition = null;

async function ensureLocationPermission() {
    const permission = await Geolocation.checkPermissions();
    if (permission.location === 'granted' || permission.coarseLocation === 'granted') return true;

    const requested = await Geolocation.requestPermissions();
    return requested.location === 'granted' || requested.coarseLocation === 'granted';
}

async function loadStatus() {
    loading.value = true;
    try {
        const status = await fetchDriverStatus();
        available.value = status.is_available;
        canConnect.value = status.can_connect;
        blockedReason.value = status.connection_block_reason || '';
        ratePerKm.value = status.rate_per_km;
        error.value = canConnect.value ? '' : blockedReason.value;

        if (available.value) {
            const granted = await ensureLocationPermission();
            if (granted) startWatching();
        }
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    user.value = await getStoredUser();
    await loadStatus();
});

onBeforeUnmount(() => {
    if (watchId !== null) Geolocation.clearWatch({ id: watchId });
});

async function sendLocation(lat, lng, isAvailable) {
    sending.value = true;
    try {
        await updateDriverLocation(lat, lng, isAvailable);
        error.value = '';
    } catch (e) {
        error.value = e.message;
        // Un corte de red transitorio no debería forzar la desconexión (el
        // mensaje de red es siempre el mismo, ver services/driver.js) — solo
        // un rechazo real del servidor (403, perfil bloqueado) apaga el
        // switch, igual que la web distingue por status 403.
        if (isAvailable && e.message !== 'No se pudo conectar con el servidor.') {
            if (watchId !== null) {
                await Geolocation.clearWatch({ id: watchId });
                watchId = null;
            }
            available.value = false;
        }
    } finally {
        sending.value = false;
    }
}

function handlePosition(position, err) {
    if (err || !position) return;
    lastKnownPosition = position;

    const now = Date.now() / 1000;
    if (now - lastSentAt < MIN_SECONDS_BETWEEN_UPDATES) return;

    lastSentAt = now;
    sendLocation(position.coords.latitude, position.coords.longitude, true);
}

async function startWatching() {
    try {
        watchId = await Geolocation.watchPosition({ enableHighAccuracy: true }, handlePosition);
    } catch (e) {
        error.value = 'No pudimos acceder a tu ubicación. Revisá los permisos.';
        available.value = false;
    }
}

async function toggle() {
    error.value = '';

    if (!available.value && !canConnect.value) {
        error.value = blockedReason.value;
        return;
    }

    if (!available.value) {
        const granted = await ensureLocationPermission();
        if (!granted) {
            error.value = 'Sin permiso de ubicación no te podés conectar. Activalo en Ajustes.';
            return;
        }

        try {
            const position = await Geolocation.getCurrentPosition({ enableHighAccuracy: true });
            available.value = true;
            lastSentAt = Date.now() / 1000;
            await sendLocation(position.coords.latitude, position.coords.longitude, true);
            if (available.value) startWatching();
        } catch (e) {
            error.value = 'No pudimos acceder a tu ubicación. Revisá los permisos.';
        }
    } else {
        const positionAtStop = lastKnownPosition;
        available.value = false;

        if (watchId !== null) {
            await Geolocation.clearWatch({ id: watchId });
            watchId = null;
        }

        if (positionAtStop) {
            await sendLocation(positionAtStop.coords.latitude, positionAtStop.coords.longitude, false);
        }
    }
}
</script>

<template>
    <MobileShell role="conductor" :user-name="user?.name || ''">
        <main class="mobile-page status-page">
            <header>
                <p class="mobile-eyebrow">Disponibilidad</p>
                <h1 class="mobile-title">Recibir carreras</h1>
                <p class="page-copy">Tú decides cuándo aparecer como disponible para tus clientes.</p>
            </header>

            <div v-if="loading" class="loading-card mobile-card"><span class="mobile-spinner"></span><span>Consultando tu estado…</span></div>

            <template v-else>
                <section class="availability-card mobile-card" :class="{ online: available }">
                    <div class="availability-top">
                        <span class="power-icon">
                            <svg viewBox="0 0 24 24"><path d="M12 3v9m5.7-6.7a9 9 0 1 1-11.4 0" /></svg>
                        </span>
                        <div><p class="mobile-eyebrow">{{ available ? 'Listo para recibir' : 'Fuera de servicio' }}</p><h2>{{ available ? 'Estás disponible' : 'No estás disponible' }}</h2></div>
                    </div>
                    <p>{{ available ? 'Tus clientes pueden enviarte solicitudes y tu ubicación se actualiza mientras estás conectado.' : 'Activa tu disponibilidad cuando estés listo para conducir.' }}</p>

                    <button class="availability-switch" :class="{ on: available }" role="switch" :aria-checked="available" :disabled="sending" @click="toggle">
                        <span><strong>{{ available ? 'Disponible' : 'No disponible' }}</strong><small>{{ sending ? 'Actualizando ubicación…' : available ? 'Toca para desconectarte' : 'Toca para conectarte' }}</small></span>
                        <span class="toggle-track"><i></i></span>
                    </button>
                </section>

                <section v-if="ratePerKm" class="rate-card mobile-card">
                    <div><p class="mobile-eyebrow">Tu tarifa declarada</p><strong>${{ ratePerKm }}</strong><small>por kilómetro</small></div>
                    <button @click="router.push({ name: 'home' })">Ver inicio</button>
                </section>

                <p v-if="error" class="mobile-alert">{{ error }}</p>

                <section class="privacy-note">
                    <svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.6 2.8 8 7 10 4.2-2 7-5.4 7-10V6l-7-3Zm-3 9 2 2 4-4" /></svg>
                    <p><strong>Control de ubicación</strong><span>Arka01 deja de actualizar tu posición cuando desactivas la disponibilidad.</span></p>
                </section>
            </template>
        </main>
    </MobileShell>
</template>

<style scoped>
.status-page { display: grid; gap: 1rem; }.page-copy { margin: .5rem 0 0; color: var(--arka-muted); font-size: .86rem; line-height: 1.45; }.loading-card { min-height: 6rem; display: flex; align-items: center; justify-content: center; gap: .7rem; color: var(--arka-muted); }
.availability-card { padding: 1rem; background:var(--arka-card); transition: border-color .2s, background .2s; }.availability-card.online { border-color:color-mix(in srgb,var(--arka-primary) 35%,transparent);background:var(--arka-success-panel) }.availability-top { display: flex; align-items: center; gap: .8rem; }.power-icon { width: 2.75rem; height: 2.75rem; display: grid; place-items: center; border-radius: .9rem; background:var(--arka-surface); color: var(--arka-muted); }.online .power-icon { background: var(--arka-primary); color:var(--arka-on-primary); }.power-icon svg, .privacy-note svg { width: 1.4rem; height: 1.4rem; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }.availability-top h2 { margin: .2rem 0 0; font-size: 1.05rem; }.availability-card > p { margin: .9rem 0; color: var(--arka-muted); font-size: .8rem; line-height: 1.5; }
.availability-switch { width: 100%; min-height: 4rem; padding: .7rem .8rem; display: flex; align-items: center; justify-content: space-between; border: 1px solid var(--arka-border); border-radius: 1rem; background:var(--arka-field); color: var(--arka-text); text-align: left; }.availability-switch > span:first-child { display: grid; gap: .15rem; }.availability-switch strong { font-size: .86rem; }.availability-switch small { color: var(--arka-muted); font-size: .68rem; }.toggle-track { position: relative; width: 3rem; height: 1.75rem; flex: 0 0 auto; border-radius: 999px; background:var(--arka-track); transition: background .2s; }.toggle-track i { position: absolute; top: .2rem; left: .2rem; width: 1.35rem; height: 1.35rem; border-radius: 50%; background:var(--arka-card); transition: transform .2s; }.availability-switch.on .toggle-track { background: var(--arka-primary); }.availability-switch.on .toggle-track i { transform: translateX(1.25rem); background:var(--arka-on-primary); }
.rate-card { padding: 1rem; display: flex; align-items: center; justify-content: space-between; }.rate-card > div { display: grid; }.rate-card strong { margin-top: .25rem; color: var(--arka-primary); font-size: 1.5rem; }.rate-card small { color: var(--arka-muted); font-size: .68rem; }.rate-card button { min-height: 2.5rem; border: 1px solid var(--arka-border); border-radius: .8rem; padding: 0 .75rem; background: transparent; color: var(--arka-muted); font-size: .72rem; }
.privacy-note { padding: .75rem .2rem; display: flex; align-items: flex-start; gap: .75rem; color: var(--arka-muted); }.privacy-note > p { margin: 0; display: grid; gap: .2rem; }.privacy-note strong { color: var(--arka-text); font-size: .78rem; }.privacy-note span { font-size: .7rem; line-height: 1.45; }
</style>
