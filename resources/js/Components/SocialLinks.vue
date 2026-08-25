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
            <svg :class="size === 'sm' ? 'h-4 w-4' : 'h-5 w-5'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.3 17.7 4 20l2.3-2.3A8 8 0 1 1 6.3 17.7Z" />
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M9.2 9.6c0 3 2.6 5.6 5.6 5.6l1.1-1.7c.2-.3.1-.7-.2-.9l-1.5-.9c-.3-.2-.6-.1-.8.1l-.5.5c-.9-.5-1.7-1.3-2.2-2.2l.5-.5c.2-.2.3-.5.1-.8l-.9-1.5c-.2-.3-.6-.4-.9-.2Z"
                />
            </svg>
        </a>
    </div>
</template>
