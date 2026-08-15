<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, router } from '@inertiajs/vue3';

defineProps({ memberships: { type: Array, required: true } });

function respond(id, decision) {
    router.post(route('cooperative-driver-invitations.respond', id), { decision }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Invitaciones de cooperativas" />
    <AuthenticatedLayout>
        <template #header><h2 class="text-xl font-semibold text-arka-text">Mis cooperativas</h2></template>
        <div class="py-8 sm:py-12">
            <div class="mx-auto max-w-3xl space-y-4 px-4 sm:px-6">
                <p class="text-sm text-arka-text-muted">Ninguna cooperativa puede vincularlo automáticamente. Usted decide qué invitación aceptar.</p>
                <div v-if="!memberships.length" class="rounded-arka bg-arka-card p-7 text-center text-arka-text-muted">No tiene invitaciones ni vínculos activos.</div>
                <article v-for="membership in memberships" :key="membership.id" class="rounded-arka border border-arka-text-muted/10 bg-arka-card p-5 shadow-lg">
                    <div class="flex items-start gap-4">
                        <img v-if="membership.cooperative.logo_url" :src="membership.cooperative.logo_url" class="h-14 w-14 rounded-xl bg-white object-contain p-1" alt="Logo" />
                        <div class="flex-1">
                            <p class="font-semibold text-arka-text">{{ membership.cooperative.name }}</p>
                            <p class="mt-1 text-sm text-arka-text-muted">{{ membership.cooperative.city?.name }} · {{ membership.cooperative.geographic_coverage }}</p>
                            <span class="mt-3 inline-block rounded-full bg-arka-base px-2.5 py-1 text-xs text-arka-text-muted">{{ membership.status }}</span>
                        </div>
                    </div>
                    <div v-if="membership.status === 'pending'" class="mt-5 flex justify-end gap-2">
                        <DangerButton @click="respond(membership.id, 'reject')">Rechazar</DangerButton>
                        <PrimaryButton @click="respond(membership.id, 'accept')">Aceptar vínculo</PrimaryButton>
                    </div>
                </article>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
