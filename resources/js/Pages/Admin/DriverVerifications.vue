<script setup>
import { reactive, ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import Modal from '@/Components/Modal.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import DriverCategoryBadge from '@/Components/DriverCategoryBadge.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    registered: { type: Array, required: true },
    incomplete: { type: Array, required: true },
    pending: { type: Array, required: true },
    rejected: { type: Array, required: true },
    approved: { type: Array, required: true },
    publicDriverCategories: { type: Object, required: true },
    serviceCategories: { type: Object, required: true },
    vehicleAmenities: { type: Object, required: true },
});

const categorySelections = reactive(Object.fromEntries(props.pending.map((profile) => [profile.id, profile.public_category ?? ''])));
const serviceSelections = reactive(Object.fromEntries(props.pending.map((profile) => [profile.id, profile.service_category ?? ''])));
const approvalError = ref(null);
const rejectingProfileId = ref(null);
const rejectReason = ref('');
const viewingDocument = ref(null);

function approve(profile) {
    const category = categorySelections[profile.id];
    const serviceCategory = serviceSelections[profile.id];
    if (!category || !serviceCategory) {
        approvalError.value = profile.id;
        return;
    }
    approvalError.value = null;
    router.post(route('admin.driver-verifications.approve', profile.id), {
        public_category: category,
        service_category: serviceCategory,
    }, { preserveScroll: true });
}

function documentsFor(profile) {
    return [
        { key: 'identity', label: 'Cédula', url: profile.identity_document_url, kind: 'image' },
        { key: 'license', label: 'Licencia', url: profile.license_photo_url, kind: 'image' },
        { key: 'vehicle', label: 'Vehículo', url: profile.vehicle_photo_url, kind: 'image' },
        // Antecedentes puede ser PDF o imagen; se muestra en un visor
        // contenido para no crear otro scroll horizontal en la ficha.
        { key: 'police', label: 'Antecedentes', url: profile.police_record_url, kind: 'document' },
    ].filter((document) => document.url);
}

function amenityLabel(key) {
    return props.vehicleAmenities[key]?.label ?? key;
}

function startReject(id) {
    rejectingProfileId.value = id;
    rejectReason.value = '';
}

function confirmReject(id) {
    router.post(route('admin.driver-verifications.reject', id), { reason: rejectReason.value }, {
        preserveScroll: true,
        onSuccess: () => (rejectingProfileId.value = null),
    });
}

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('es-EC', { day: '2-digit', month: 'short', year: 'numeric' });
}
</script>

