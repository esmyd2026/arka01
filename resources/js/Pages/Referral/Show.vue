<script setup>
import { ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import RatingStars from '@/Components/RatingStars.vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

// "Referí a tu conductor" (pedido explícito del usuario): página pública,
// sin sesión — igual que Public/RideTracking.vue, no usa AuthenticatedLayout
// a propósito, porque quien la abre puede no tener cuenta todavía.
const props = defineProps({
    driver: { type: Object, required: true },
    inviteCode: { type: String, required: true },
    canInvite: { type: Boolean, required: true },
    alreadyMember: { type: Boolean, required: true },
});

const isLoggedIn = !!usePage().props.auth.user;
const sent = ref(false);
const processing = ref(false);

function invite() {
    processing.value = true;
    router.post(
        route('referrals.store', props.inviteCode),
        {},
        {
            preserveScroll: true,
            onSuccess: () => (sent.value = true),
            onFinish: () => (processing.value = false),
        }
    );
}
</script>

<template>
    <Head :title="`${driver.name} lo invita a Arka01`" />

    <div class="min-h-screen bg-arka-base flex flex-col items-center justify-center px-4 py-12">
        <Link href="/" class="mb-6">
            <ApplicationLogo size="h-8" />
        </Link>

        <div class="w-full max-w-sm bg-arka-card shadow-md rounded-arka p-6 text-center space-y-4">
            <p class="text-sm text-arka-text-muted">Te recomendaron viajar con</p>

            <div class="flex flex-col items-center gap-2">
                <UserAvatar :user="driver" size-class="h-20 w-20 text-2xl" />
                <p class="text-lg font-semibold text-arka-text flex items-center gap-1.5">
                    {{ driver.name }}
                    <span v-if="driver.is_verified" class="text-arka-primary-bright text-sm" title="Conductor verificado">✓</span>
                </p>
                <RatingStars v-if="driver.review_count > 0" :rating="driver.average_rating" :count="driver.review_count" readonly />
                <p v-if="driver.vehicle_make" class="text-sm text-arka-text-muted">
                    {{ driver.vehicle_make }} {{ driver.vehicle_model }}
                </p>
            </div>

            <p class="text-sm text-arka-text-muted">
                En Arka01 arma su propia flota de conductores de confianza y pide sus viajes dentro de ese círculo,
                sin comisión de la plataforma.
            </p>

            <template v-if="alreadyMember">
                <p class="text-sm text-arka-primary-bright font-medium">
                    Ya es parte de su flota de confianza.
                </p>
            </template>

            <template v-else-if="sent">
                <p class="text-sm text-arka-primary-bright font-medium">
                    ¡Listo! Le mandamos la invitación — la ve apenas entre.
                </p>
            </template>

            <template v-else-if="canInvite">
                <PrimaryButton class="w-full justify-center" :disabled="processing" @click="invite">
                    Agregar a mi flota
                </PrimaryButton>
            </template>

            <template v-else-if="isLoggedIn">
                <p class="text-sm text-arka-text-muted italic">
                    Esto es para cuentas de pasajero — con esta cuenta no puede agregarlo a una flota.
                </p>
            </template>

            <template v-else>
                <div class="space-y-2">
                    <Link :href="route('register')" class="block">
                        <PrimaryButton class="w-full justify-center">Crear cuenta y agregarlo</PrimaryButton>
                    </Link>
                    <Link :href="route('login')" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                        ¿Ya tiene cuenta? Inicie sesión
                    </Link>
                </div>
            </template>
        </div>
    </div>
</template>
