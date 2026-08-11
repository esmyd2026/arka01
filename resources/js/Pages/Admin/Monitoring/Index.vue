<script setup>
import { computed, ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    events: { type: Object, required: true },
    filters: { type: Object, required: true },
    modules: { type: Array, required: true },
});

// Bug real reportado por el usuario (con captura): el <select> nativo se
// pinta con el tema del sistema operativo, no con el oscuro de la app.
const moduleOptions = computed(() => props.modules.map((m) => ({ value: m, label: m })));
const SEVERITY_OPTIONS = [
    { value: 'info', label: 'Info' },
    { value: 'warning', label: 'Warning' },
    { value: 'error', label: 'Error' },
    { value: 'critical', label: 'Critical' },
];
const STATUS_OPTIONS = [
    { value: 'failed', label: 'Sin revisar' },
    { value: 'resolved', label: 'Resuelto' },
];

const search = ref(props.filters.q ?? '');
const moduleFilter = ref(props.filters.module ?? '');
const severityFilter = ref(props.filters.severity ?? '');
const statusFilter = ref(props.filters.status ?? '');
const fromDate = ref(props.filters.from ?? '');
const toDate = ref(props.filters.to ?? '');

function applyFilters() {
    router.get(
        route('admin.monitoring.index'),
        {
            q: search.value,
            module: moduleFilter.value,
            severity: severityFilter.value,
            status: statusFilter.value,
            from: fromDate.value,
            to: toDate.value,
        },
        { preserveState: true, preserveScroll: true, replace: true }
    );
}

function markResolved(eventId) {
    router.post(route('admin.monitoring.resolve', eventId), {}, { preserveScroll: true });
}

const SEVERITY_CLASS = {
    info: 'bg-arka-text-muted/10 text-arka-text-muted',
    warning: 'bg-arka-warning/10 text-arka-warning',
    error: 'bg-arka-danger/10 text-arka-danger',
    critical: 'bg-arka-danger/20 text-arka-danger font-semibold',
};
</script>

<template>
    <Head title="Admin · Monitoreo" />

    <AdminLayout title="Monitoreo">
        <div class="py-12">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Errores y eventos críticos del sistema (WhatsApp que no salió, alertas SOS que no llegaron,
                    excepciones no atrapadas) — sin tener que entrar a los logs del servidor.
                </p>

                <!-- Filtros -->
                <div class="bg-arka-card shadow rounded-arka p-4 sm:p-6">
                    <div class="flex flex-wrap items-end gap-3">
                        <div>
                            <InputLabel value="Buscar" />
                            <TextInput v-model="search" type="text" placeholder="Tipo o mensaje" class="mt-1 w-48" @keyup.enter="applyFilters" />
                        </div>
                        <div>
                            <InputLabel value="Módulo" />
                            <SearchableSelect
                                v-model="moduleFilter"
                                :options="moduleOptions"
                                empty-label="Todos"
                                class="mt-1 w-40"
                                @update:model-value="applyFilters"
                            />
                        </div>
                        <div>
                            <InputLabel value="Severidad" />
                            <SearchableSelect
                                v-model="severityFilter"
                                :options="SEVERITY_OPTIONS"
                                empty-label="Todas"
                                class="mt-1 w-40"
                                @update:model-value="applyFilters"
                            />
                        </div>
                        <div>
                            <InputLabel value="Estado" />
                            <SearchableSelect
                                v-model="statusFilter"
                                :options="STATUS_OPTIONS"
                                empty-label="Todos"
                                class="mt-1 w-40"
                                @update:model-value="applyFilters"
                            />
                        </div>
                        <div>
                            <InputLabel value="Desde" />
                            <TextInput v-model="fromDate" type="date" class="mt-1" @change="applyFilters" />
                        </div>
                        <div>
                            <InputLabel value="Hasta" />
                            <TextInput v-model="toDate" type="date" class="mt-1" @change="applyFilters" />
                        </div>
                        <SecondaryButton type="button" @click="applyFilters">Buscar</SecondaryButton>
                        <span class="ml-auto text-xs text-arka-text-muted self-center">{{ events.total }} evento(s)</span>
                    </div>
                </div>

                <p v-if="!events.data.length" class="text-sm text-arka-text-muted">No hay eventos con esos filtros.</p>

                <ul v-else class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <li v-for="event in events.data" :key="event.id" class="p-4 sm:p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="px-2 py-0.5 rounded-full text-xs" :class="SEVERITY_CLASS[event.severity]">
                                        {{ event.severity.toUpperCase() }}
                                    </span>
                                    <span class="text-xs text-arka-text-muted">{{ event.module }} · {{ event.event_type }}</span>
                                    <span v-if="event.status === 'resolved'" class="text-xs text-arka-primary-bright">✓ Resuelto</span>
                                </div>
                                <p class="mt-1 text-arka-text">{{ event.message }}</p>
                                <p class="mt-1 text-xs text-arka-text-muted">
                                    {{ new Date(event.created_at).toLocaleString('es-EC', { dateStyle: 'medium', timeStyle: 'short' }) }}
                                    <span v-if="event.user"> · {{ event.user.name }}</span>
                                    <span v-if="event.provider_error_code"> · código {{ event.provider_error_code }}</span>
                                </p>
                                <details v-if="event.context" class="mt-1">
                                    <summary class="text-xs text-arka-primary cursor-pointer">Detalle</summary>
                                    <pre class="mt-1 text-xs text-arka-text-muted bg-arka-base rounded-arka p-2 overflow-x-auto">{{ JSON.stringify(event.context, null, 2) }}</pre>
                                </details>
                            </div>
                            <SecondaryButton v-if="event.status !== 'resolved'" class="shrink-0" @click="markResolved(event.id)">
                                Marcar resuelto
                            </SecondaryButton>
                        </div>
                    </li>
                </ul>

                <div v-if="events.prev_page_url || events.next_page_url" class="flex justify-between">
                    <Link v-if="events.prev_page_url" :href="events.prev_page_url" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                        &larr; Anterior
                    </Link>
                    <span v-else></span>
                    <Link v-if="events.next_page_url" :href="events.next_page_url" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                        Siguiente &rarr;
                    </Link>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
