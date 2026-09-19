<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import {
    fetchTrustCircle,
    searchTrustCircle,
    sendTrustCircleRequest,
    respondTrustCircleRequest,
    removeTrustCircleConnection,
    inviteRecommendedDriver,
} from '../services/trustCircle';
import { getStoredUser } from '../services/auth';
import MobileShell from '../components/MobileShell.vue';

const router = useRouter();
const user = ref(null);
const loading = ref(true);
const error = ref(null);
const notice = ref(null);

const data = ref(null);
const searchTerm = ref('');
const searchResults = ref([]);
const searching = ref(false);
const workingId = ref(null);

async function load() {
    try {
        data.value = await fetchTrustCircle();
    } catch (e) {
        error.value = e.message || 'No se pudo cargar tu círculo de confianza.';
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    user.value = await getStoredUser();
    await load();
});

async function runSearch() {
    if (searchTerm.value.trim().length < 2) return;
    searching.value = true;
    try {
        searchResults.value = await searchTrustCircle(searchTerm.value.trim());
    } catch (e) {
        error.value = e.message || 'No se pudo buscar.';
    } finally {
        searching.value = false;
    }
}

async function sendRequest(person) {
    workingId.value = person.user_public_id;
    error.value = null;
    try {
        await sendTrustCircleRequest(person.user_public_id);
        person.connection_status = 'pending';
        notice.value = `Solicitud enviada a ${person.name}.`;
    } catch (e) {
        error.value = e.message || 'No se pudo enviar la solicitud.';
    } finally {
        workingId.value = null;
    }
}

async function respond(request, action) {
    workingId.value = request.connection_public_id;
    error.value = null;
    try {
        await respondTrustCircleRequest(request.connection_public_id, action);
        await load();
    } catch (e) {
        error.value = e.message || 'No se pudo responder la solicitud.';
    } finally {
        workingId.value = null;
    }
}

async function remove(person) {
    workingId.value = person.connection_public_id;
    error.value = null;
    try {
        await removeTrustCircleConnection(person.connection_public_id);
        await load();
    } catch (e) {
        error.value = e.message || 'No se pudo eliminar la conexión.';
    } finally {
        workingId.value = null;
    }
}

async function inviteDriver(driver) {
    workingId.value = driver.driver_public_id;
    error.value = null;
    try {
        await inviteRecommendedDriver(driver.driver_public_id);
        notice.value = `Invitación enviada a ${driver.name}.`;
    } catch (e) {
        error.value = e.message || 'No se pudo enviar la invitación.';
    } finally {
        workingId.value = null;
    }
}
</script>

<template>
    <MobileShell v-if="user" :role="user.role" :user-name="user.name">
        <main class="mobile-page circle-page">
            <button class="back-link" @click="router.back()">‹ Volver</button>

            <section class="welcome">
                <p class="mobile-eyebrow">Tu red</p>
                <h1 class="mobile-title">Círculo de confianza</h1>
                <p class="welcome-copy">Familiares y amigos con quienes compartes tu flota o calificación.</p>
            </section>

            <div v-if="loading" class="loading-row"><span class="mobile-spinner"></span><span>Cargando…</span></div>
            <p v-else-if="error" class="mobile-alert">{{ error }}</p>

            <template v-else-if="data">
                <div class="totals-grid">
                    <div class="total-tile mobile-card"><small>Personas</small><strong>{{ data.summary.people }}</strong></div>
                    <div class="total-tile mobile-card"><small>Confianza</small><strong>{{ data.summary.own_trust.score }}</strong></div>
                </div>

                <section class="search-card mobile-card">
                    <p class="mobile-eyebrow">Agregar a alguien</p>
                    <form class="search-row" @submit.prevent="runSearch">
                        <input v-model="searchTerm" class="mobile-input" placeholder="Nombre, usuario o código de socio" />
                        <button class="mobile-button search-button" type="submit">{{ searching ? '…' : 'Buscar' }}</button>
                    </form>
                    <p v-if="notice" class="notice-copy">{{ notice }}</p>
                    <div v-for="person in searchResults" :key="person.user_public_id" class="result-row">
                        <span><strong>{{ person.name }}</strong><small>@{{ person.username }} · {{ person.role }}</small></span>
                        <button
                            v-if="!person.connection_status"
                            class="mini-button accept"
                            :disabled="workingId === person.user_public_id"
                            @click="sendRequest(person)"
                        >Enviar</button>
                        <span v-else class="status-chip">{{ person.connection_status === 'pending' ? 'Pendiente' : 'Ya conectado' }}</span>
                    </div>
                </section>

                <section v-if="data.receivedRequests.length">
                    <div class="section-heading"><p class="mobile-eyebrow">Solicitudes</p><h2>Te invitaron</h2></div>
                    <div v-for="request in data.receivedRequests" :key="request.connection_public_id" class="result-row mobile-card">
                        <span><strong>{{ request.name }}</strong><small>{{ request.relationship_label || request.role }}</small></span>
                        <div class="actions">
                            <button class="mini-button accept" :disabled="workingId === request.connection_public_id" @click="respond(request, 'accept')">Aceptar</button>
                            <button class="mini-button reject" :disabled="workingId === request.connection_public_id" @click="respond(request, 'reject')">Rechazar</button>
                        </div>
                    </div>
                </section>

                <section v-if="data.sentRequests.length">
                    <div class="section-heading"><p class="mobile-eyebrow">Solicitudes</p><h2>Esperando respuesta</h2></div>
                    <div v-for="request in data.sentRequests" :key="request.connection_public_id" class="result-row mobile-card">
                        <span><strong>{{ request.name }}</strong><small>Enviada, esperando que acepte</small></span>
                    </div>
                </section>

                <section v-if="data.people.length">
                    <div class="section-heading"><p class="mobile-eyebrow">Tu círculo</p><h2>Personas conectadas</h2></div>
                    <div v-for="person in data.people" :key="person.connection_public_id" class="person-row mobile-card">
                        <span class="person-info">
                            <strong>{{ person.name }}</strong>
                            <small>{{ person.relationship_label || person.role }}</small>
                            <small v-if="person.trust">Confianza: {{ person.trust.score }}</small>
                        </span>
                        <button class="mini-button reject" :disabled="workingId === person.connection_public_id" @click="remove(person)">Quitar</button>
                    </div>
                </section>
                <p v-else class="empty-copy">Todavía no tienes a nadie en tu círculo — buscá a alguien arriba para empezar.</p>

                <section v-if="data.recommendedDrivers?.length">
                    <div class="section-heading"><p class="mobile-eyebrow">Recomendados</p><h2>Conductores de tu círculo</h2></div>
                    <div v-for="driver in data.recommendedDrivers" :key="driver.driver_public_id" class="person-row mobile-card">
                        <span class="person-info">
                            <strong>{{ driver.name }}</strong>
                            <small>Recomendado por {{ driver.recommended_by_count }} persona(s)</small>
                        </span>
                        <button class="mini-button accept" :disabled="workingId === driver.driver_public_id" @click="inviteDriver(driver)">Invitar</button>
                    </div>
                </section>
            </template>
        </main>
    </MobileShell>

    <main v-else-if="loading" class="loading-screen"><span class="mobile-spinner"></span><p>Cargando…</p></main>
