<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { fetchFleet, searchDrivers, inviteDriver, removeMember, createFleet, cancelInvitation } from '../services/fleet';
import { getStoredUser } from '../services/auth';
import MobileShell from '../components/MobileShell.vue';

const router = useRouter();
const fleets = ref([]);
const loading = ref(true);
const error = ref(null);
const user = ref(null);
const limits = ref({ max_fleets: null, max_drivers_per_fleet: null, plan_name: '' });
const selectedFleetId = ref(null);
const newFleetName = ref('');
const creatingFleet = ref(false);
const cancelingInvitationId = ref(null);
const selectedFleet = computed(() => fleets.value.find(fleet => fleet.id === selectedFleetId.value) || fleets.value[0]);
const canCreateFleet = computed(() => limits.value.max_fleets === null || fleets.value.length < limits.value.max_fleets);

// Buscador de conductores para invitar — uno solo a la vez, contra la
// primera flota (igual que el plan Gratis, el caso más común: una sola
// flota). Si más adelante hay multi-flota en la app, esto necesita un
// selector de a cuál flota invitar.
//
// Asincrónico (pedido explícito del usuario): busca solo mientras se
// escribe, con debounce, sin botón "Buscar" — mismo patrón que
// components/AddressAutocomplete.vue (debounce de 300ms + un id de
// pedido para descartar una respuesta vieja que llegue después de una
// más nueva, si las dos quedaron en vuelo a la vez).
const query = ref('');
const searching = ref(false);
const searchError = ref(null);
const results = ref(null);
const invitingUserId = ref(null);
const removingMemberId = ref(null);
const actionError = ref(null);

let debounceTimer = null;
let searchRequestId = 0;

