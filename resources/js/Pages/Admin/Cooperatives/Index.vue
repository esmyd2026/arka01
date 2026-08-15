<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ cooperatives: { type: Object, required: true }, filters: { type: Object, required: true } });
const q = ref(props.filters.q ?? '');
const status = ref(props.filters.status ?? '');
function search() { router.get(route('admin.cooperatives.index'), { q: q.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true }); }
</script>

<template>
    <Head title="Admin · Cooperativas" />
    <AdminLayout title="Gestión de cooperativas">
        <div class="py-10"><div class="mx-auto max-w-6xl space-y-5 px-4 sm:px-6">
            <form class="grid gap-3 rounded-arka bg-arka-card p-4 sm:grid-cols-[1fr_14rem_auto]" @submit.prevent="search">
                <TextInput v-model="q" placeholder="Nombre, razón social o RUC" />
                <select v-model="status" class="rounded-arka border-arka-text-muted/20 bg-arka-base text-arka-text"><option value="">Todos los estados</option><option value="pending">Pendiente</option><option value="in_review">En revisión</option><option value="approved">Aprobada</option><option value="rejected">Rechazada</option><option value="suspended">Suspendida</option></select>
                <PrimaryButton>Filtrar</PrimaryButton>
            </form>
            <div class="overflow-x-auto rounded-arka bg-arka-card shadow-lg">
                <table class="min-w-full text-sm"><thead class="bg-arka-base/60 text-left text-xs uppercase text-arka-text-muted"><tr><th class="p-4">Cooperativa</th><th class="p-4">Estado</th><th class="p-4">Capacidad</th><th class="p-4">Documentos</th><th class="p-4"></th></tr></thead>
                    <tbody class="divide-y divide-arka-text-muted/10"><tr v-for="item in cooperatives.data" :key="item.id"><td class="p-4"><p class="font-medium text-arka-text">{{ item.name || 'Registro incompleto' }}</p><p class="text-xs text-arka-text-muted">{{ item.ruc || 'Sin RUC' }} · {{ item.city?.name || 'Sin ciudad' }}</p></td><td class="p-4 text-arka-text-muted">{{ item.status }}</td><td class="p-4 text-arka-text-muted">{{ item.active_driver_memberships_count }} vinculados · {{ item.declared_unit_count }} unidades</td><td class="p-4 text-arka-text-muted">{{ item.documents_count }}</td><td class="p-4 text-right"><Link :href="route('admin.cooperatives.show', item.id)" class="font-medium text-arka-primary">Revisar →</Link></td></tr></tbody>
                </table>
                <p v-if="!cooperatives.data.length" class="p-8 text-center text-arka-text-muted">No hay resultados.</p>
            </div>
            <div class="flex justify-between text-sm"><Link v-if="cooperatives.prev_page_url" :href="cooperatives.prev_page_url" class="text-arka-primary">← Anterior</Link><span v-else/><Link v-if="cooperatives.next_page_url" :href="cooperatives.next_page_url" class="text-arka-primary">Siguiente →</Link></div>
        </div></div>
    </AdminLayout>
</template>
