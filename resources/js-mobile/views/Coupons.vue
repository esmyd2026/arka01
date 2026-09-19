<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { fetchCoupons } from '../services/coupons';
import { getStoredUser } from '../services/auth';
import MobileShell from '../components/MobileShell.vue';

const router = useRouter();
const user = ref(null);
const loading = ref(true);
const error = ref(null);
const coupons = ref([]);

function formatExpiry(dateString) {
    return new Date(dateString).toLocaleDateString('es-EC', { day: 'numeric', month: 'short', year: 'numeric' });
}

onMounted(async () => {
    user.value = await getStoredUser();
    try {
        coupons.value = await fetchCoupons();
    } catch (e) {
        error.value = e.message || 'No se pudieron cargar los cupones.';
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <MobileShell v-if="user" :role="user.role" :user-name="user.name">
        <main class="mobile-page coupons-page">
            <button class="back-link" @click="router.back()">‹ Volver</button>

            <section class="welcome">
                <p class="mobile-eyebrow">Beneficios</p>
                <h1 class="mobile-title">Cupones y aliados</h1>
                <p class="welcome-copy">Promociones exclusivas para {{ user.role === 'conductor' ? 'conductores' : 'clientes' }} de Arka01.</p>
            </section>

            <div v-if="loading" class="loading-row"><span class="mobile-spinner"></span><span>Cargando…</span></div>
            <p v-else-if="error" class="mobile-alert">{{ error }}</p>
            <p v-else-if="!coupons.length" class="empty-copy">Todavía no hay cupones activos para vos. Vuelve pronto.</p>

            <article v-for="coupon in coupons" :key="coupon.id" class="coupon-card mobile-card">
                <img v-if="coupon.image_url" :src="coupon.image_url" :alt="coupon.title" class="coupon-image" />
                <div class="coupon-body">
                    <h2>{{ coupon.title }}</h2>
                    <p v-if="coupon.description">{{ coupon.description }}</p>
                    <p v-if="coupon.expires_at" class="coupon-expiry">Vence el {{ formatExpiry(coupon.expires_at) }}</p>
                    <a v-if="coupon.button_url" class="mobile-button coupon-button" :href="coupon.button_url" target="_blank" rel="noopener">
                        {{ coupon.button_label || 'Ver más' }}
                    </a>
                </div>
            </article>
        </main>
    </MobileShell>

    <main v-else-if="loading" class="loading-screen"><span class="mobile-spinner"></span><p>Cargando…</p></main>
</template>

<style scoped>
.coupons-page { display: grid; gap: 1.15rem; }
.back-link { justify-self: start; border: 0; background: transparent; color: var(--arka-muted); font-size: .85rem; font-weight: 700; padding: .35rem 0; }
.welcome { padding: 0 .25rem; }
.welcome-copy { margin: .5rem 0 0; color: var(--arka-muted); font-size: .85rem; line-height: 1.5; }
.loading-row { display: flex; align-items: center; gap: .6rem; padding: 1rem .25rem; color: var(--arka-muted); font-size: .88rem; }
.empty-copy { margin: 0; padding: .25rem; color: var(--arka-muted); font-size: .85rem; line-height: 1.5; }
.coupon-card { padding: 0; overflow: hidden; display: grid; gap: 0; }
.coupon-image { width: 100%; aspect-ratio: 16 / 9; object-fit: cover; display: block; }
.coupon-body { padding: 1rem 1.1rem 1.15rem; display: grid; gap: .4rem; }
.coupon-body h2 { margin: 0; font-size: 1rem; letter-spacing: -.02em; }
.coupon-body p { margin: 0; color: var(--arka-muted); font-size: .85rem; line-height: 1.5; }
.coupon-expiry { font-size: .72rem; text-transform: uppercase; color: var(--arka-primary); font-weight: 700; }
.coupon-button { margin-top: .5rem; text-align: center; text-decoration: none; }
.loading-screen { min-height: 100dvh; display: grid; place-content: center; justify-items: center; gap: .75rem; color: var(--arka-muted); }
</style>