async function load() {
    try {
        const data = await fetchFleet();
        fleets.value = data.fleets;
        selectedFleetId.value ||= data.fleets[0]?.id ?? null;
        limits.value = data;
    } catch (e) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

onMounted(async () => { user.value = await getStoredUser(); await load(); });
onBeforeUnmount(() => clearTimeout(debounceTimer));

function onQueryInput() {
    clearTimeout(debounceTimer);
    searchError.value = null;

    const term = query.value.trim();
    if (!term) {
        results.value = null;
        searching.value = false;
        return;
    }

    if (term.length < 2) {
        results.value = null;
        return;
    }

    debounceTimer = setTimeout(runSearch, 300);
}

async function runSearch() {
    const requestId = ++searchRequestId;
    searching.value = true;

    try {
        const data = await searchDrivers(selectedFleet.value.id, query.value.trim());
        if (requestId !== searchRequestId) return;
        results.value = data;
        searchError.value = null;
    } catch (e) {
        if (requestId !== searchRequestId) return;
        searchError.value = e.message;
    } finally {
        if (requestId === searchRequestId) searching.value = false;
    }
}

async function invite(driver) {
    invitingUserId.value = driver.user_id;
    actionError.value = null;
    try {
        await inviteDriver(selectedFleet.value.id, driver.user_id);
        driver.status = 'pending';
        await load();
    } catch (e) {
        actionError.value = e.message;
    } finally {
        invitingUserId.value = null;
    }
}

async function remove(member) {
    removingMemberId.value = member.member_id;
    actionError.value = null;
    try {
        await removeMember(member.member_id);
        await load();
    } catch (e) {
        actionError.value = e.message;
    } finally {
        removingMemberId.value = null;
    }
}

async function addFleet() {
    if (!newFleetName.value.trim()) return;
    creatingFleet.value = true; actionError.value = null;
    try {
        const fleet = await createFleet(newFleetName.value.trim());
        newFleetName.value = '';
        await load();
        selectedFleetId.value = fleet.id;
    } catch (e) { actionError.value = e.message; }
    finally { creatingFleet.value = false; }
}

async function cancelInvitationAction(invitation) {
    cancelingInvitationId.value = invitation.id; actionError.value = null;
    try { await cancelInvitation(invitation.id); await load(); }
    catch (e) { actionError.value = e.message; }
    finally { cancelingInvitationId.value = null; }
}

function requestRide(member) {
    sessionStorage.setItem('arka-preselected-driver', JSON.stringify({ ...member, user_id: member.driver_user_id }));
    router.push({ name: 'request-ride' });
}

async function recommend(member) {
    const text = `Te recomiendo a ${member.name}, conductor de confianza en Arka01.`;
    if (navigator.share) await navigator.share({ title: 'Conductor recomendado', text });
    else await navigator.clipboard?.writeText(text);
}
</script>

<template>
    <MobileShell role="cliente" :user-name="user?.name">
    <main class="mobile-page fleet-page">
        <header class="page-heading"><p class="mobile-eyebrow">Tu red de confianza</p><h1 class="mobile-title">Mi flota</h1><p>Administra los conductores a quienes puedes pedirles una carrera directamente.</p></header>

        <p v-if="loading" class="loading">Cargando…</p>
        <p v-else-if="error" class="error">{{ error }}</p>

        <template v-else>
            <section class="plan-summary mobile-card"><div><p class="mobile-eyebrow">Plan {{ limits.plan_name }}</p><strong>{{ fleets.length }} de {{ limits.max_fleets ?? '∞' }} flotas</strong></div><button @click="router.push({ name: 'plan' })">Ver plan</button></section>

            <div v-if="fleets.length > 1" class="fleet-tabs" role="tablist"><button v-for="fleet in fleets" :key="fleet.id" :class="{ active: selectedFleetId === fleet.id }" @click="selectedFleetId = fleet.id">{{ fleet.name }}</button></div>

            <section class="search mobile-card">
                <div class="section-title"><div><p class="mobile-eyebrow">Agregar</p><h2>Buscar conductor</h2></div></div>
                <p class="section-copy">Encuéntralo por su código de socio, nombre o usuario.</p>
                <div class="search-form">
                    <input class="mobile-input"
                        v-model="query"
                        type="text"
                        placeholder="Código de socio, nombre o usuario"
                        autocomplete="off"
                        @input="onQueryInput"
                    />
                    <span v-if="searching" class="searching-indicator">Buscando…</span>
                </div>
                <p v-if="searchError" class="error">{{ searchError }}</p>
                <p v-if="actionError" class="error">{{ actionError }}</p>

                <ul v-if="results" class="results">
                    <li v-if="results.length === 0" class="empty">No se encontró ningún conductor.</li>
                    <li v-for="driver in results" :key="driver.user_id" class="result">
                        <div class="result-main">
                            <strong>{{ driver.name }}</strong>
                            <span v-if="driver.member_code" class="member-code">#{{ driver.member_code }}</span>
                        </div>
                        <button
                            v-if="driver.status === 'not_invited'"
                            class="invite-btn"
                            :disabled="invitingUserId === driver.user_id"
                            @click="invite(driver)"
                        >
                            {{ invitingUserId === driver.user_id ? 'Invitando…' : 'Invitar' }}
                        </button>
                        <span v-else-if="driver.status === 'pending'" class="status-tag">Invitación pendiente</span>
                        <span v-else class="status-tag">Ya es de la flota</span>
                    </li>
                </ul>
            </section>

            <section v-if="canCreateFleet" class="create-fleet mobile-card"><div><p class="mobile-eyebrow">Nueva flota</p><h2>Organiza otro grupo</h2></div><div><input v-model="newFleetName" class="mobile-input" placeholder="Ej. Conductores del trabajo" maxlength="100"/><button :disabled="creatingFleet || !newFleetName.trim()" @click="addFleet">{{ creatingFleet ? 'Creando…' : 'Crear' }}</button></div></section>

            <section v-if="selectedFleet" class="fleet mobile-card">
                <template v-for="fleet in [selectedFleet]" :key="fleet.id">
                <div class="fleet-title"><div><p class="mobile-eyebrow">Conductores</p><h2>{{ fleet.name }}</h2></div><span>{{ fleet.members.length }}</span></div>

                <p v-if="fleet.members.length === 0 && fleet.pending_invitations.length === 0" class="empty">
                    Todavía no tenés conductores. Buscalos por código de socio o nombre para invitarlos.
                </p>

                <ul class="members">
                    <li v-for="member in fleet.members" :key="member.member_id" class="member">
                        <div class="member-head"><img v-if="member.avatar_url" :src="member.avatar_url" alt=""/><span v-else class="member-avatar">{{ member.name?.[0] }}</span><div class="member-main"><strong>{{ member.name }}</strong><small>{{ member.tier_label || 'Conductor de confianza' }}</small><span class="dot" :class="member.is_available ? 'online' : 'offline'"></span></div></div>
                        <p class="member-meta">
                            <span v-if="member.average_rating">★ {{ member.average_rating }} ({{ member.review_count }})</span>
                            <span v-else>Sin calificaciones</span>
                            · {{ member.rides_count }} carreras
                            <span v-if="member.rate_per_km"> · ${{ member.rate_per_km }}/km</span>
                        </p>
                        <div class="member-actions"><button class="ride-btn" @click="requestRide(member)">Pedir carrera</button><button class="recommend-btn" @click="recommend(member)">Recomendar</button></div>
                        <details class="member-menu"><summary>Más opciones</summary><button class="remove-btn" :disabled="removingMemberId === member.member_id" @click="remove(member)">{{ removingMemberId === member.member_id ? 'Quitando…' : 'Quitar de la flota' }}</button></details>
                    </li>
                </ul>

                <ul v-if="fleet.pending_invitations.length" class="pending">
                    <li v-for="invitation in fleet.pending_invitations" :key="invitation.id"><span>{{ invitation.driver_name }}<small>Invitación pendiente</small></span><button :disabled="cancelingInvitationId === invitation.id" @click="cancelInvitationAction(invitation)">{{ cancelingInvitationId === invitation.id ? 'Cancelando…' : 'Cancelar' }}</button>
                    </li>
                </ul>
                </template>
            </section>
        </template>
    </main>
    </MobileShell>
</template>

<style scoped>
.fleet-page { display: grid; gap: 1rem; }.page-heading > p:last-child { margin: .5rem 0 0; color: var(--arka-muted); font-size: .8rem; line-height: 1.45; }.page-heading h1 { margin-top: .25rem; }h2 { margin: .25rem 0 0; font-size: 1rem; }.loading { color: var(--arka-muted); }.error { color: var(--arka-danger); font-size: .78rem; }.search, .fleet, .create-fleet { padding: 1rem; }.section-copy { margin: .55rem 0 .8rem; color: var(--arka-muted); font-size: .73rem; line-height: 1.45; }
.plan-summary{padding:.9rem 1rem;display:flex;align-items:center;justify-content:space-between}.plan-summary strong{font-size:.9rem}.plan-summary button{border:0;background:transparent;color:var(--arka-primary);font-size:.75rem;font-weight:800}.fleet-tabs{display:flex;gap:.45rem;overflow:auto}.fleet-tabs button{padding:.55rem .85rem;border:1px solid var(--arka-border);border-radius:999px;background:var(--arka-card);color:var(--arka-muted);white-space:nowrap}.fleet-tabs button.active{border-color:var(--arka-primary);background:var(--arka-primary-soft);color:var(--arka-primary)}
.create-fleet>div:last-child{margin-top:.75rem;display:grid;grid-template-columns:1fr auto;gap:.5rem}.create-fleet button{padding:0 .9rem;border:0;border-radius:.8rem;background:var(--arka-primary);color:#fff;font-weight:800}
.search-form {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.search-form input { flex: 1; }
.searching-indicator {
    color: var(--arka-primary);
    font-size: 0.8rem;
    white-space: nowrap;
}
.results {
    list-style: none;
    padding: 0;
    margin: .75rem 0 0;
}
.result {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: .7rem 0;
    border-bottom: 1px solid var(--arka-border);
}
.result-main {
    display: flex;
    flex-direction: column;
    font-size: 0.9rem;
}
.member-code {
    color: var(--arka-muted);
    font-size: 0.8rem;
}
.invite-btn {
    padding: 0.4rem 0.75rem;
    border: none;
    border-radius: .75rem;
    background: var(--arka-primary);
    color: var(--arka-on-primary);
    font-size: 0.85rem;
    white-space: nowrap;
}
.invite-btn:disabled {
    opacity: 0.6;
}
.status-tag {
    color: var(--arka-muted);
    font-size: 0.8rem;
    white-space: nowrap;
}
.fleet-title { display: flex; align-items: center; justify-content: space-between; margin-bottom: .55rem; }.fleet-title > span { min-width: 2rem; height: 1.7rem; padding: 0 .45rem; display: grid; place-items: center; border-radius: 999px; background: var(--arka-primary-soft); color: var(--arka-primary); font-size: .7rem; font-weight: 800; }
.empty {
    color: var(--arka-muted);
    font-size: 0.9rem;
}
.members,
.pending {
    list-style: none;
    padding: 0;
    margin: 0;
}
.member {
    padding: .85rem 0;
    border-bottom: 1px solid var(--arka-border);
}
.member-main {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.member-head{display:flex;align-items:center;gap:.7rem}.member-head img,.member-avatar{width:2.75rem;height:2.75rem;border-radius:50%;object-fit:cover}.member-avatar{display:grid;place-items:center;background:var(--arka-primary-soft);color:var(--arka-primary);font-weight:900}.member-main{position:relative;flex:1;display:grid;gap:.15rem}.member-main small{color:var(--arka-muted);font-size:.68rem}.member-main .dot{position:absolute;right:0;top:.35rem}
.dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}
.dot.online {
    background: var(--arka-primary);
}
.dot.offline {
    background: #60766c;
}
.member-meta {
    margin: 0.25rem 0 0;
    color: var(--arka-muted);
    font-size: 0.85rem;
}
.remove-btn {
    margin-top: 0.5rem;
    padding: 0.4rem 0.75rem;
    border: 1px solid rgba(248,113,113,.35);
    border-radius: .7rem;
    background: none;
    color: var(--arka-danger);
    font-size: 0.8rem;
}
.remove-btn:disabled {
    opacity: 0.6;
}
.member-actions{margin-top:.7rem;display:grid;grid-template-columns:1fr 1fr;gap:.45rem}.member-actions button{min-height:2.6rem;border-radius:.75rem;font-size:.75rem;font-weight:800}.ride-btn{border:0;background:var(--arka-primary);color:#fff}.recommend-btn{border:1px solid var(--arka-primary);background:transparent;color:var(--arka-primary)}.member-menu{margin-top:.4rem}.member-menu summary{color:var(--arka-muted);font-size:.68rem;text-align:right;list-style:none}.member-menu .remove-btn{width:100%;margin-top:.45rem}
.pending li {
    padding: 0.5rem 0;
    color: var(--arka-muted);
    font-size: 0.85rem;
    border-bottom: 1px solid var(--arka-border);
}
.pending li{display:flex;align-items:center;justify-content:space-between;gap:.6rem}.pending span{display:grid;gap:.15rem}.pending small{color:var(--arka-muted)}.pending button{border:0;background:transparent;color:var(--arka-danger);font-size:.72rem;font-weight:750}
</style>
