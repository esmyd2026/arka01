<script setup>
import { Head, Link } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

// Envoltorio simple para las páginas legales (Términos y Privacidad, pedido
// explícito del usuario): son las únicas dos pantallas de solo texto de la
// app, accesibles sin sesión — no comparten AuthenticatedLayout (nav de
// cliente/conductor) ni GuestLayout (panel de marca partido, pensado para
// formularios cortos).
defineProps({
    title: { type: String, required: true },
    updatedAt: { type: String, required: true },
});
</script>

<template>
    <Head :title="title" />

    <div class="min-h-screen bg-arka-base">
        <header class="border-b border-arka-text-muted/10 px-4 sm:px-6 py-4">
            <div class="max-w-3xl mx-auto flex items-center justify-between">
                <Link href="/">
                    <ApplicationLogo size="h-8" />
                </Link>
                <Link href="/" class="text-sm text-arka-text-muted hover:text-arka-primary-bright">
                    Volver al inicio
                </Link>
            </div>
        </header>

        <main class="max-w-3xl mx-auto px-4 sm:px-6 py-10">
            <h1 class="text-2xl font-semibold text-arka-text">{{ title }}</h1>
            <p class="mt-1 text-sm text-arka-text-muted">Última actualización: {{ updatedAt }}</p>

            <div class="mt-8 space-y-6 text-sm text-arka-text-muted leading-relaxed [&_h2]:text-base [&_h2]:font-semibold [&_h2]:text-arka-text [&_h2]:mb-2 [&_strong]:text-arka-text [&_a]:text-arka-primary [&_a]:hover:text-arka-primary-bright [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:space-y-1">
                <slot />
            </div>
        </main>
    </div>
</template>