<template>
    <Head title="Admin · Verificación de conductores" />

    <AdminLayout title="Verificación y activación de conductores">
        <div class="py-8 sm:py-12">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                <section class="rounded-arka border border-arka-primary/20 bg-arka-card p-4 shadow sm:p-6">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-arka-primary">Proceso de activación</p>
                    <h3 class="mt-1 text-lg font-semibold text-arka-text">Cada conductor avanza por estas etapas</h3>
                    <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <div class="rounded-xl bg-arka-base p-3"><p class="text-2xl font-bold text-arka-text">{{ registered.length }}</p><p class="text-xs font-semibold text-arka-text">1. Registrados</p><p class="mt-1 text-[11px] text-arka-text-muted">Todavía no crean su perfil.</p></div>
                        <div class="rounded-xl bg-arka-base p-3"><p class="text-2xl font-bold text-arka-warning">{{ incomplete.length }}</p><p class="text-xs font-semibold text-arka-text">2. Completando datos</p><p class="mt-1 text-[11px] text-arka-text-muted">Perfil o documentos incompletos.</p></div>
                        <div class="rounded-xl border border-arka-warning/25 bg-arka-warning/5 p-3"><p class="text-2xl font-bold text-arka-warning">{{ pending.length }}</p><p class="text-xs font-semibold text-arka-text">3. Por revisar</p><p class="mt-1 text-[11px] text-arka-text-muted">Requieren decisión administrativa.</p></div>
                        <div class="rounded-xl border border-arka-primary/20 bg-arka-primary/5 p-3"><p class="text-2xl font-bold text-arka-primary-bright">{{ approved.length }}</p><p class="text-xs font-semibold text-arka-text">4. Activados recientes</p><p class="mt-1 text-[11px] text-arka-text-muted">Ya pueden conectarse.</p></div>
                    </div>
                </section>

                <section class="rounded-arka border border-arka-warning/25 bg-arka-card shadow">
                    <header class="flex items-center justify-between gap-3 border-b border-arka-text-muted/10 p-4 sm:p-5">
                        <div><p class="text-xs font-semibold uppercase tracking-[0.16em] text-arka-warning">Prioridad</p><h3 class="mt-1 text-lg font-semibold text-arka-text">Listos para revisar</h3><p class="mt-1 text-xs text-arka-text-muted">Revise documentos, asigne la categoría pública y active al conductor.</p></div>
                        <span class="rounded-full bg-arka-warning/15 px-3 py-1 text-sm font-bold text-arka-warning">{{ pending.length }}</span>
                    </header>

                    <p v-if="!pending.length" class="p-6 text-center text-sm text-arka-text-muted">No hay expedientes completos esperando revisión.</p>
                    <ul v-else class="divide-y divide-arka-text-muted/10">
                        <li v-for="profile in pending" :key="profile.id" class="space-y-4 p-4 sm:p-6">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <UserAvatar :user="profile.user" size-class="h-12 w-12 text-sm shrink-0" />
                                    <div class="min-w-0"><p class="truncate font-semibold text-arka-text">{{ profile.user.full_name || profile.user.name }}</p><p class="truncate text-xs text-arka-text-muted">{{ profile.user.email }} · Socio #{{ profile.user.member_code }}</p><p class="mt-1 text-xs text-arka-text-muted">{{ profile.vehicle_make }} {{ profile.vehicle_model }} · {{ profile.vehicle_plate }}</p></div>
                                </div>
                                <Link :href="route('admin.users.show', profile.user.id)" class="text-sm font-medium text-arka-primary hover:text-arka-primary-bright">Ver expediente completo →</Link>
                            </div>

                            <div class="grid gap-3 lg:grid-cols-[minmax(0,1.45fr)_minmax(17rem,0.55fr)]">
                                <section class="min-w-0 rounded-xl border border-arka-text-muted/10 bg-arka-base/50 p-3">
                                    <div class="mb-3 flex items-center justify-between gap-3"><div><p class="text-xs font-semibold text-arka-text">Documentos y fotografías</p><p class="text-[11px] text-arka-text-muted">Vista compacta; toque una imagen para ampliarla.</p></div><span class="rounded-full bg-arka-card px-2 py-1 text-[10px] text-arka-text-muted">{{ documentsFor(profile).length }} archivos</span></div>
                                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                        <button v-for="document in documentsFor(profile)" :key="document.key" type="button" class="group min-w-0 overflow-hidden rounded-xl border border-arka-text-muted/15 bg-arka-card text-left hover:border-arka-primary/40" @click="viewingDocument = document">
                                            <span class="grid h-28 place-items-center overflow-hidden bg-black/20">
                                                <img v-if="document.kind === 'image'" :src="document.url" :alt="document.label" class="h-full w-full object-contain transition group-hover:scale-[1.03]" />
                                                <span v-else class="grid h-12 w-12 place-items-center rounded-xl bg-arka-primary/10 text-arka-primary-bright"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 3.5h7.5L18 7v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1v-15a1 1 0 0 1 1-1Z"/><path stroke-linecap="round" d="M14 3.5V7h4M8.5 13h7M8.5 16h5"/></svg></span>
                                            </span>
                                            <span class="flex items-center justify-between gap-2 px-2.5 py-2 text-[11px] font-semibold text-arka-text"><span class="truncate">{{ document.label }}</span><span class="text-arka-primary-bright">↗</span></span>
                                        </button>
                                    </div>
                                </section>

                                <aside class="rounded-xl border border-arka-text-muted/10 bg-arka-base/50 p-3">
                                    <p class="text-xs font-semibold text-arka-text">Resumen del vehículo</p>
                                    <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[11px]"><dt class="text-arka-text-muted">Vehículo</dt><dd class="text-right text-arka-text">{{ profile.vehicle_make }} {{ profile.vehicle_model }}</dd><dt class="text-arka-text-muted">Placa</dt><dd class="text-right text-arka-text">{{ profile.vehicle_plate }}</dd><dt class="text-arka-text-muted">Año</dt><dd class="text-right text-arka-text">{{ profile.vehicle_year }}</dd><dt class="text-arka-text-muted">Capacidad</dt><dd class="text-right text-arka-text">{{ profile.passenger_capacity ?? '—' }}</dd></dl>
                                    <p class="mt-3 rounded-lg border px-2.5 py-2 text-[11px] font-medium" :class="profile.has_insurance ? 'border-arka-primary/25 bg-arka-primary/5 text-arka-primary-bright' : 'border-arka-danger/25 bg-arka-danger/5 text-arka-danger'">{{ profile.has_insurance ? '✓ Seguro declarado' : '✗ Sin seguro declarado' }}</p>
                                    <div v-if="profile.vehicle_amenities?.length" class="mt-3 flex flex-wrap gap-1"><span v-for="amenity in profile.vehicle_amenities" :key="amenity" class="rounded-full bg-arka-primary/10 px-2 py-1 text-[10px] text-arka-primary-bright">{{ amenityLabel(amenity) }}</span></div>
                                    <p v-else class="mt-3 text-[11px] text-arka-text-muted">No declaró comodidades adicionales.</p>
                                </aside>
                            </div>

                            <div class="rounded-xl border border-arka-primary/15 bg-arka-base p-3 sm:p-4">
                                <div class="mb-3"><p class="text-xs font-bold uppercase tracking-[0.12em] text-arka-primary-bright">Decisión administrativa</p><p class="mt-1 text-xs text-arka-text-muted">Clasifique el servicio y la etiqueta que verá el público antes de activar.</p></div>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <div><label :for="`service-category-${profile.id}`" class="text-xs font-semibold text-arka-text">Categoría del servicio</label><select :id="`service-category-${profile.id}`" v-model="serviceSelections[profile.id]" class="mt-2 block w-full rounded-arka border-arka-text-muted/25 bg-arka-card text-sm text-arka-text" @change="approvalError = null"><option value="">Seleccione el nivel</option><option v-for="(category, key) in serviceCategories" :key="key" :value="key">{{ category.label }}</option></select><p v-if="serviceSelections[profile.id]" class="mt-2 text-[11px] leading-4 text-arka-text-muted">{{ serviceCategories[serviceSelections[profile.id]].description }}</p></div>
                                    <div><label :for="`public-category-${profile.id}`" class="text-xs font-semibold text-arka-text">Etiqueta pública</label><select :id="`public-category-${profile.id}`" v-model="categorySelections[profile.id]" class="mt-2 block w-full rounded-arka border-arka-text-muted/25 bg-arka-card text-sm text-arka-text" @change="approvalError = null"><option value="">Seleccione la etiqueta</option><option v-for="(category, key) in publicDriverCategories" :key="key" :value="key">{{ category.label }}</option></select><p v-if="categorySelections[profile.id]" class="mt-2 text-[11px] leading-4 text-arka-text-muted">{{ publicDriverCategories[categorySelections[profile.id]].description }}</p></div>
                                </div>
                                <p v-if="approvalError === profile.id" class="mt-3 text-xs font-medium text-arka-danger">Seleccione la categoría de servicio y la etiqueta pública antes de aprobar.</p>
                            </div>

                            <div v-if="rejectingProfileId !== profile.id" class="flex flex-wrap gap-2"><PrimaryButton @click="approve(profile)">Aprobar y activar</PrimaryButton><DangerButton @click="startReject(profile.id)">Solicitar corrección</DangerButton></div>
                            <div v-else class="space-y-2 rounded-xl border border-arka-danger/20 bg-arka-danger/5 p-3">
                                <TextInput v-model="rejectReason" type="text" class="w-full" placeholder="Explique exactamente qué debe corregir" />
                                <div class="flex gap-2"><DangerButton :disabled="!rejectReason.trim()" @click="confirmReject(profile.id)">Enviar corrección</DangerButton><SecondaryButton @click="rejectingProfileId = null">Cancelar</SecondaryButton></div>
                            </div>
                        </li>
                    </ul>
                </section>

                <details class="rounded-arka border border-arka-text-muted/10 bg-arka-card shadow">
                    <summary class="flex cursor-pointer list-none items-center justify-between p-4 sm:p-5"><div><h3 class="font-semibold text-arka-text">Registrados sin perfil</h3><p class="mt-1 text-xs text-arka-text-muted">Crearon una cuenta de conductor pero todavía no comenzaron el expediente.</p></div><span class="rounded-full bg-arka-base px-3 py-1 text-sm font-bold text-arka-text">{{ registered.length }}</span></summary>
                    <ul class="divide-y divide-arka-text-muted/10 border-t border-arka-text-muted/10"><li v-for="driver in registered" :key="driver.id" class="flex items-center justify-between gap-3 p-4"><div><p class="font-medium text-arka-text">{{ driver.full_name || driver.name }}</p><p class="text-xs text-arka-text-muted">{{ driver.email }} · Registrado {{ formatDate(driver.created_at) }}</p></div><Link :href="route('admin.users.show', driver.id)" class="text-xs font-medium text-arka-primary">Ver cuenta</Link></li><li v-if="!registered.length" class="p-4 text-sm text-arka-text-muted">No hay registros detenidos en esta etapa.</li></ul>
                </details>

                <details class="rounded-arka border border-arka-text-muted/10 bg-arka-card shadow">
                    <summary class="flex cursor-pointer list-none items-center justify-between p-4 sm:p-5"><div><h3 class="font-semibold text-arka-text">Expedientes incompletos</h3><p class="mt-1 text-xs text-arka-text-muted">Ya empezaron, pero aún no están listos para decisión.</p></div><span class="rounded-full bg-arka-warning/10 px-3 py-1 text-sm font-bold text-arka-warning">{{ incomplete.length }}</span></summary>
                    <ul class="divide-y divide-arka-text-muted/10 border-t border-arka-text-muted/10"><li v-for="profile in incomplete" :key="profile.id" class="flex items-center justify-between gap-3 p-4"><div><p class="font-medium text-arka-text">{{ profile.user.full_name || profile.user.name }}</p><p class="text-xs text-arka-text-muted">Actualizado {{ formatDate(profile.updated_at) }} · Faltan datos o documentos</p></div><Link :href="route('admin.users.show', profile.user.id)" class="text-xs font-medium text-arka-primary">Revisar avance</Link></li><li v-if="!incomplete.length" class="p-4 text-sm text-arka-text-muted">No hay expedientes incompletos.</li></ul>
                </details>

                <details class="rounded-arka border border-arka-danger/15 bg-arka-card shadow">
                    <summary class="flex cursor-pointer list-none items-center justify-between p-4 sm:p-5"><div><h3 class="font-semibold text-arka-text">Requieren corrección</h3><p class="mt-1 text-xs text-arka-text-muted">Conductores rechazados que deben volver a enviar información.</p></div><span class="rounded-full bg-arka-danger/10 px-3 py-1 text-sm font-bold text-arka-danger">{{ rejected.length }}</span></summary>
                    <ul class="divide-y divide-arka-text-muted/10 border-t border-arka-text-muted/10"><li v-for="profile in rejected" :key="profile.id" class="p-4"><div class="flex items-center justify-between gap-3"><p class="font-medium text-arka-text">{{ profile.user.full_name || profile.user.name }}</p><Link :href="route('admin.users.show', profile.user.id)" class="text-xs font-medium text-arka-primary">Ver expediente</Link></div><p class="mt-1 text-xs text-arka-danger">{{ profile.verification_rejection_reason || 'Sin motivo registrado' }}</p></li><li v-if="!rejected.length" class="p-4 text-sm text-arka-text-muted">No hay correcciones pendientes.</li></ul>
                </details>

                <details class="rounded-arka border border-arka-primary/15 bg-arka-card shadow">
                    <summary class="flex cursor-pointer list-none items-center justify-between p-4 sm:p-5"><div><h3 class="font-semibold text-arka-text">Activados recientemente</h3><p class="mt-1 text-xs text-arka-text-muted">Últimos conductores aprobados y habilitados para conectarse.</p></div><span class="rounded-full bg-arka-primary/10 px-3 py-1 text-sm font-bold text-arka-primary">{{ approved.length }}</span></summary>
                    <ul class="divide-y divide-arka-text-muted/10 border-t border-arka-text-muted/10"><li v-for="profile in approved" :key="profile.id" class="flex items-center justify-between gap-3 p-4"><div><p class="font-medium text-arka-text">{{ profile.user.full_name || profile.user.name }}</p><p class="text-xs text-arka-text-muted">Activado {{ formatDate(profile.verified_at) }}</p></div><DriverCategoryBadge :label="profile.public_category ? publicDriverCategories[profile.public_category]?.label : null" /></li><li v-if="!approved.length" class="p-4 text-sm text-arka-text-muted">Todavía no hay conductores activados.</li></ul>
                </details>
            </div>
        </div>

        <Modal :show="viewingDocument !== null" max-width="2xl" @close="viewingDocument = null">
            <div v-if="viewingDocument" class="p-4 sm:p-5">
                <div class="mb-3 flex items-center justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-[0.12em] text-arka-primary-bright">Documento del conductor</p><h3 class="mt-1 text-lg font-semibold text-arka-text">{{ viewingDocument.label }}</h3></div><button type="button" class="grid h-9 w-9 place-items-center rounded-full border border-arka-text-muted/15 text-arka-text-muted hover:text-arka-text" aria-label="Cerrar visor" @click="viewingDocument = null">×</button></div>
                <div class="grid h-[min(68vh,720px)] place-items-center overflow-hidden rounded-xl border border-arka-text-muted/15 bg-black/30">
                    <img v-if="viewingDocument.kind === 'image'" :src="viewingDocument.url" :alt="viewingDocument.label" class="max-h-full max-w-full object-contain" />
                    <iframe v-else :src="viewingDocument.url" :title="viewingDocument.label" class="h-full w-full bg-white" />
                </div>
                <div class="mt-3 flex items-center justify-between gap-3"><a :href="viewingDocument.url" target="_blank" rel="noopener" class="text-xs font-medium text-arka-primary-bright">Abrir original ↗</a><SecondaryButton @click="viewingDocument = null">Cerrar</SecondaryButton></div>
            </div>
        </Modal>
    </AdminLayout>
</template>
