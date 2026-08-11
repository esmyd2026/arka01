<script setup>
// Panel de marca para las pantallas de sesión (login, registro, etc.):
// pensado como sección reutilizable y con contenido variable (título,
// bajada y viñetas por prop), no un diseño fijo solo para el login — así
// cualquier pantalla nueva de este estilo (ej. una landing de precios) puede
// reusarlo con su propio contenido en vez de duplicar el layout.
defineProps({
    title: { type: String, default: '«Solo suben los suyos.»' },
    subtitle: {
        type: String,
        default: 'Arme su propia flota de conductores de confianza y pida sus viajes dentro de ese círculo — sin desconocidos, sin sorpresas.',
    },
    bullets: {
        type: Array,
        default: () => [
            'Invita usted a quién entra a su flota',
            'Precio siempre desglosado, nunca oculto',
            'Seguimiento en vivo y botón SOS en cada viaje',
        ],
    },
});
</script>

<template>
    <div
        class="relative hidden lg:flex lg:w-1/2 flex-col justify-between p-12 overflow-hidden"
        style="background: radial-gradient(circle at 15% 15%, rgba(52, 211, 153, 0.16), transparent 45%), linear-gradient(160deg, #0a0f0c 0%, #0d1712 55%, #10251c 100%)"
    >
        <!-- Pedido explícito del usuario (con captura): el lockup completo
             (isotipo + "Arka01") se veía mal acá — se usa solo el isotipo,
             chico y proporcionado, para que el resto del contenido del panel
             quede bien ubicado debajo. -->
        <img src="/img/logo-arka01-icono.png" alt="Arka01" class="h-64 w-auto self-start" />

        <div class="max-w-md">
            <h2 class="text-3xl font-bold text-arka-text leading-tight">{{ title }}</h2>
            <p class="mt-4 text-arka-text-muted">{{ subtitle }}</p>

            <ul class="mt-8 space-y-3">
                <li v-for="bullet in bullets" :key="bullet" class="flex items-start gap-3">
                    <svg class="h-5 w-5 mt-0.5 shrink-0 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="9" stroke-linecap="round" stroke-linejoin="round" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.5 12.5 2.5 2.5 4.5-5" />
                    </svg>
                    <span class="text-sm text-arka-text">{{ bullet }}</span>
                </li>
            </ul>

            <!-- Espacio para CTAs adicionales según la pantalla (ej. "Ver planes"). -->
            <div class="mt-8 flex flex-wrap gap-3">
                <slot />
            </div>
        </div>

        <p class="text-xs text-arka-text-muted">Copyright © 2026 - <a href="https://arka01.com/" target="_blank" rel="noopener noreferrer">Arka01</a>, Reservados todos los derechos.</p>
    </div>
</template>
