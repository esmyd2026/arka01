<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import UserAvatar from '@/Components/UserAvatar.vue';

const props = defineProps({
    channel: { type: Object, required: true },
    shareCode: { type: String, required: true },
    canJoin: { type: Boolean, required: true },
    alreadyMember: { type: Boolean, required: true },
    isOwner: { type: Boolean, required: true },
});

const loggedIn = Boolean(usePage().props.auth.user);

function join() {
    router.post(route('radio.invitation.join', props.shareCode));
}
</script>

<template>
    <Head :title="`Invitación a ${channel.name}`" />

    <main class="arka-app-background flex min-h-screen items-center justify-center px-4 py-10">
        <section class="w-full max-w-md rounded-arka border border-arka-border bg-arka-card p-6 text-center shadow-2xl">
            <Link href="/" class="mb-7 inline-flex"><ApplicationLogo size="h-9" /></Link>

            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-arka-primary">Canal de seguridad privado</p>
            <UserAvatar :user="channel.owner" size-class="mx-auto mt-5 h-20 w-20 text-2xl" />
            <h1 class="mt-4 text-xl font-bold text-arka-text">{{ channel.name }}</h1>
            <p class="mt-1 text-sm text-arka-text-muted">
                Creado por {{ channel.owner.name }} · {{ channel.owner.role }}
            </p>

            <div class="mt-5 rounded-arka bg-arka-surface p-4 text-left text-sm text-arka-text-muted">
                Este canal solo se activa cuando su propietario solicita o realiza una carrera. La voz se transmite en vivo y no queda grabada.
            </div>

            <p class="mt-4 text-sm text-arka-text-muted">{{ channel.member_count }} integrante(s) autorizado(s)</p>

            <p v-if="isOwner" class="mt-5 text-sm font-medium text-arka-primary">Este es su canal principal.</p>
            <p v-else-if="alreadyMember" class="mt-5 text-sm font-medium text-arka-primary">Ya forma parte de este canal.</p>
            <PrimaryButton v-else-if="canJoin" class="mt-5 w-full justify-center" @click="join">Unirme al canal</PrimaryButton>

            <div v-else-if="!loggedIn" class="mt-5 grid gap-3 sm:grid-cols-2">
                <Link :href="route('login')" class="rounded-arka border border-arka-primary px-4 py-3 text-sm font-semibold text-arka-primary">Iniciar sesión</Link>
                <Link :href="route('register')" class="rounded-arka bg-arka-primary px-4 py-3 text-sm font-semibold text-arka-card">Crear cuenta</Link>
            </div>
            <p v-else class="mt-5 text-sm text-arka-text-muted">Este canal solo admite cuentas de cliente o conductor.</p>
        </section>
    </main>
</template>
