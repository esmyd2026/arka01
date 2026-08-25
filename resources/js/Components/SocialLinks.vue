<script setup>
// Redes de Arka01 (pedido explícito del usuario) — mismo trío en el footer
// de la raíz (Welcome.vue), el de las pantallas de sesión (GuestLayout.vue)
// y el "Síguenos" de la encuesta (Survey/Show.vue). Un solo lugar para
// cambiar el usuario o el número si algún día cambian.
const INSTAGRAM_URL = 'https://instagram.com/arka01.ec';
const TIKTOK_URL = 'https://tiktok.com/@arka01.ec';
const WHATSAPP_URL = 'https://wa.me/593994281769';

defineProps({
    // "sm" para donde el footer ya es chico (login), "md" para donde hay
    // más lugar (raíz, encuesta).
    size: { type: String, default: 'md' },
});
</script>

<template>
    <div class="flex items-center gap-3">
        <a
            :href="INSTAGRAM_URL"
            target="_blank"
            rel="noopener noreferrer"
            aria-label="Arka01 en Instagram"
            class="flex items-center justify-center rounded-full border border-arka-text-muted/20 text-arka-text-muted hover:text-arka-primary-bright hover:border-arka-primary/50 transition"
            :class="size === 'sm' ? 'h-8 w-8' : 'h-10 w-10'"
        >
            <svg :class="size === 'sm' ? 'h-4 w-4' : 'h-5 w-5'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="18" height="18" rx="5" stroke-linecap="round" stroke-linejoin="round" />
                <circle cx="12" cy="12" r="4" stroke-linecap="round" stroke-linejoin="round" />
                <circle cx="17.4" cy="6.6" r="0.6" fill="currentColor" stroke="none" />
            </svg>
        </a>
        <a
            :href="TIKTOK_URL"
            target="_blank"
            rel="noopener noreferrer"
            aria-label="Arka01 en TikTok"
            class="flex items-center justify-center rounded-full border border-arka-text-muted/20 text-arka-text-muted hover:text-arka-primary-bright hover:border-arka-primary/50 transition"
            :class="size === 'sm' ? 'h-8 w-8' : 'h-10 w-10'"
        >
            <svg :class="size === 'sm' ? 'h-4 w-4' : 'h-5 w-5'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14 4v10.5a3.5 3.5 0 1 1-2.5-3.36" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M14 4a5 5 0 0 0 5 5" />
            </svg>
        </a>
        <a
            :href="WHATSAPP_URL"
            target="_blank"
            rel="noopener noreferrer"
            aria-label="Arka01 en WhatsApp"
            class="flex items-center justify-center rounded-full border border-arka-text-muted/20 text-arka-text-muted hover:text-arka-primary-bright hover:border-arka-primary/50 transition"
            :class="size === 'sm' ? 'h-8 w-8' : 'h-10 w-10'"
        >
            <svg :class="size === 'sm' ? 'h-4 w-4' : 'h-5 w-5'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3Z" />
                <path
                    fill="currentColor"
                    stroke="none"
                    d="M9.2 8.5c.3-.7 1-1.1 1.7-.9l1 .3c.6.2 1 .8.9 1.4l-.2.9c-.1.3 0 .6.2.8.6.7 1.3 1.2 2.1 1.6.3.1.6.1.8-.1l.7-.6c.4-.4 1.1-.4 1.5 0l1 .8c.5.4.6 1.1.2 1.6-.4.5-1 .8-1.6.8-4 0-7.2-3.2-7.2-7.2 0-.4.1-.7.3-1Z"
                />
            </svg>
        </a>
    </div>
</template>
