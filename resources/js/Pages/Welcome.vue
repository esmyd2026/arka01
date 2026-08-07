<script setup>
import { Head, Link } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
});
</script>

<template>
    <Head title="Arka01 — Solo suben los suyos." />

    <div class="min-h-screen bg-arka-base flex flex-col items-center justify-center px-6 text-center">
        <ApplicationLogo size="text-5xl sm:text-6xl" class="mb-4" />

        <p class="mt-2 text-arka-text-muted max-w-md">
            «Solo suben los suyos.» Arme su propia flota de conductores de confianza y pida sus viajes dentro de
            ese círculo.
        </p>

        <div class="mt-8 flex gap-4" v-if="canLogin">
            <Link
                v-if="$page.props.auth.user"
                :href="route('dashboard')"
                class="inline-flex items-center px-4 py-2 bg-arka-primary rounded-arka font-semibold text-sm text-arka-base hover:bg-arka-primary-bright transition"
            >
                Ir a mi cuenta
            </Link>

            <template v-else>
                <Link
                    :href="route('login')"
                    class="inline-flex items-center px-4 py-2 bg-arka-card border border-arka-text-muted/30 rounded-arka font-semibold text-sm text-arka-text hover:bg-arka-base transition"
                >
                    Iniciar sesión
                </Link>
                <Link
                    v-if="canRegister"
                    :href="route('register')"
                    class="inline-flex items-center px-4 py-2 bg-arka-primary rounded-arka font-semibold text-sm text-arka-base hover:bg-arka-primary-bright transition"
                >
                    Crear cuenta
                </Link>
            </template>
        </div>

        <p class="mt-10 text-xs text-arka-text-muted">
            <Link href="/terminos" class="hover:text-arka-primary-bright">Términos</Link>
            <span class="mx-2">·</span>
            <Link href="/privacidad" class="hover:text-arka-primary-bright">Privacidad</Link>
        </p>
    </div>
</template>
