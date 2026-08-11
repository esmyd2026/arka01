<script setup>
import { computed, ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    tickets: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const statusFilter = ref(props.filters.status ?? '');

const STATUS_LABEL = {
    nuevo: 'Nuevo',
    en_atencion: 'En atención',
    esperando_usuario: 'Esperando usuario',
    resuelto: 'Resuelto',
    cerrado: 'Cerrado',
};
// Bug real reportado por el usuario (con captura): el <select> nativo se
// pinta con el tema del sistema operativo, no con el oscuro de la app.
const STATUS_OPTIONS = computed(() => Object.entries(STATUS_LABEL).map(([value, label]) => ({ value, label })));
const STATUS_CLASS = {
    nuevo: 'bg-arka-primary/15 text-arka-primary-bright',
    en_atencion: 'bg-arka-warning/15 text-arka-warning',
    esperando_usuario: 'bg-arka-text-muted/15 text-arka-text-muted',
    resuelto: 'bg-arka-lime/15 text-arka-lime',
    cerrado: 'bg-arka-text-muted/10 text-arka-text-muted',
};

function applyFilter() {
    router.get(route('admin.support-tickets.index'), { status: statusFilter.value }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Admin · Soporte" />

    <AdminLayout title="Soporte">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="bg-arka-card shadow rounded-arka p-4 sm:p-6 flex items-center gap-3">
                    <SearchableSelect
                        v-model="statusFilter"
                        :options="STATUS_OPTIONS"
                        empty-label="Todos los estados"
                        class="w-48"
                        @update:model-value="applyFilter"
                    />
                    <span class="ml-auto text-xs text-arka-text-muted">{{ tickets.total }} ticket(s)</span>
                </div>

                <p v-if="!tickets.data.length" class="text-sm text-arka-text-muted">No hay tickets con ese filtro.</p>

                <ul v-else class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <li v-for="ticket in tickets.data" :key="ticket.id">
                        <Link :href="route('admin.support-tickets.show', ticket.id)" class="p-4 sm:p-6 flex items-center gap-4 hover:bg-arka-base/40">
                            <UserAvatar :user="ticket.user" size-class="h-10 w-10 text-sm shrink-0" />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="text-arka-text font-medium">{{ ticket.user.name }}</p>
                                    <span class="px-2 py-0.5 rounded-full text-xs" :class="STATUS_CLASS[ticket.status]">
                                        {{ STATUS_LABEL[ticket.status] }}
                                    </span>
                                </div>
                                <p v-if="ticket.messages[0]" class="text-sm text-arka-text-muted truncate">
                                    {{ ticket.messages[0].body }}
                                </p>
                                <p class="text-xs text-arka-text-muted">{{ ticket.messages_count }} mensaje(s)</p>
                            </div>
                            <span class="text-xs text-arka-text-muted shrink-0">
                                {{ new Date(ticket.updated_at).toLocaleString('es-EC', { dateStyle: 'medium', timeStyle: 'short' }) }}
                            </span>
                        </Link>
                    </li>
                </ul>

                <div v-if="tickets.prev_page_url || tickets.next_page_url" class="flex justify-between">
                    <Link v-if="tickets.prev_page_url" :href="tickets.prev_page_url" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                        &larr; Anterior
                    </Link>
                    <span v-else></span>
                    <Link v-if="tickets.next_page_url" :href="tickets.next_page_url" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                        Siguiente &rarr;
                    </Link>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
