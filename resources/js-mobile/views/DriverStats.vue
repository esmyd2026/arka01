<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { fetchDriverStats } from '../services/driverStats';
import { getStoredUser } from '../services/auth';
import MobileShell from '../components/MobileShell.vue';

const router = useRouter();
const user = ref(null);
const loading = ref(true);
const error = ref(null);
const stats = ref(null);

const statusLabels = { completed: 'Completada', cancelled: 'Cancelada', in_progress: 'En curso', scheduled: 'Programada' };

function formatMoney(value) {
    return `$${Number(value).toFixed(2)}`;
}

function formatDate(dateString) {
    return new Date(dateString).toLocaleDateString('es-EC', { day: 'numeric', month: 'short' });
}

onMounted(async () => {
    user.value = await getStoredUser();
    try {
        stats.value = await fetchDriverStats();
    } catch (e) {
        error.value = e.message || 'No se pudieron cargar tus indicadores.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <MobileShell v-if="user" :role="user.role" :user-name="user.name">
        <main class="mobile-page stats-page">
            <button class="back-link" @click="router.back()">‹ Volver</button>

            <section class="welcome">
                <p class="mobile-eyebrow">Desempeño</p>
                <h1 class="mobile-title">Mis indicadores</h1>
            </section>

            <div v-if="loading" class="loading-row"><span class="mobile-spinner"></span><span>Cargando…</span></div>
            <p v-else-if="error" class="mobile-alert">{{ error }}</p>

            <template v-else-if="stats">
                <section class="tier-card mobile-card">
                    <span class="tier-emoji">{{ stats.gamification.tier.badge_emoji }}</span>
                    <span class="tier-info">
                        <small>Medalla actual</small>
                        <strong>{{ stats.gamification.tier.name }}</strong>
                        <small v-if="stats.gamification.next_tier">
                            Faltan {{ stats.gamification.next_tier.min_points - stats.gamification.total_points }} puntos para {{ stats.gamification.next_tier.name }}
                        </small>
                    </span>
                </section>

                <div class="totals-grid">
                    <div class="total-tile mobile-card">
                        <small>Ganancias</small>
                        <strong>{{ formatMoney(stats.totals.earnings) }}</strong>
                    </div>
                    <div class="total-tile mobile-card">
                        <small>Completadas</small>
                        <strong>{{ stats.totals.completed }}</strong>
                    </div>
                    <div class="total-tile mobile-card">
                        <small>Canceladas</small>
                        <strong>{{ stats.totals.cancelled }}</strong>
                    </div>
                    <div class="total-tile mobile-card">
                        <small>Distancia</small>
                        <strong>{{ stats.totals.distance_km.toFixed(1) }} km</strong>
                    </div>
                    <div class="total-tile mobile-card">
                        <small>Calificación</small>
                        <strong>{{ stats.totals.average_rating || '—' }} <span v-if="stats.totals.average_rating">★</span></strong>
                    </div>
                    <div class="total-tile mobile-card">
                        <small>Reseñas</small>
                        <strong>{{ stats.totals.review_count }}</strong>
                    </div>
                </div>

                <section v-if="stats.daily_earnings.length">
                    <div class="section-heading"><p class="mobile-eyebrow">Últimos días</p><h2>Ganancia diaria</h2></div>
                    <div class="earnings-list mobile-card">
                        <div v-for="day in stats.daily_earnings" :key="day.label" class="earnings-row">
                            <span>{{ day.label }}</span>
                            <strong>{{ formatMoney(day.value) }}</strong>
                        </div>
                    </div>
                </section>

                <section v-if="stats.history.length">
                    <div class="section-heading"><p class="mobile-eyebrow">Historial</p><h2>Carreras recientes</h2></div>
                    <div v-for="ride in stats.history" :key="ride.id" class="ride-row mobile-card">
                        <span class="ride-info">
                            <strong>{{ ride.client_name }}</strong>
                            <small>{{ ride.origin_address }} → {{ ride.destination_address }}</small>
                            <small>{{ formatDate(ride.date) }} · {{ statusLabels[ride.status] || ride.status }}</small>
                        </span>
                        <strong class="ride-price">{{ formatMoney(ride.price) }}</strong>
                    </div>
                </section>
                <p v-else class="empty-copy">Todavía no tienes carreras registradas.</p>
            </template>
        </main>
    </MobileShell>

    <main v-else-if="loading" class="loading-screen"><span class="mobile-spinner"></span><p>Cargando…</p></main>
</template>

<style scoped>
.stats-page { display: grid; gap: 1.15rem; }
.back-link { justify-self: start; border: 0; background: transparent; color: var(--arka-muted); font-size: .85rem; font-weight: 700; padding: .35rem 0; }
.welcome { padding: 0 .25rem; }
.loading-row { display: flex; align-items: center; gap: .6rem; padding: 1rem .25rem; color: var(--arka-muted); font-size: .88rem; }
.empty-copy { margin: 0; padding: .25rem; color: var(--arka-muted); font-size: .85rem; }
.tier-card { padding: 1.1rem; display: grid; grid-template-columns: auto 1fr; align-items: center; gap: .85rem; background:var(--arka-success-panel); }
.tier-emoji { font-size: 2.2rem; }
.tier-info { display: grid; gap: .15rem; }
.tier-info small { color: var(--arka-muted); font-size: .72rem; }
.tier-info strong { font-size: 1.1rem; }
.totals-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: .7rem; }
.total-tile { padding: .85rem .95rem; display: grid; gap: .25rem; }
.total-tile small { color: var(--arka-muted); font-size: .7rem; text-transform: uppercase; }
.total-tile strong { font-size: 1.05rem; }
.section-heading { margin: .2rem .2rem .65rem; }
.section-heading h2 { margin: .25rem 0 0; font-size: 1.1rem; letter-spacing: -.02em; }
.earnings-list { padding: .3rem 1rem; }
.earnings-row { display: flex; justify-content: space-between; padding: .6rem 0; font-size: .85rem; border-bottom: 1px solid rgba(147,173,162,.12); }
.earnings-row:last-child { border-bottom: none; }
.ride-row { margin-bottom: .6rem; padding: .85rem 1rem; display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
.ride-info { display: grid; gap: .15rem; min-width: 0; }
.ride-info strong { font-size: .88rem; }
.ride-info small { color: var(--arka-muted); font-size: .72rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ride-price { flex: none; color: var(--arka-primary); font-size: .95rem; }
.loading-screen { min-height: 100dvh; display: grid; place-content: center; justify-items: center; gap: .75rem; color: var(--arka-muted); }
</style>
