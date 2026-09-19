<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { fetchMyPlan } from '../services/plan';
import { getStoredUser } from '../services/auth';
import MobileShell from '../components/MobileShell.vue';

const router = useRouter();
const user = ref(null);
const loading = ref(true);
const error = ref(null);
const plan = ref(null);

const usedLabel = computed(() => {
    if (!plan.value) return null;
    if (plan.value.owner_type === 'driver') {
        const max = plan.value.current_plan.max_clients;
        return `${plan.value.used_clients} / ${max === null ? '∞' : max} clientes de confianza`;
    }
    if (plan.value.owner_type === 'client') {
        const max = plan.value.current_plan.max_fleets;
        return `${plan.value.used_fleets} / ${max === null ? '∞' : max} flotas`;
    }
    const max = plan.value.current_plan.max_units;
    return `${plan.value.used_units} / ${max === null ? '∞' : max} unidades`;
});

const secondaryLabel = computed(() => {
    if (!plan.value || plan.value.owner_type !== 'client') return null;
    const max = plan.value.current_plan.max_drivers_per_fleet;
    return `Hasta ${max === null ? '∞' : max} conductores por flota`;
});

const features = computed(() => {
    if (!plan.value || plan.value.owner_type !== 'driver') return [];
    const p = plan.value.current_plan;
    return [
        p.public_visibility ? 'Aparece en el directorio público' : null,
        p.priority_listing ? 'Sale primero en el directorio' : null,
        p.verified_badge ? 'Insignia de conductor verificado' : null,
        p.express_enabled ? 'Puede postularse a Expresos' : null,
        p.van_trips_enabled ? 'Puede publicar viajes tipo Van' : null,
    ].filter(Boolean);
});

const statusLabel = computed(() => {
    const status = plan.value?.current_plan?.subscription_status;
    return { active: 'Activo', grace: 'En periodo de gracia', expired: 'Vencido', none: 'Plan gratuito' }[status] || null;
});

onMounted(async () => {
    user.value = await getStoredUser();
    try {
        plan.value = await fetchMyPlan();
    } catch (e) {
        error.value = e.message || 'No se pudo cargar tu plan.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <MobileShell v-if="user" :role="user.role" :user-name="user.name">
        <main class="mobile-page plan-page">
            <button class="back-link" @click="router.back()">‹ Volver</button>

            <section class="welcome">
                <p class="mobile-eyebrow">Suscripción</p>
                <h1 class="mobile-title">Mi plan</h1>
            </section>

            <div v-if="loading" class="loading-row"><span class="mobile-spinner"></span><span>Cargando tu plan…</span></div>
            <p v-else-if="error" class="mobile-alert">{{ error }}</p>

            <template v-else-if="plan">
                <section class="plan-card mobile-card">
                    <p class="mobile-eyebrow">Plan vigente</p>
                    <h2>{{ plan.current_plan.plan_name }}</h2>
                    <p v-if="statusLabel" class="plan-status" :class="plan.current_plan.subscription_status">{{ statusLabel }}</p>
                    <p v-if="plan.current_plan.expires_at" class="plan-expiry">Vence el {{ new Date(plan.current_plan.expires_at).toLocaleDateString('es-EC') }}</p>
                </section>

                <section class="usage-card mobile-card">
                    <p class="mobile-eyebrow">Cupo usado</p>
                    <p class="usage-main">{{ usedLabel }}</p>
                    <p v-if="secondaryLabel" class="usage-secondary">{{ secondaryLabel }}</p>
                </section>

                <section v-if="features.length" class="features-card mobile-card">
                    <p class="mobile-eyebrow">Beneficios</p>
                    <ul class="feature-list">
                        <li v-for="feature in features" :key="feature">
                            <svg viewBox="0 0 24 24"><path d="m5 13 4 4L19 7" /></svg>
                            {{ feature }}
                        </li>
                    </ul>
                </section>

                <section v-if="plan.changes?.length">
                    <div class="section-heading"><p class="mobile-eyebrow">Historial</p><h2>Cambios de plan</h2></div>
                    <div v-for="change in plan.changes" :key="change.id" class="change-row mobile-card">
                        <span>
                            <small>{{ new Date(change.changed_at).toLocaleDateString('es-EC') }}</small>
                            <strong>{{ change.old_plan?.name || 'Gratis' }} → {{ change.new_plan.name }}</strong>
                        </span>
                    </div>
                </section>
            </template>
        </main>
    </MobileShell>

    <main v-else-if="loading" class="loading-screen"><span class="mobile-spinner"></span><p>Cargando…</p></main>
</template>

<style scoped>
.plan-page { display: grid; gap: 1.15rem; }
.back-link { justify-self: start; border: 0; background: transparent; color: var(--arka-muted); font-size: .85rem; font-weight: 700; padding: .35rem 0; }
.welcome { padding: 0 .25rem; }
.loading-row { display: flex; align-items: center; gap: .6rem; padding: 1rem .25rem; color: var(--arka-muted); font-size: .88rem; }
.plan-card { padding: 1.2rem; display: grid; gap: .3rem; background:var(--arka-success-panel); }
.plan-card h2 { margin: .2rem 0 0; font-size: 1.3rem; letter-spacing: -.02em; }
.plan-status { display: inline-block; width: fit-content; margin: .4rem 0 0; padding: .2rem .65rem; border-radius: 999px; background: var(--arka-primary-soft); color: var(--arka-primary); font-size: .7rem; font-weight: 800; text-transform: uppercase; }
.plan-status.expired { background: rgba(248,113,113,.14); color: var(--arka-danger); }
.plan-status.grace { background: rgba(251,191,36,.14); color: var(--arka-warning); }
.plan-expiry { margin: .5rem 0 0; color: var(--arka-muted); font-size: .8rem; }
.usage-card { padding: 1.1rem; display: grid; gap: .2rem; }
.usage-main { margin: .3rem 0 0; font-size: 1.05rem; font-weight: 800; }
.usage-secondary { margin: 0; color: var(--arka-muted); font-size: .82rem; }
.features-card { padding: 1.1rem; }
.feature-list { margin: .6rem 0 0; padding: 0; list-style: none; display: grid; gap: .55rem; }
.feature-list li { display: flex; align-items: center; gap: .55rem; color: var(--arka-text); font-size: .85rem; }
.feature-list svg { flex: none; width: 1.15rem; height: 1.15rem; fill: none; stroke: var(--arka-primary); stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
.section-heading { margin: .2rem .2rem .65rem; }
.section-heading h2 { margin: .25rem 0 0; font-size: 1.1rem; letter-spacing: -.02em; }
.change-row { margin-bottom: .6rem; padding: .8rem .95rem; }
.change-row small { display: block; color: var(--arka-muted); font-size: .68rem; text-transform: uppercase; }
.change-row strong { display: block; margin-top: .15rem; font-size: .85rem; }
.loading-screen { min-height: 100dvh; display: grid; place-content: center; justify-items: center; gap: .75rem; color: var(--arka-muted); }
</style>
