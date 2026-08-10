<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

// Pedido explícito del usuario: registrarse (o loguearse por primera vez)
// con Google armaba la cuenta directo como cliente, sin preguntar — mismo
// primer paso que ya tiene el registro normal (Register.vue), solo que acá
// como pantalla propia porque la cuenta de Google ya existe (con nombre y
// correo ya cargados) antes de llegar a elegir esto.
const firstName = (usePage().props.auth.user.name ?? '').trim().split(/\s+/)[0] ?? '';
</script>

<template>
    <Head title="¿Cómo va a usar Arka01?" />

    <GuestLayout>
        <p class="text-sm text-arka-primary-bright font-medium mb-1">¡Bienvenido, {{ firstName }}!</p>
        <h2 class="text-lg font-medium text-arka-text mb-4">¿Qué tipo de cuenta necesita?</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Link
                :href="route('dashboard')"
                class="p-4 rounded-arka border-2 border-arka-text-muted/20 hover:border-arka-primary hover:bg-arka-primary/10 text-start transition"
            >
                <svg class="h-7 w-7 text-arka-primary mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="8" r="3.5" stroke-linecap="round" stroke-linejoin="round" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 20a7.5 7.5 0 0 1 15 0" />
                </svg>
                <p class="font-medium text-arka-text">Pasajero</p>
                <p class="text-xs text-arka-text-muted mt-0.5">Armo mi flota de conductores de confianza y pido carreras.</p>
            </Link>

            <Link
                :href="route('driver.profile.edit')"
                class="p-4 rounded-arka border-2 border-arka-text-muted/20 hover:border-arka-primary hover:bg-arka-primary/10 text-start transition"
            >
                <svg class="h-7 w-7 text-arka-primary mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l2.5-6.5A2 2 0 0 1 8.35 8.2h7.3a2 2 0 0 1 1.85 1.3L20 16" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16h16v2.5a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1V17H7v1.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V16Z" />
                </svg>
                <p class="font-medium text-arka-text">Conductor</p>
                <p class="text-xs text-arka-text-muted mt-0.5">Manejo mi propio vehículo y recibo carreras de mis clientes.</p>
            </Link>
        </div>

        <p class="mt-4 text-xs text-arka-text-muted">
            Puede cambiar de opinión después contactándonos — pero elegir bien ahora le ahorra pasos.
        </p>
    </GuestLayout>
</template>
