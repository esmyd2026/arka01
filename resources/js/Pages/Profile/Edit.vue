<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import SubscriptionSummary from './Partials/SubscriptionSummary.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { Head } from '@inertiajs/vue3';
import { onMounted } from 'vue';

// "Ver mi suscripción" del menú de cuenta lleva a /profile#suscripcion — el
// scroll automático de Inertia al hash no es parejo entre versiones, así que
// lo forzamos acá como respaldo.
onMounted(() => {
    if (window.location.hash) {
        document.getElementById(window.location.hash.slice(1))?.scrollIntoView({ behavior: 'smooth' });
    }
});

defineProps({
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
});
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
