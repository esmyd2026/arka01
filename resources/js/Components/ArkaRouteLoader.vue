<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: 'Preparando tu recorrido' },
    message: {
        type: String,
        default: 'Estamos calculando la mejor ruta y preparando las opciones disponibles para ti.',
    },
});
</script>

<template>
    <Transition name="route-loader-fade">
        <div
            v-if="show"
            class="fixed inset-0 z-[1800] grid place-items-center bg-[#06110c]/75 px-6 backdrop-blur-[6px]"
            role="status"
            aria-live="polite"
            aria-busy="true"
        >
            <div class="w-full max-w-[310px] rounded-[28px] border border-white/10 bg-[#10231b] px-6 py-7 text-center shadow-[0_28px_90px_rgba(0,0,0,0.5)]">
                <div class="arka-route-loader mx-auto" aria-hidden="true">
                    <span class="arka-route-loader__ring"></span>
                    <span class="arka-route-loader__ring arka-route-loader__ring--inner"></span>
                    <span class="arka-route-loader__core"><img src="/img/logo-arka01-icono.png" alt="" class="h-12 w-12 object-contain" /></span>
                    <span class="arka-route-loader__dot"></span>
                </div>
                <ApplicationLogo size="h-7" class="mx-auto mt-5" />
                <h2 class="mt-4 text-lg font-bold text-arka-text">{{ title }}</h2>
                <p class="mt-2 text-xs leading-5 text-arka-text-muted">{{ message }}</p>
                <div class="mt-5 flex items-center justify-center gap-1.5" aria-hidden="true">
                    <span class="arka-loading-pulse"></span><span class="arka-loading-pulse"></span><span class="arka-loading-pulse"></span>
                </div>
                <p class="mt-3 text-[10px] font-semibold uppercase tracking-[.15em] text-arka-primary">Un momento, por favor</p>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.arka-route-loader { width: 112px; height: 112px; position: relative; display: grid; place-items: center; }
.arka-route-loader__ring { position: absolute; inset: 2px; border-radius: 999px; border: 2px solid rgba(52,211,153,.13); border-top-color: #34d399; border-right-color: rgba(163,230,53,.65); animation: arka-route-spin 1.15s linear infinite; }
.arka-route-loader__ring--inner { inset: 12px; border-width: 1px; border-color: rgba(255,255,255,.08); border-bottom-color: rgba(52,211,153,.75); animation-duration: 1.8s; animation-direction: reverse; }
.arka-route-loader__core { width: 72px; height: 72px; position: relative; z-index: 2; display: grid; place-items: center; overflow: hidden; border-radius: 999px; background: radial-gradient(circle at 35% 25%, rgba(52,211,153,.18), rgba(4,20,13,.96) 68%); box-shadow: inset 0 0 0 1px rgba(52,211,153,.18), 0 12px 36px rgba(0,0,0,.35); }
.arka-route-loader__dot { width: 9px; height: 9px; position: absolute; left: 50%; top: -2px; z-index: 3; border-radius: 50%; background: #a3e635; box-shadow: 0 0 15px rgba(163,230,53,.9); transform-origin: 0 58px; animation: arka-route-orbit 1.15s linear infinite; }
.arka-loading-pulse { width: 6px; height: 6px; border-radius: 999px; background: #34d399; animation: arka-route-pulse 1.15s ease-in-out infinite; }.arka-loading-pulse:nth-child(2) { animation-delay: .16s; }.arka-loading-pulse:nth-child(3) { animation-delay: .32s; }
.route-loader-fade-enter-active, .route-loader-fade-leave-active { transition: opacity .2s ease; }.route-loader-fade-enter-from, .route-loader-fade-leave-to { opacity: 0; }
@keyframes arka-route-spin { to { transform: rotate(360deg); } }
@keyframes arka-route-orbit { to { transform: rotate(360deg); } }
@keyframes arka-route-pulse { 0%, 70%, 100% { opacity: .28; transform: scale(.8); } 35% { opacity: 1; transform: scale(1.2); } }
@media (prefers-reduced-motion: reduce) { .arka-route-loader__ring, .arka-route-loader__dot, .arka-loading-pulse { animation-duration: 2.8s; } }
</style>
