<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import AuthBrandingPanel from '@/Components/AuthBrandingPanel.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <!-- Layout para las pantallas sin sesión (login, registro, recuperar
         contraseña): panel de marca a la izquierda en escritorio (oculto en
         móvil, donde alcanza con el logo chico arriba de la tarjeta) y el
         formulario a la derecha. -->
    <div class="arka-app-background min-h-screen flex">
        <AuthBrandingPanel />

        <div class="flex-1 flex flex-col justify-center items-center px-6 py-12">
            <div class="w-full sm:max-w-md">
                <Link href="/" class="lg:hidden flex justify-center mb-6">
                    <ApplicationLogo size="h-10" />
                </Link>

                <div class="w-full px-6 py-4 bg-arka-card shadow-md overflow-hidden rounded-arka">
                    <slot />
                </div>

                <!-- Pedido explícito del usuario (gap identificado antes del
                     despliegue): enlaces a Términos y Privacidad, visibles
                     antes de registrarse. -->
                <p class="mt-6 text-center text-xs text-arka-text-muted">
                    <Link :href="route('legal.terms')" class="hover:text-arka-primary-bright">Términos</Link>
                    <span class="mx-2">·</span>
                    <Link :href="route('legal.privacy')" class="hover:text-arka-primary-bright">Privacidad</Link>
                </p>
            </div>
        </div>
    </div>
</template>
