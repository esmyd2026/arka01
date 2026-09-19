<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { fetchSavedRoutes, addSavedRoute, deleteSavedRoute } from '../services/savedRoutes';
import { getStoredUser } from '../services/auth';
import MobileShell from '../components/MobileShell.vue';
import AddressAutocomplete from '../components/AddressAutocomplete.vue';

const router = useRouter();
const user = ref(null);
const loading = ref(true);
const error = ref(null);
const routes = ref([]);
const deletingId = ref(null);

const showForm = ref(false);
const alias = ref('');
const originAddress = ref('');
const origin = ref(null);
const destinationAddress = ref('');
const destination = ref(null);
const saving = ref(false);
const formError = ref(null);

async function load() {
    loading.value = true;
    try {
        routes.value = await fetchSavedRoutes();
    } catch (e) {
        error.value = e.message || 'No se pudieron cargar tus rutas.';
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

function openForm() {
    alias.value = '';
    originAddress.value = '';
    origin.value = null;
    destinationAddress.value = '';
    destination.value = null;
    formError.value = null;
    showForm.value = true;
}

async function save() {
    if (!origin.value || !destination.value) {
        formError.value = 'Elige el origen y el destino de la lista de sugerencias.';
        return;
    }

    saving.value = true;
    formError.value = null;
    try {
        const route = await addSavedRoute({
            alias: alias.value || null,
            origin_lat: origin.value.lat,
            origin_lng: origin.value.lng,
            origin_address: origin.value.address,
            destination_lat: destination.value.lat,
            destination_lng: destination.value.lng,
            destination_address: destination.value.address,
        });
        routes.value = [route, ...routes.value];
        showForm.value = false;
    } catch (e) {
        formError.value = e.message || 'No se pudo guardar la ruta.';
    } finally {
        saving.value = false;
    }
}

async function remove(route) {
    deletingId.value = route.id;
    try {
        await deleteSavedRoute(route.id);
        routes.value = routes.value.filter((r) => r.id !== route.id);
    } catch (e) {
        error.value = e.message || 'No se pudo eliminar la ruta.';
    } finally {
        deletingId.value = null;
    }
}
</script>

<template>
    <MobileShell v-if="user" :role="user.role" :user-name="user.name">
        <main class="mobile-page routes-page">
            <button class="back-link" @click="router.back()">‹ Volver</button>

            <section class="welcome">
                <p class="mobile-eyebrow">Pedir carrera</p>
                <h1 class="mobile-title">Mis rutas</h1>
                <p class="welcome-copy">Guarda tus trayectos frecuentes para pedirlos más rápido.</p>
            </section>

            <div v-if="loading" class="loading-row"><span class="mobile-spinner"></span><span>Cargando…</span></div>
            <p v-else-if="error" class="mobile-alert">{{ error }}</p>

            <template v-else>
                <p v-if="!routes.length" class="empty-copy">Todavía no guardas ninguna ruta.</p>

                <article v-for="route in routes" :key="route.id" class="route-card mobile-card">
                    <div class="route-info">
                        <strong>{{ route.alias || 'Ruta guardada' }}</strong>
                        <small>{{ route.origin_address || 'Origen' }} → {{ route.destination_address || 'Destino' }}</small>
                    </div>
                    <button class="remove-button" :disabled="deletingId === route.id" @click="remove(route)">
                        {{ deletingId === route.id ? '…' : 'Quitar' }}
                    </button>
                </article>

                <button class="mobile-button add-button" @click="openForm">+ Guardar nueva ruta</button>
            </template>
        </main>

        <div v-if="showForm" class="sheet-backdrop" @click.self="showForm = false">
            <section class="add-sheet mobile-card">
                <span class="sheet-handle"></span>
                <h2>Nueva ruta guardada</h2>
                <label class="mobile-field"><span>Nombre (opcional)</span><input v-model="alias" class="mobile-input" placeholder="Casa, trabajo…" /></label>
                <label class="mobile-field">
                    <span>Origen</span>
                    <AddressAutocomplete v-model="originAddress" placeholder="Escribe el origen" @place-selected="origin = $event" @clear="origin = null" />
                </label>
                <label class="mobile-field">
                    <span>Destino</span>
                    <AddressAutocomplete v-model="destinationAddress" placeholder="Escribe el destino" @place-selected="destination = $event" @clear="destination = null" />
                </label>
                <p v-if="formError" class="mobile-alert">{{ formError }}</p>
                <button class="mobile-button" :disabled="saving" @click="save">{{ saving ? 'Guardando…' : 'Guardar ruta' }}</button>
                <button class="cancel-button" @click="showForm = false">Cancelar</button>
            </section>
        </div>
    </MobileShell>

    <main v-else-if="loading" class="loading-screen"><span class="mobile-spinner"></span><p>Cargando…</p></main>
</template>

<style scoped>
.routes-page { display: grid; gap: 1.15rem; }
.back-link { justify-self: start; border: 0; background: transparent; color: var(--arka-muted); font-size: .85rem; font-weight: 700; padding: .35rem 0; }
.welcome { padding: 0 .25rem; }
.welcome-copy { margin: .5rem 0 0; color: var(--arka-muted); font-size: .85rem; line-height: 1.5; }
.loading-row { display: flex; align-items: center; gap: .6rem; padding: 1rem .25rem; color: var(--arka-muted); font-size: .88rem; }
.empty-copy { margin: 0; padding: .25rem; color: var(--arka-muted); font-size: .85rem; }
.route-card { padding: .9rem 1rem; display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
.route-info { display: grid; gap: .15rem; min-width: 0; }
.route-info strong { font-size: .9rem; }
.route-info small { color: var(--arka-muted); font-size: .74rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block; }
.remove-button { flex: none; border: 0; background: transparent; color: var(--arka-danger); font-size: .78rem; font-weight: 700; padding: .4rem .2rem; }
.add-button { margin-top: .2rem; }
.sheet-backdrop { position: fixed; z-index: 100; inset: 0; display: flex; align-items: flex-end; background: rgba(0,0,0,.7); }
.add-sheet { width: 100%; max-width: 560px; margin: 0 auto; padding: .6rem 1rem calc(1rem + env(safe-area-inset-bottom)); border-radius: 1.5rem 1.5rem 0 0; display: grid; gap: .7rem; }
.sheet-handle { width: 2.5rem; height: .25rem; margin: 0 auto .3rem; display: block; border-radius: 1rem; background: rgba(147,173,162,.3); }
.add-sheet h2 { margin: 0; }
.cancel-button { width: 100%; text-align: center; border: 0; background: transparent; color: var(--arka-muted); min-height: 2.5rem; }
.loading-screen { min-height: 100dvh; display: grid; place-content: center; justify-items: center; gap: .75rem; color: var(--arka-muted); }
</style>
