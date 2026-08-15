<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    cooperative: { type: Object, required: true },
    averageRating: { type: Number, default: null },
    isAttached: { type: Boolean, required: true },
});

const isClient = usePage().props.auth?.isClient ?? false;

function toggle() {
    if (props.isAttached) router.delete(route('cooperatives.detach', props.cooperative.id));
    else router.post(route('cooperatives.attach', props.cooperative.id));
}
</script>

<template>
    <GuestLayout>
        <Head :title="cooperative.name || 'Cooperativa'" />
        <div class="w-full max-w-3xl overflow-hidden rounded-arka border border-arka-text-muted/10 bg-arka-card shadow-2xl">
            <div class="bg-gradient-to-br from-arka-primary/25 via-arka-card to-arka-lime/10 p-6 sm:p-9">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                    <img v-if="cooperative.logo_url" :src="cooperative.logo_url" class="h-24 w-24 rounded-2xl bg-white object-contain p-2" alt="Logo" />
                    <div v-else class="grid h-24 w-24 place-items-center rounded-2xl bg-arka-primary/15 text-4xl font-bold text-arka-primary">{{ cooperative.name?.charAt(0) }}</div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-arka-primary-bright">✓ Cooperativa verificada</p>
                        <h1 class="mt-2 text-2xl font-bold text-arka-text">{{ cooperative.name }}</h1>
                        <p class="mt-1 text-sm text-arka-text-muted">{{ cooperative.city?.name }} · {{ cooperative.province }}</p>
                        <p v-if="averageRating" class="mt-2 text-sm text-arka-lime">★ {{ averageRating }} promedio de sus conductores</p>
                    </div>
                </div>
            </div>
            <div class="grid gap-5 p-6 sm:grid-cols-2 sm:p-9">
                <div><p class="text-xs uppercase text-arka-text-muted">Cobertura</p><p class="mt-1 text-sm text-arka-text">{{ cooperative.geographic_coverage }}</p></div>
                <div><p class="text-xs uppercase text-arka-text-muted">Horario</p><p class="mt-1 text-sm text-arka-text">{{ cooperative.operating_hours }}</p></div>
                <div><p class="text-xs uppercase text-arka-text-muted">Conductores vinculados</p><p class="mt-1 text-2xl font-semibold text-arka-text">{{ cooperative.active_driver_memberships.length }}</p></div>
                <div><p class="text-xs uppercase text-arka-text-muted">Unidades declaradas</p><p class="mt-1 text-2xl font-semibold text-arka-text">{{ cooperative.declared_unit_count }}</p></div>
                <div class="sm:col-span-2 flex justify-end">
                    <SecondaryButton v-if="isClient && isAttached" @click="toggle">Retirar de mi red</SecondaryButton>
                    <PrimaryButton v-else-if="isClient" @click="toggle">Agregar a mi red de confianza</PrimaryButton>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>
