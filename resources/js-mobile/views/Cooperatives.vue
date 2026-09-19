<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { fetchCooperatives, attachCooperative, detachCooperative } from '../services/cooperatives';
import { getStoredUser } from '../services/auth';
import MobileShell from '../components/MobileShell.vue';

const router = useRouter();
const user = ref(null);
const loading = ref(true);
const error = ref(null);
const cooperatives = ref([]);
const search = ref('');
const workingId = ref(null);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        cooperatives.value = await fetchCooperatives(search.value);
    } catch (e) {
        error.value = e.message || 'No se pudo cargar el directorio.';
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    user.value = await getStoredUser();
    if (user.value?.role === 'conductor') {
        router.replace({ name: 'home' });
        return;
    }
    await load();
});

async function toggle(cooperative) {
    workingId.value = cooperative.id;
    error.value = null;
    try {
        if (cooperative.is_attached) {
            await detachCooperative(cooperative.id);
            cooperative.is_attached = false;
        } else {
            await attachCooperative(cooperative.id);
            cooperative.is_attached = true;
        }
    } catch (e) {
        error.value = e.message || 'No se pudo actualizar la cooperativa.';
    } finally {
        workingId.value = null;
    }
}
</script>

<template>
    <MobileShell v-if="user" :role="user.role" :user-name="user.name">
        <main class="mobile-page coop-page">
            <button class="back-link" @click="router.back()">‹ Volver</button>

            <section class="welcome">
                <p class="mobile-eyebrow">Red de confianza</p>
                <h1 class="mobile-title">Cooperativas</h1>
                <p class="welcome-copy">Guarda las cooperativas que quieras poder elegir al pedir una carrera.</p>
            </section>

            <form class="search-row" @submit.prevent="load">
                <input v-model="search" class="mobile-input" placeholder="Buscar por nombre o ciudad" />
                <button class="mobile-button search-button" type="submit">Buscar</button>
            </form>

            <div v-if="loading" class="loading-row"><span class="mobile-spinner"></span><span>Cargando…</span></div>
            <p v-else-if="error" class="mobile-alert">{{ error }}</p>
            <p v-else-if="!cooperatives.length" class="empty-copy">No se encontraron cooperativas.</p>

            <article v-for="coop in cooperatives" :key="coop.id" class="coop-card mobile-card">
                <img v-if="coop.logo_url" :src="coop.logo_url" alt="" class="coop-logo" />
                <div v-else class="coop-logo coop-logo--placeholder">{{ coop.name?.[0] }}</div>

                <div class="coop-info">
                    <strong>{{ coop.name }}</strong>
                    <small>{{ coop.city || 'Sin ciudad registrada' }}<span v-if="coop.coverage"> · {{ coop.coverage }}</span></small>
                    <small class="coop-meta">
                        <span v-if="coop.average_rating">★ {{ coop.average_rating }} ({{ coop.review_count }})</span>
                        <span>{{ coop.driver_count }} conductores</span>
                    </small>
                </div>

                <button
                    class="attach-button"
                    :class="{ 'is-attached': coop.is_attached }"
                    :disabled="workingId === coop.id"
                    @click="toggle(coop)"
                >
                    {{ workingId === coop.id ? '…' : coop.is_attached ? 'Quitar' : 'Agregar' }}
                </button>
            </article>
        </main>
    </MobileShell>

    <main v-else-if="loading" class="loading-screen"><span class="mobile-spinner"></span><p>Cargando…</p></main>
</template>

<style scoped>
.coop-page { display: grid; gap: 1.15rem; }
.back-link { justify-self: start; border: 0; background: transparent; color: var(--arka-muted); font-size: .85rem; font-weight: 700; padding: .35rem 0; }
.welcome { padding: 0 .25rem; }
.welcome-copy { margin: .5rem 0 0; color: var(--arka-muted); font-size: .85rem; line-height: 1.5; }
.search-row { display: grid; grid-template-columns: 1fr auto; gap: .5rem; }
.search-button { width: auto; padding: 0 1.1rem; }
.loading-row { display: flex; align-items: center; gap: .6rem; padding: 1rem .25rem; color: var(--arka-muted); font-size: .88rem; }
.empty-copy { margin: 0; padding: .25rem; color: var(--arka-muted); font-size: .85rem; }
.coop-card { padding: .9rem 1rem; display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: .8rem; }
.coop-logo { width: 2.8rem; height: 2.8rem; border-radius: .8rem; object-fit: cover; }
.coop-logo--placeholder { display: grid; place-items: center; background: var(--arka-primary-soft); color: var(--arka-primary); font-weight: 800; }
.coop-info { display: grid; gap: .18rem; min-width: 0; }
.coop-info strong { font-size: .9rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.coop-info small { color: var(--arka-muted); font-size: .72rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.coop-meta { display: flex; gap: .5rem; }
.attach-button { flex: none; min-height: 2.3rem; padding: 0 .8rem; border: 1px solid var(--arka-primary); border-radius: .7rem; background: transparent; color: var(--arka-primary); font-size: .76rem; font-weight: 700; }
.attach-button.is-attached { background: var(--arka-primary-soft); }
.loading-screen { min-height: 100dvh; display: grid; place-content: center; justify-items: center; gap: .75rem; color: var(--arka-muted); }
</style>
