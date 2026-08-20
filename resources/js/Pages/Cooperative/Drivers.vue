<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    cooperative: { type: Object, required: true },
    memberships: { type: Array, required: true },
    planLimits: { type: Object, required: true },
});

const query = ref('');
const results = ref([]);
const searching = ref(false);

async function search() {
    if (query.value.trim().length < 2) return;
    searching.value = true;
    try {
        const response = await window.axios.get(route('cooperative.drivers.search'), { params: { q: query.value } });
        results.value = response.data;
    } finally {
        searching.value = false;
    }
}

function invite(driver) {
    router.post(route('cooperative.drivers.invite'), { driver_user_id: driver.id }, { preserveScroll: true, onSuccess: () => (driver.membership_status = 'pending') });
}
</script>

<template>
    <Head title="Conductores de la cooperativa" />
    <AuthenticatedLayout>
        <template #header><h2 class="text-xl font-semibold text-arka-text">Conductores de {{ cooperative.name }}</h2></template>
        <div class="py-8 sm:py-12">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6">
                <section class="rounded-arka border border-arka-text-muted/10 bg-arka-card p-5 shadow-lg">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="flex-1">
                            <label class="text-sm font-medium text-arka-text">Buscar conductor por nombre, usuario o código</label>
                            <TextInput v-model="query" class="mt-2 w-full" placeholder="Ej. Juan, jperez o ARK-0001" @keydown.enter.prevent="search" />
                        </div>
                        <PrimaryButton :disabled="searching || query.trim().length < 2" @click="search">{{ searching ? 'Buscando…' : 'Buscar' }}</PrimaryButton>
                    </div>
                    <p class="mt-3 text-xs text-arka-text-muted">Plan {{ planLimits.plan_name }}: {{ planLimits.max_units ?? 'sin límite' }} unidades/conductores. La invitación nunca crea el vínculo automáticamente.</p>
                    <div v-if="results.length" class="mt-4 divide-y divide-arka-text-muted/10 rounded-arka border border-arka-text-muted/10">
                        <div v-for="driver in results" :key="driver.id" class="flex items-center gap-3 p-3">
                            <UserAvatar :user="driver" size-class="h-10 w-10 text-xs shrink-0" />
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-arka-text">{{ driver.name }}</p>
                                <p class="text-xs text-arka-text-muted">{{ driver.member_code }} · {{ driver.verification_status === 'approved' ? 'verificado' : 'verificación pendiente' }}</p>
                            </div>
                            <PrimaryButton v-if="!driver.membership_status || ['rejected', 'removed'].includes(driver.membership_status)" @click="invite(driver)">Invitar</PrimaryButton>
                            <span v-else class="text-xs text-arka-primary">{{ driver.membership_status }}</span>
                        </div>
                    </div>
                </section>

                <section class="rounded-arka bg-arka-card shadow-lg">
                    <div class="border-b border-arka-text-muted/10 p-5"><h3 class="font-semibold text-arka-text">Vínculos actuales</h3></div>
                    <p v-if="!memberships.length" class="p-6 text-sm text-arka-text-muted">Aún no hay conductores vinculados o invitados.</p>
                    <div v-else class="divide-y divide-arka-text-muted/10">
                        <div v-for="membership in memberships" :key="membership.id" class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center">
                            <UserAvatar :user="membership.driver" size-class="h-11 w-11 text-sm shrink-0" />
                            <div class="flex-1">
                                <p class="font-medium text-arka-text">{{ membership.driver.name }}</p>
                                <p class="mt-1 text-xs text-arka-text-muted">
                                    {{ membership.status }}
                                    <span v-if="membership.driver.driver_profile?.is_available"> · disponible ahora</span>
                                    <span v-if="membership.driver.driver_profile?.vehicle_plate"> · {{ membership.driver.driver_profile.vehicle_plate }}</span>
                                </p>
                            </div>
                            <div class="flex gap-2">
                                <Link :href="route('cooperative.drivers.show', membership.id)" class="inline-flex items-center rounded-lg border border-arka-primary/40 px-3 py-2 text-xs font-semibold uppercase tracking-wider text-arka-primary transition hover:bg-arka-primary/10">Ver perfil</Link>
                                <SecondaryButton v-if="membership.status === 'accepted'" @click="router.post(route('cooperative.drivers.suspend', membership.id))">Suspender</SecondaryButton>
                                <PrimaryButton v-if="membership.status === 'suspended'" @click="router.post(route('cooperative.drivers.reactivate', membership.id))">Reactivar</PrimaryButton>
                                <DangerButton @click="router.delete(route('cooperative.drivers.remove', membership.id))">Retirar</DangerButton>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
