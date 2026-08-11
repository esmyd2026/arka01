<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import SubscriptionSummary from './Partials/SubscriptionSummary.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import ShareProfileQr from '@/Components/ShareProfileQr.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

// "Ver mi suscripción" del menú de cuenta lleva a /profile#suscripcion — el
// scroll automático de Inertia al hash no es parejo entre versiones, así que
// lo forzamos acá como respaldo.
onMounted(() => {
    if (window.location.hash) {
        document.getElementById(window.location.hash.slice(1))?.scrollIntoView({ behavior: 'smooth' });
    }
});

const props = defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    cities: {
        type: Array,
        required: true,
    },
    subscriptionSummary: {
        type: Object,
        required: true,
    },
    profileUrl: {
        type: String,
        required: true,
    },
});

// Compartir mi perfil (pedido explícito del usuario, con captura de
// referencia): QR con el logo + mensaje prearmado para WhatsApp. El link en
// sí ya se ve "profesional" al compartirse — WhatsApp arma la tarjeta con
// foto y nombre solo, leyendo las etiquetas og:* de Profile/Show.vue (ver
// PublicProfileController::show()).
const whatsappShareUrl = computed(() => {
    const text = `¡Hola! Soy ${usePage().props.auth.user.name} en Arka01 🚗\n\nMirá mi perfil:\n${props.profileUrl}`;
    return `https://wa.me/?text=${encodeURIComponent(text)}`;
});

const linkCopied = ref(false);
async function copyProfileLink() {
    await navigator.clipboard.writeText(props.profileUrl);
    linkCopied.value = true;
    setTimeout(() => (linkCopied.value = false), 2000);
}
</script>

<template>
    <Head title="Mi perfil" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <UserAvatar :user="$page.props.auth.user" size-class="h-12 w-12 text-base" />
                <h2 class="font-semibold text-xl text-arka-text leading-tight">Mi perfil</h2>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Tarjetas sobre fondo de tarjeta (arka-card), consistente con el resto de la interfaz -->
                <div class="p-4 sm:p-8 bg-arka-card shadow sm:rounded-arka">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        :cities="cities"
                        class="max-w-xl"
                    />
                </div>

                <!-- Compartir mi perfil (pedido explícito del usuario): QR con el
                     logo + WhatsApp, para que otros lo escaneen o lo abran desde un
                     enlace que se ve profesional al compartirse. -->
                <div class="p-4 sm:p-8 bg-arka-card shadow sm:rounded-arka">
                    <h2 class="text-lg font-medium text-arka-text">Compartir mi perfil</h2>
                    <p class="mt-1 text-sm text-arka-text-muted max-w-xl">
                        Para que alguien lo agregue a su flota o vea sus calificaciones sin buscarlo a mano —
                        escaneando el código o abriendo el enlace.
                    </p>

                    <div class="mt-4 flex flex-col sm:flex-row items-start sm:items-center gap-6">
                        <ShareProfileQr :url="profileUrl" />

                        <div class="flex flex-col gap-2">
                            <a
                                :href="whatsappShareUrl"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-2 px-4 py-2 rounded-arka bg-arka-primary text-arka-base text-sm font-semibold hover:bg-arka-primary-bright transition"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12 2a10 10 0 0 0-8.6 15L2 22l5.2-1.4A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.1l-.3-.2-3.1.8.8-3-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1-.2.2-.7.8-.8 1-.1.1-.3.2-.5.1-.2-.1-1-.4-1.9-1.2-.7-.6-1.2-1.4-1.3-1.6-.1-.2 0-.4.1-.5.1-.1.2-.3.4-.4.1-.1.2-.2.2-.4.1-.1 0-.3 0-.4 0-.1-.6-1.4-.8-1.9-.2-.5-.4-.4-.6-.4h-.5c-.2 0-.4.1-.6.3-.2.2-.8.8-.8 1.9s.8 2.2 1 2.4c.1.1 1.6 2.4 3.8 3.4.5.2.9.4 1.3.5.6.2 1.1.1 1.5.1.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.1-1.2 0-.1-.2-.2-.4-.3Z" />
                                </svg>
                                Compartir por WhatsApp
                            </a>
                            <SecondaryButton @click="copyProfileLink">
                                {{ linkCopied ? '¡Copiado!' : 'Copiar enlace' }}
                            </SecondaryButton>
                        </div>
                    </div>
                </div>

                <div v-if="Object.keys(subscriptionSummary).length" class="p-4 sm:p-8 bg-arka-card shadow sm:rounded-arka">
                    <SubscriptionSummary :summary="subscriptionSummary" class="max-w-xl" />
                </div>

                <div class="p-4 sm:p-8 bg-arka-card shadow sm:rounded-arka">
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div class="p-4 sm:p-8 bg-arka-card shadow sm:rounded-arka">
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
