<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    cooperatives: { type: Object, required: true },
    filters: { type: Object, required: true },
    cities: { type: Array, required: true },
});

const q = ref(props.filters.q ?? '');
const cityId = ref(props.filters.city_id ?? '');
const isClient = usePage().props.auth.isClient;

function search() {
    router.get(route('cooperatives.index'), { q: q.value || undefined, city_id: cityId.value || undefined }, { preserveState: true, replace: true });
}

function toggle(cooperative) {
    if (cooperative.is_attached) {
        router.delete(route('cooperatives.detach', cooperative.id), { preserveScroll: true, onSuccess: () => (cooperative.is_attached = false) });
    } else {
        router.post(route('cooperatives.attach', cooperative.id), {}, { preserveScroll: true, onSuccess: () => (cooperative.is_attached = true) });
    }
}
</script>

<template>
    <Head title="Cooperativas verificadas" />
    <AuthenticatedLayout>
        <template #header>
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-arka-primary">Red regulada de confianza</p>
                <h2 class="mt-1 text-xl font-semibold text-arka-text">Cooperativas verificadas</h2>
            </div>
        </template>

        <div class="py-8 sm:py-12">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6">
                <form class="grid gap-3 rounded-arka border border-arka-text-muted/10 bg-arka-card p-4 shadow-lg sm:grid-cols-[1fr_16rem_auto]" @submit.prevent="search">
                    <TextInput v-model="q" placeholder="Buscar por nombre, razón social o cobertura" class="w-full" />
                    <select v-model="cityId" class="rounded-arka border-arka-text-muted/20 bg-arka-base text-arka-text focus:border-arka-primary focus:ring-arka-primary">
                        <option value="">Todas las ciudades</option>
                        <option v-for="city in cities" :key="city.id" :value="city.id">{{ city.name }}</option>
                    </select>
                    <PrimaryButton>Buscar</PrimaryButton>
                </form>

                <div v-if="!cooperatives.data.length" class="rounded-arka bg-arka-card p-8 text-center text-arka-text-muted">
                    No hay cooperativas aprobadas con esos filtros.
                </div>

                <div v-else class="grid gap-4 sm:grid-cols-2">
                    <article v-for="cooperative in cooperatives.data" :key="cooperative.id" class="rounded-arka border border-arka-text-muted/10 bg-arka-card p-5 shadow-lg">
                        <div class="flex items-start gap-4">
                            <img v-if="cooperative.logo_url" :src="cooperative.logo_url" :alt="cooperative.name" class="h-14 w-14 rounded-xl bg-white object-contain p-1" />
                            <div v-else class="grid h-14 w-14 shrink-0 place-items-center rounded-xl bg-arka-primary/15 text-xl font-bold text-arka-primary">{{ cooperative.name.charAt(0) }}</div>
                            <div class="min-w-0 flex-1">
                                <Link :href="route('cooperatives.show', cooperative.id)" class="font-semibold text-arka-text hover:text-arka-primary-bright">{{ cooperative.name }}</Link>
                                <p class="mt-1 text-xs font-medium text-arka-primary-bright">✓ Cooperativa verificada</p>
                                <p class="mt-2 text-sm text-arka-text-muted">{{ cooperative.city }}<span v-if="cooperative.province"> · {{ cooperative.province }}</span></p>
                            </div>
                        </div>
                        <p class="mt-4 line-clamp-2 text-sm text-arka-text-muted">{{ cooperative.coverage }}</p>
                        <div class="mt-4 flex flex-wrap gap-2 text-xs text-arka-text-muted">
                            <span class="rounded-full bg-arka-base px-2.5 py-1">{{ cooperative.driver_count }} conductores</span>
                            <span class="rounded-full bg-arka-base px-2.5 py-1">{{ cooperative.unit_count }} unidades</span>
                            <span class="rounded-full bg-arka-base px-2.5 py-1">{{ cooperative.client_count }} clientes</span>
                        </div>
                        <div class="mt-5 flex items-center justify-between gap-3">
                            <Link :href="route('cooperatives.show', cooperative.id)" class="text-sm font-medium text-arka-primary hover:text-arka-primary-bright">Ver perfil →</Link>
                            <SecondaryButton v-if="isClient" @click="toggle(cooperative)">{{ cooperative.is_attached ? 'Retirar de mi red' : 'Agregar a mi red' }}</SecondaryButton>
                        </div>
                    </article>
                </div>

                <div class="flex justify-between text-sm">
                    <Link v-if="cooperatives.prev_page_url" :href="cooperatives.prev_page_url" class="text-arka-primary">← Anterior</Link><span v-else />
                    <Link v-if="cooperatives.next_page_url" :href="cooperatives.next_page_url" class="text-arka-primary">Siguiente →</Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
