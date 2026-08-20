<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FleetRoster from '@/Components/FleetRoster.vue';
import { Head, Link } from '@inertiajs/vue3';

// Pedido explícito del usuario: "Mis flotas" (Fleet/List.vue) ya muestra el
// roster completo de cada flota de una, sin tener que entrar acá — esta
// pantalla queda solo como respaldo por si algo llega a enlazar directo a
// una flota puntual (toda la lógica real vive en Components/FleetRoster.vue,
// compartida con List.vue para no duplicarla).
defineProps({
    fleet: { type: Object, required: true },
    maxDriversPerFleet: { type: Number, default: null },
    memberStats: { type: Object, required: true },
});
</script>

<template>
    <Head :title="fleet.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <Link :href="route('fleet.index')" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                        &larr; Mis flotas
                    </Link>
                    <h2 class="font-semibold text-xl text-arka-text leading-tight">{{ fleet.name }}</h2>
                </div>
                <span class="text-sm text-arka-text-muted">
                    {{ fleet.active_members?.length ?? 0 }} de {{ maxDriversPerFleet ?? '∞' }} conductores
                </span>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                <FleetRoster :fleet="fleet" :max-drivers-per-fleet="maxDriversPerFleet" :member-stats="memberStats" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
