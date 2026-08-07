<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <GuestLayout>
        <Head title="Cuenta bloqueada" />

        <div class="text-center space-y-3">
            <div class="mx-auto h-12 w-12 rounded-full bg-arka-danger/15 flex items-center justify-center">
                <svg class="h-6 w-6 text-arka-danger" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="4.5" y="10" width="15" height="10" rx="2" stroke-linecap="round" stroke-linejoin="round" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 10V7a4 4 0 0 1 8 0v3" />
                </svg>
            </div>

            <h1 class="text-lg font-semibold text-arka-text">Cuenta bloqueada</h1>

            <p class="text-sm text-arka-text-muted">
                Bloqueamos esta cuenta de inmediato y cerramos todas sus sesiones activas. Un administrador de Arka01
                la va a revisar para reactivarla.
            </p>

            <Link :href="route('login')" class="inline-block mt-2 text-sm text-arka-primary hover:text-arka-primary-bright">
                Volver al inicio de sesión
            </Link>
        </div>
    </GuestLayout>
</template>
