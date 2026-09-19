<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { fetchCooperativeInvitations, respondCooperativeInvitation } from '../services/cooperatives';
import { getStoredUser } from '../services/auth';
import MobileShell from '../components/MobileShell.vue';

const router = useRouter();
const user = ref(null);
const loading = ref(true);
const error = ref(null);
const memberships = ref([]);
const workingId = ref(null);

const statusLabel = { pending: 'Pendiente', accepted: 'Activo', suspended: 'Suspendido' };

async function load() {
    loading.value = true;
    try {
        memberships.value = await fetchCooperativeInvitations();
    } catch (e) {
        error.value = e.message || 'No se pudieron cargar las invitaciones.';
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    user.value = await getStoredUser();
    if (user.value?.role !== 'conductor') {
        router.replace({ name: 'home' });
        return;
    }
    await load();
});

async function respond(membership, decision) {
    workingId.value = membership.id;
    error.value = null;
    try {
        await respondCooperativeInvitation(membership.id, decision);
        await load();
    } catch (e) {
        error.value = e.message || 'No se pudo responder la invitación.';
    } finally {
        workingId.value = null;
    }
}
</script>

<template>
    <MobileShell v-if="user" :role="user.role" :user-name="user.name">
        <main class="mobile-page invitations-page">
            <button class="back-link" @click="router.back()">‹ Volver</button>

            <section class="welcome">
                <p class="mobile-eyebrow">Cooperativas</p>
                <h1 class="mobile-title">Invitaciones</h1>
                <p class="welcome-copy">Cooperativas que te invitaron a unirte, o donde ya estás afiliado.</p>
            </section>

            <div v-if="loading" class="loading-row"><span class="mobile-spinner"></span><span>Cargando…</span></div>
            <p v-else-if="error" class="mobile-alert">{{ error }}</p>
            <p v-else-if="!memberships.length" class="empty-copy">No tienes invitaciones ni vínculos con cooperativas.</p>

            <article v-for="membership in memberships" :key="membership.id" class="membership-card mobile-card">
                <img v-if="membership.cooperative?.logo_url" :src="membership.cooperative.logo_url" alt="" class="coop-logo" />
                <div v-else class="coop-logo coop-logo--placeholder">{{ membership.cooperative?.name?.[0] }}</div>

                <div class="membership-info">
                    <strong>{{ membership.cooperative?.name }}</strong>
                    <small>{{ membership.cooperative?.city?.name || 'Sin ciudad registrada' }}</small>
                    <span class="status-chip" :class="membership.status">{{ statusLabel[membership.status] || membership.status }}</span>
                </div>

                <div v-if="membership.status === 'pending'" class="actions">
                    <button class="mini-button accept" :disabled="workingId === membership.id" @click="respond(membership, 'accept')">Aceptar</button>
                    <button class="mini-button reject" :disabled="workingId === membership.id" @click="respond(membership, 'reject')">Rechazar</button>
                </div>
            </article>
        </main>
    </MobileShell>

    <main v-else-if="loading" class="loading-screen"><span class="mobile-spinner"></span><p>Cargando…</p></main>
</template>

<style scoped>
.invitations-page { display: grid; gap: 1.15rem; }
.back-link { justify-self: start; border: 0; background: transparent; color: var(--arka-muted); font-size: .85rem; font-weight: 700; padding: .35rem 0; }
.welcome { padding: 0 .25rem; }
.welcome-copy { margin: .5rem 0 0; color: var(--arka-muted); font-size: .85rem; line-height: 1.5; }
.loading-row { display: flex; align-items: center; gap: .6rem; padding: 1rem .25rem; color: var(--arka-muted); font-size: .88rem; }
.empty-copy { margin: 0; padding: .25rem; color: var(--arka-muted); font-size: .85rem; }
.membership-card { padding: .9rem 1rem; display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: .8rem; }
.coop-logo { width: 2.8rem; height: 2.8rem; border-radius: .8rem; object-fit: cover; }
.coop-logo--placeholder { display: grid; place-items: center; background: var(--arka-primary-soft); color: var(--arka-primary); font-weight: 800; }
.membership-info { display: grid; gap: .18rem; min-width: 0; }
.membership-info strong { font-size: .9rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.membership-info small { color: var(--arka-muted); font-size: .72rem; }
.status-chip { justify-self: start; margin-top: .15rem; padding: .12rem .5rem; border-radius: 999px; font-size: .65rem; font-weight: 800; text-transform: uppercase; background: rgba(147,173,162,.15); color: var(--arka-muted); }
.status-chip.accepted { background: var(--arka-primary-soft); color: var(--arka-primary); }
.status-chip.pending { background: rgba(251,191,36,.14); color: var(--arka-warning); }
.actions { display: grid; gap: .4rem; }
.mini-button { min-height: 2.1rem; padding: 0 .7rem; border-radius: .6rem; font-size: .72rem; font-weight: 700; border: 1px solid transparent; }
.mini-button.accept { background: var(--arka-primary); color: #062016; }
.mini-button.reject { background: transparent; border-color: var(--arka-danger); color: var(--arka-danger); }
.loading-screen { min-height: 100dvh; display: grid; place-content: center; justify-items: center; gap: .75rem; color: var(--arka-muted); }
</style>