</template>

<style scoped>
.circle-page { display: grid; gap: 1.15rem; }
.back-link { justify-self: start; border: 0; background: transparent; color: var(--arka-muted); font-size: .85rem; font-weight: 700; padding: .35rem 0; }
.welcome { padding: 0 .25rem; }
.welcome-copy { margin: .5rem 0 0; color: var(--arka-muted); font-size: .85rem; line-height: 1.5; }
.loading-row { display: flex; align-items: center; gap: .6rem; padding: 1rem .25rem; color: var(--arka-muted); font-size: .88rem; }
.empty-copy { margin: 0; padding: .25rem; color: var(--arka-muted); font-size: .85rem; }
.totals-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .7rem; }
.total-tile { padding: .85rem .95rem; display: grid; gap: .25rem; }
.total-tile small { color: var(--arka-muted); font-size: .7rem; text-transform: uppercase; }
.total-tile strong { font-size: 1.15rem; }
.search-card { padding: 1.1rem; display: grid; gap: .6rem; }
.search-row { display: grid; grid-template-columns: 1fr auto; gap: .5rem; }
.search-button { width: auto; padding: 0 1.1rem; }
.notice-copy { margin: 0; color: var(--arka-primary); font-size: .78rem; font-weight: 700; }
.result-row { padding: .6rem 0; display: flex; align-items: center; justify-content: space-between; gap: .6rem; border-bottom: 1px solid rgba(147,173,162,.1); }
.result-row:last-child { border-bottom: none; }
.result-row strong { font-size: .85rem; display: block; }
.result-row small { color: var(--arka-muted); font-size: .72rem; }
.section-heading { margin: .2rem .2rem .65rem; }
.section-heading h2 { margin: .25rem 0 0; font-size: 1.1rem; letter-spacing: -.02em; }
.person-row { margin-bottom: .6rem; padding: .85rem 1rem; display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
.person-info { display: grid; gap: .15rem; min-width: 0; }
.person-info strong { font-size: .88rem; }
.person-info small { color: var(--arka-muted); font-size: .72rem; }
.actions { display: flex; gap: .4rem; flex: none; }
.mini-button { min-height: 2.1rem; padding: 0 .7rem; border-radius: .6rem; font-size: .72rem; font-weight: 700; border: 1px solid transparent; flex: none; }
.mini-button.accept { background: var(--arka-primary); color: #062016; }
.mini-button.reject { background: transparent; border-color: var(--arka-danger); color: var(--arka-danger); }
.status-chip { flex: none; font-size: .68rem; color: var(--arka-muted); }
.loading-screen { min-height: 100dvh; display: grid; place-content: center; justify-items: center; gap: .75rem; color: var(--arka-muted); }
</style>
