<script setup>
import { reactive } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    clients: { type: Object, required: true },
    cities: { type: Array, required: true },
    filters: { type: Object, required: true },
});

// Mismo criterio que Admin/Drivers.vue, del otro lado (pedido explícito del
// usuario): buscar/filtrar por ciudad, ver fecha de registro y última
// actividad real, sin traer todos los clientes de una sola vez.
const form = reactive({
    q: props.filters.q ?? '',
    city_id: props.filters.city_id ?? '',
});

function applyFilters() {
    router.get(route('admin.clients.index'), form, { preserveState: true, preserveScroll: true, replace: true });
}

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('es-EC', { day: '2-digit', month: 'short', year: 'numeric' });
}
</script>

<template>
    <Head title="Admin · Clientes" />

    <AdminLayout title="Clientes">
        <div class="py-12">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <h3 class="text-lg font-medium text-arka-text">Clientes registrados ({{ clients.total }})</h3>

                    <form @submit.prevent="applyFilters" class="flex flex-wrap items-end gap-3">
                        <div class="flex-1 min-w-[160px]">
                            <TextInput v-model="form.q" placeholder="Nombre o correo" class="w-full" />
                        </div>
                        <select v-model="form.city_id" class="rounded-arka border-arka-text-muted/30 bg-arka-card text-arka-text text-sm">
                            <option value="">Todas las ciudades</option>
                            <option v-for="city in cities" :key="city.id" :value="city.id">{{ city.name }}</option>
                        </select>
                        <PrimaryButton type="submit">Filtrar</PrimaryButton>
                    </form>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-arka-text-muted border-b border-arka-text-muted/10">
                                    <th class="py-2 pr-3">Cliente</th>
                                    <th class="py-2 pr-3">Ciudad</th>
                                    <th class="py-2 pr-3">Conductores en su flota</th>
                                    <th class="py-2 pr-3">Carreras pedidas</th>
                                    <th class="py-2 pr-3">Registro</th>
                                    <th class="py-2 pr-3">Última actividad</th>
                                    <th class="py-2"></th>
                                    <th class="py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-arka-text-muted/10">
                                <tr v-for="c in clients.data" :key="c.id">
                                    <td class="py-2 pr-3">
                                        <Link :href="route('admin.users.show', c.id)" class="text-arka-text font-medium hover:text-arka-primary-bright">
                                            {{ c.name }}
                                        </Link>
                                        <p class="text-xs text-arka-text-muted">{{ c.email }}</p>
                                    </td>
                                    <td class="py-2 pr-3 text-arka-text-muted">{{ c.city ?? '—' }}</td>
                                    <td class="py-2 pr-3 text-arka-text">{{ c.drivers_count }}</td>
                                    <td class="py-2 pr-3 text-arka-text">{{ c.completed_rides_count }}</td>
                                    <td class="py-2 pr-3 text-arka-text-muted">{{ formatDate(c.registered_at) }}</td>
                                    <td class="py-2 pr-3 text-arka-text-muted">{{ formatDate(c.last_active_at) }}</td>
                                    <td class="py-2">
                                        <span v-if="c.is_locked" class="text-arka-danger font-medium">Bloqueada</span>
                                    </td>
                                    <td class="py-2">
                                        <Link :href="route('admin.clients.show', c.id)" class="text-xs text-arka-primary hover:text-arka-primary-bright whitespace-nowrap">
                                            Ver WhatsApp
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <p v-if="!clients.data.length" class="py-6 text-center text-sm text-arka-text-muted">
                            No hay clientes que coincidan con estos filtros.
                        </p>
                    </div>

                    <!-- Paginado simple: nunca se carga todo de una vez (sección 9.7) -->
                    <div v-if="clients.prev_page_url || clients.next_page_url" class="flex justify-between pt-2">
                        <Link
                            v-if="clients.prev_page_url"
                            :href="clients.prev_page_url"
                            preserve-state
                            class="text-sm text-arka-primary hover:text-arka-primary-bright"
                        >
                            &larr; Anterior
                        </Link>
                        <span v-else></span>

                        <Link
                            v-if="clients.next_page_url"
                            :href="clients.next_page_url"
                            preserve-state
                            class="text-sm text-arka-primary hover:text-arka-primary-bright"
                        >
                            Siguiente &rarr;
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
