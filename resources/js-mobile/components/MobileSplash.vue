<script setup>
import MobileBrand from './MobileBrand.vue';
</script>

<template>
    <div class="mobile-splash" role="status" aria-label="Abriendo Arka01">
        <div class="splash-brand">
            <MobileBrand />
            <span>Movilidad de confianza</span>
        </div>
        <div class="splash-progress" aria-hidden="true"><i /></div>
    </div>
</template>

<style scoped>
.mobile-splash {
    position: fixed;
    z-index: 1000;
    inset: 0;
    min-height: 100dvh;
    padding: env(safe-area-inset-top) 1.5rem env(safe-area-inset-bottom);
    display: grid;
    place-items: center;
    background:
        radial-gradient(circle at 50% 40%, rgba(52, 211, 153, .14), transparent 34%),
        #07110d;
}

.splash-brand { display: grid; justify-items: center; gap: .9rem; }
.splash-brand span { color: var(--arka-muted); font-size: .72rem; font-weight: 700; letter-spacing: .13em; text-transform: uppercase; }
.splash-progress { position: absolute; bottom: calc(2.25rem + env(safe-area-inset-bottom)); width: 4.5rem; height: .16rem; overflow: hidden; border-radius: 999px; background: rgba(147, 173, 162, .14); }
.splash-progress i { display: block; width: 45%; height: 100%; border-radius: inherit; background: var(--arka-primary); animation: splash-progress 850ms ease-in-out infinite alternate; }

@keyframes splash-progress { to { transform: translateX(125%); } }
</style>
