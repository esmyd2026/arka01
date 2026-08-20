<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { resetStartupSplash } from '@/Utils/startupSplash';

const props = defineProps({
    status: {
        type: String,
    },
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(() => props.status === 'verification-link-sent');
</script>

<template>
    <GuestLayout>
        <Head title="Verificar correo" />

        <div class="mb-4 text-sm text-arka-text-muted">
            ¡Gracias por registrarse! Antes de empezar, ¿puede verificar su correo haciendo clic en el enlace que
            le acabamos de enviar? Si no le llegó, le mandamos otro con gusto.
        </div>

        <div class="mb-4 font-medium text-sm text-arka-primary-bright" v-if="verificationLinkSent">
            Le enviamos un nuevo enlace de verificación al correo que usó al registrarse.
        </div>

        <form @submit.prevent="submit">
            <div class="mt-4 flex items-center justify-between">
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Reenviar correo de verificación
                </PrimaryButton>

                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    @click="resetStartupSplash"
                    class="underline text-sm text-arka-text-muted hover:text-arka-text rounded-arka focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-arka-card focus:ring-arka-primary"
                    >Cerrar sesión</Link
                >
            </div>
        </form>
    </GuestLayout>
</template>
