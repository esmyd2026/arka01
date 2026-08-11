<script setup>
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    feedback: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const statusFilter = ref(props.filters.status ?? '');
const typeFilter = ref(props.filters.type ?? '');

const STATUS_LABEL = {
    nueva: 'Nueva',
    revisando: 'Revisando',
    considerada: 'Considerada',
    implementada: 'Implementada',
    descartada: 'Descartada',
};
const TYPE_LABEL = { sugerencia: 'Sugerencia', problema: 'Problema', nueva_idea: 'Nueva idea', otro: 'Otro' };

function applyFilters() {
    router.get(route('admin.platform-feedback.index'), { status: statusFilter.value, type: typeFilter.value }, { preserveState: true, replace: true });
}

const editingId = ref(null);
const form = useForm({ status: '', internal_notes: '' });

function startEdit(item) {
    editingId.value = item.id;
    form.status = item.status;
    form.internal_notes = item.internal_notes ?? '';
}

function save(id) {
    form.patch(route('admin.platform-feedback.update', id), { onSuccess: () => (editingId.value = null), preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · Opiniones" />

    <AdminLayout title="Opiniones">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Lo que mandan los visitantes desde "Ayúdanos a mejorar ARKA01" en la página pública.
                </p>

                <div class="bg-arka-card shadow rounded-arka p-4 sm:p-6 flex flex-wrap items-center gap-3">
                    <select v-model="statusFilter" class="rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text text-sm" @change="applyFilters">
                        <option value="">Todos los estados</option>
                        <option v-for="(label, value) in STATUS_LABEL" :key="value" :value="value">{{ label }}</option>
                    </select>
                    <select v-model="typeFilter" class="rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text text-sm" @change="applyFilters">
                        <option value="">Todos los tipos</option>
                        <option v-for="(label, value) in TYPE_LABEL" :key="value" :value="value">{{ label }}</option>
                    </select>
                    <span class="ml-auto text-xs text-arka-text-muted">{{ feedback.total }} opinión(es)</span>
                </div>

                <p v-if="!feedback.data.length" class="text-sm text-arka-text-muted">No hay opiniones con ese filtro.</p>

                <ul v-else class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <li v-for="item in feedback.data" :key="item.id" class="p-4 sm:p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap text-xs text-arka-text-muted">
                                    <span class="px-2 py-0.5 rounded-full bg-arka-primary/10 text-arka-primary-bright">{{ TYPE_LABEL[item.type] }}</span>
                                    <span>{{ STATUS_LABEL[item.status] }}</span>
                                    <span>{{ new Date(item.created_at).toLocaleString('es-EC', { dateStyle: 'medium', timeStyle: 'short' }) }}</span>
                                </div>
                                <p class="mt-1 text-arka-text">{{ item.comment }}</p>
                                <p class="mt-1 text-xs text-arka-text-muted">
                                    {{ item.name ?? 'Anónimo' }}<span v-if="item.email"> · {{ item.email }}</span>
                                </p>
                                <p v-if="item.internal_notes" class="mt-1 text-xs text-arka-lime">Nota interna: {{ item.internal_notes }}</p>
                            </div>
                            <SecondaryButton class="shrink-0" @click="startEdit(item)">Clasificar</SecondaryButton>
                        </div>

                        <form v-if="editingId === item.id" @submit.prevent="save(item.id)" class="mt-3 space-y-2 border-t border-arka-text-muted/10 pt-3">
                            <select v-model="form.status" class="block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text text-sm">
                                <option v-for="(label, value) in STATUS_LABEL" :key="value" :value="value">{{ label }}</option>
                            </select>
                            <textarea
                                v-model="form.internal_notes"
                                rows="2"
                                placeholder="Nota interna (no la ve quien mandó la opinión)"
                                class="block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text text-sm"
                            ></textarea>
                            <div class="flex gap-2">
                                <SecondaryButton type="submit" :disabled="form.processing">Guardar</SecondaryButton>
                                <SecondaryButton type="button" @click="editingId = null">Cancelar</SecondaryButton>
                            </div>
                        </form>
                    </li>
                </ul>

                <div v-if="feedback.prev_page_url || feedback.next_page_url" class="flex justify-between">
                    <Link v-if="feedback.prev_page_url" :href="feedback.prev_page_url" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                        &larr; Anterior
                    </Link>
                    <span v-else></span>
                    <Link v-if="feedback.next_page_url" :href="feedback.next_page_url" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                        Siguiente &rarr;
                    </Link>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
