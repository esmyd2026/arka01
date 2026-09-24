<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { fetchDirectory } from '../services/directory';
import { inviteDriver } from '../services/fleet';
import { fetchCities } from '../services/profile';
import { getStoredUser } from '../services/auth';
import MobileShell from '../components/MobileShell.vue';

const router = useRouter();
const user = ref(null);
const loading = ref(true);
const error = ref(null);
const drivers = ref([]);
const targetFleetId = ref(null);
const invitingId = ref(null);
// Filtro por sector (pedido explícito del usuario: "el cliente... pueda ver
// a los conductores de su sector") — mismo catálogo ciudad/sector que la web.
const cities = ref([]);
const selectedSectorId = ref('');

const statusLabel = { member: 'Ya en tu flota', pending: 'Invitación enviada', not_invited: null };

async function load() {
    loading.value = true;
    try {
        const data = await fetchDirectory(1, null, selectedSectorId.value || null);
        drivers.value = data.drivers;
        targetFleetId.value = data.target_fleet_id;
    } catch (e) {
        error.value = e.message || 'No se pudo cargar el directorio.';
    } finally {
        loading.value = false;
    }
}

async function onSectorChange() {
    await load();
}

onMounted(async () => {
    user.value = await getStoredUser();
    if (user.value?.role === 'conductor') {
        router.replace({ name: 'home' });
        return;
    }
    await load();
    try {
        cities.value = await fetchCities();
    } catch (e) {
        // El filtro es un extra: si el catálogo falla, el directorio sigue funcionando sin él.
    }
});

async function invite(driver) {
    invitingId.value = driver.user_id;
    try {
        await inviteDriver(targetFleetId.value, driver.user_id);
        driver.status = 'pending';
    } catch (e) {
        error.value = e.message || 'No se pudo enviar la invitación.';
    } finally {
        invitingId.value = null;
    }
}
</script>

<template>
    <MobileShell v-if="user" :role="user.role" :user-name="user.name">
        <main class="mobile-page directory-page">
            <button class="back-link" @click="router.back()">‹ Volver</button>

            <section class="welcome">
                <p class="mobile-eyebrow">Red de respaldo</p>
                <h1 class="mobile-title">Directorio de conductores</h1>
                <p class="welcome-copy">Conductores públicos ordenados por medalla y calificación.</p>
            </section>

            <div v-if="cities.length" class="sector-filter">
                <label for="sector_id">Filtrar por sector</label>
                <select id="sector_id" v-model="selectedSectorId" @change="onSectorChange">
                    <option value="">Todos los sectores</option>
                    <optgroup v-for="city in cities" :key="city.id" :label="city.name">
                        <option v-for="sector in city.sectors" :key="sector.id" :value="sector.id">{{ sector.name }}</option>
                    </optgroup>
                </select>
            </div>

            <div v-if="loading" class="loading-row"><span class="mobile-spinner"></span><span>Cargando…</span></div>
            <p v-else-if="error" class="mobile-alert">{{ error }}</p>
            <p v-else-if="!drivers.length" class="empty-copy">Todavía no hay conductores públicos disponibles.</p>

            <article v-for="driver in drivers" :key="driver.user_id" class="driver-card mobile-card">
                <img v-if="driver.avatar_url" :src="driver.avatar_url" :alt="driver.name" class="driver-avatar" />
                <div v-else class="driver-avatar driver-avatar--placeholder">{{ driver.name?.[0] }}</div>

                <div class="driver-info">
                    <strong>{{ driver.name }} <span class="tier-emoji">{{ driver.tier.badge_emoji }}</span></strong>
                    <small>{{ driver.vehicle_type }}<span v-if="driver.cooperative"> · {{ driver.cooperative.name }}</span></small>
                    <small class="driver-meta">
                        <span v-if="driver.average_rating">★ {{ driver.average_rating }} ({{ driver.review_count }})</span>
                        <span :class="['availability', { 'is-available': driver.is_available }]">{{ driver.is_available ? 'Disponible' : 'No disponible' }}</span>
                    </small>
                    <small v-if="driver.coverage_sectors?.length" class="driver-zones">
                        Trabaja en: {{ driver.coverage_sectors.map((s) => s.name).join(', ') }}
                    </small>
                </div>

                <button
                    v-if="!statusLabel[driver.status]"
                    class="invite-button"
                    :disabled="invitingId === driver.user_id"
                    @click="invite(driver)"
                >
                    {{ invitingId === driver.user_id ? '…' : 'Invitar' }}
                </button>
                <span v-else class="status-chip">{{ statusLabel[driver.status] }}</span>
            </article>
        </main>
    </MobileShell>

    <main v-else-if="loading" class="loading-screen"><span class="mobile-spinner"></span><p>Cargando…</p></main>
</template>

<style scoped>
.directory-page { display: grid; gap: 1.15rem; }
.back-link { justify-self: start; border: 0; background: transparent; color: var(--arka-muted); font-size: .85rem; font-weight: 700; padding: .35rem 0; }
.welcome { padding: 0 .25rem; }
.welcome-copy { margin: .5rem 0 0; color: var(--arka-muted); font-size: .85rem; line-height: 1.5; }
.sector-filter { display: grid; gap: .3rem; padding: 0 .25rem; }
.sector-filter label { font-size: .78rem; font-weight: 700; color: var(--arka-muted); }
.sector-filter select { min-height: 2.4rem; padding: 0 .6rem; border-radius: .7rem; border: 1px solid var(--arka-border, #e2e2e2); background: var(--arka-card, #fff); color: inherit; font-size: .85rem; }
.loading-row { display: flex; align-items: center; gap: .6rem; padding: 1rem .25rem; color: var(--arka-muted); font-size: .88rem; }
.empty-copy { margin: 0; padding: .25rem; color: var(--arka-muted); font-size: .85rem; }
.driver-card { padding: .9rem 1rem; display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: .8rem; }
.driver-avatar { width: 2.8rem; height: 2.8rem; border-radius: 999px; object-fit: cover; }
.driver-avatar--placeholder { display: grid; place-items: center; background: var(--arka-primary-soft); color: var(--arka-primary); font-weight: 800; }
.driver-info { display: grid; gap: .2rem; min-width: 0; }
.driver-info strong { font-size: .9rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tier-emoji { font-size: .85rem; }
.driver-info small { color: var(--arka-muted); font-size: .72rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.driver-meta { display: flex; gap: .5rem; }
.driver-zones { white-space: normal; }
.availability.is-available { color: var(--arka-primary); }
.invite-button { flex: none; min-height: 2.3rem; padding: 0 .8rem; border: 1px solid var(--arka-primary); border-radius: .7rem; background: transparent; color: var(--arka-primary); font-size: .78rem; font-weight: 700; }
.status-chip { flex: none; font-size: .68rem; color: var(--arka-muted); text-align: right; max-width: 6.5rem; }
.loading-screen { min-height: 100dvh; display: grid; place-content: center; justify-items: center; gap: .75rem; color: var(--arka-muted); }
</style>
