<script setup>
import { reactive } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    referrals: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const form = reactive({
    q: props.filters.q ?? '',
});

function applyFilters() {
    router.get(route('admin.referrals.index'), form, { preserveState: true, preserveScroll: true, replace: true });
}

function formatDate(value) {
    return new Date(value).toLocaleDateString('es-EC', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Head title="Admin · Referidos" />

    <AdminLayout title="Referidos">
        <div class="py-12">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <div>
                        <h3 class="text-lg font-medium text-arka-text">Trazabilidad de referidos ({{ referrals.total }})</h3>
                        <p class="text-sm text-arka-text-muted">
                            Cuentas que se registraron a través de un enlace compartido por otro usuario — invitación de
                            flota de un conductor o perfil público (sección 3.6 y 9.9).
                        </p>
                    </div>

                    <form @submit.prevent="applyFilters" class="flex flex-wrap items-end gap-3">
                        <div class="flex-1 min-w-[200px]">
                            <TextInput v-model="form.q" placeholder="Nombre de referido o de quien invitó" class="w-full" />
                        </div>
                        <PrimaryButton type="submit">Filtrar</PrimaryButton>
                    </form>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-arka-text-muted border-b border-arka-text-muted/10">
                                    <th class="py-2 pr-3">Se registró</th>
                                    <th class="py-2 pr-3">Invitado por</th>
                                    <th class="py-2 pr-3">Fecha</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-arka-text-muted/10">
                                <tr v-for="r in referrals.data" :key="r.id">
                                    <td class="py-2 pr-3">
                                        <Link :href="route('admin.users.show', r.id)" class="text-arka-text font-medium hover:text-arka-primary-bright">
                                            {{ r.name }}
                                        </Link>
                                        <span class="ms-1.5 text-xs text-arka-text-muted">({{ r.role }})</span>
                                    </td>
                                    <td class="py-2 pr-3">
                                        <Link
                                            :href="route('admin.users.show', r.referred_by.id)"
                                            class="text-arka-text font-medium hover:text-arka-primary-bright"
                                        >
                                            {{ r.referred_by.name }}
                                        </Link>
                                        <span class="ms-1.5 text-xs text-arka-text-muted">({{ r.referred_by.role }})</span>
                                    </td>
                                    <td class="py-2 pr-3 text-arka-text-muted">{{ formatDate(r.registered_at) }}</td>
                                </tr>
                            </tbody>
                        </table>

                        <p v-if="!referrals.data.length" class="py-6 text-center text-sm text-arka-text-muted">
                            Todavía no hay referidos registrados.
                        </p>
                    </div>

                    <div v-if="referrals.prev_page_url || referrals.next_page_url" class="flex justify-between pt-2">
                        <Link
                            v-if="referrals.prev_page_url"
                            :href="referrals.prev_page_url"
                            preserve-state
                            class="text-sm text-arka-primary hover:text-arka-primary-bright"
                        >
                            &larr; Anterior
                        </Link>
                        <span v-else></span>

                        <Link
                            v-if="referrals.next_page_url"
                            :href="referrals.next_page_url"
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
