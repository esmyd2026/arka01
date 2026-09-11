<script setup>
// Panel de marca para las pantallas de sesión (login, registro, etc.):
// pensado como sección reutilizable y con contenido variable (título,
// bajada y viñetas por prop), no un diseño fijo solo para el login — así
// cualquier pantalla nueva de este estilo (ej. una landing de precios) puede
// reusarlo con su propio contenido en vez de duplicar el layout.
defineProps({
    title: { type: String, default: 'Muévete con más confianza' },
    subtitle: {
        type: String,
        default: 'Arka01 conecta personas, conductores y cooperativas para hacer de cada viaje una experiencia más cercana y tranquila.',
    },
    bullets: {
        type: Array,
        default: () => [
            'Tranquilidad en cada viaje',
            'Conductores y cooperativas conectados',
            'Tu círculo de confianza, siempre cerca',
        ],
    },
});
</script>

<template>
    <!-- El fondo lo pinta GuestLayout.vue. Este panel no añade otra placa:
         la marca y el contenido descansan directamente sobre la fotografía. -->
    <div
        class="auth-branding relative hidden flex-col justify-center gap-8 p-8 lg:m-6 lg:flex lg:w-[calc(50%_-_3rem)] xl:p-10"
    >
        <!-- Pedido explícito del usuario ("que la pantalla se ajuste a la
             dimensión del dispositivo, que no scrollee"): el isotipo estaba
             a h-64 (256px) — con el padding y el resto del contenido, el
             panel se pasaba de la altura real de la pantalla en monitores
             normales y forzaba scroll en toda la página. Achicado a un
             tamaño que sigue siendo protagonista sin desbordar. -->
        <img src="/img/logo-arka01.png" alt="Arka01" class="h-16 w-auto max-w-full self-start object-contain object-left drop-shadow-[0_5px_16px_rgba(0,0,0,0.32)]" />

        <div class="max-w-md">
            <h2 class="text-3xl font-bold text-white leading-tight">{{ title }}</h2>
            <p class="mt-4 text-white/80">{{ subtitle }}</p>

            <!-- Pedido explícito del usuario: "los tres puntos únelos así con
                 la línea que venimos haciendo" — mismo patrón de ícono +
                 línea vertical que ya usa el hero de Welcome.vue y la lista
                 "Para Clientes", para que se lean como pasos de una misma
                 experiencia y no como 3 datos sueltos. -->
            <ul class="mt-8">
                <li v-for="(bullet, index) in bullets" :key="bullet" class="flex items-start gap-3">
                    <div class="flex flex-col items-center self-stretch shrink-0">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-arka-primary/15 border border-arka-primary/40">
                            <svg class="h-4 w-4 text-emerald-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m5 12.5 4.5 4.5L19 7" />
                            </svg>
                        </span>
                        <div v-if="index < bullets.length - 1" class="w-0.5 flex-1 min-h-[0.75rem] bg-arka-primary/40 my-0.5 rounded-full"></div>
                    </div>
                    <span class="pt-1.5 text-sm font-medium text-white">{{ bullet }}</span>
                </li>
            </ul>

            <!-- Espacio para CTAs adicionales según la pantalla (ej. "Ver planes"). -->
            <div class="mt-8 flex flex-wrap gap-3">
                <slot />
            </div>
        </div>

        <p class="text-xs text-white/70">Copyright © 2026 - <a href="https://arka01.com/" target="_blank" rel="noopener noreferrer">Arka01</a>, Reservados todos los derechos.</p>
    </div>
</template>

<style scoped>
.auth-branding {
    text-shadow: 0 2px 8px rgba(0, 0, 0, .58);
}
</style>
