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
});

const categorySelections = reactive(Object.fromEntries(props.pending.map((profile) => [profile.id, profile.public_category ?? ''])));
const approvalError = ref(null);
const rejectingProfileId = ref(null);
const rejectReason = ref('');
const viewingDocument = ref(null);

function approve(profile) {
    const category = categorySelections[profile.id];
    if (!category) {
        approvalError.value = profile.id;
        return;
    }
    approvalError.value = null;
    router.post(route('admin.driver-verifications.approve', profile.id), { public_category: category }, { preserveScroll: true });
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

                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                <button v-if="profile.identity_document_url" type="button" class="rounded-xl border border-arka-text-muted/20 p-3 text-left text-xs font-medium text-arka-primary hover:bg-arka-primary/10" @click="viewingDocument = { url: profile.identity_document_url, label: 'Cédula de identidad' }">Ver cédula</button>
                                <button v-if="profile.license_photo_url" type="button" class="rounded-xl border border-arka-text-muted/20 p-3 text-left text-xs font-medium text-arka-primary hover:bg-arka-primary/10" @click="viewingDocument = { url: profile.license_photo_url, label: 'Licencia de conducir' }">Ver licencia</button>
                                <button v-if="profile.police_record_url" type="button" class="rounded-xl border border-arka-text-muted/20 p-3 text-left text-xs font-medium text-arka-primary hover:bg-arka-primary/10" @click="viewingDocument = { url: profile.police_record_url, label: 'Antecedentes penales' }">Ver antecedentes</button>
                                <p class="rounded-xl border p-3 text-xs font-medium" :class="profile.has_insurance ? 'border-arka-primary/30 text-arka-primary-bright' : 'border-arka-danger/30 text-arka-danger'">{{ profile.has_insurance ? '✓ Seguro declarado' : '✗ Sin seguro declarado' }}</p>
                            </div>

                            <div class="rounded-xl border border-arka-primary/15 bg-arka-base p-3">
                                <label :for="`public-category-${profile.id}`" class="text-xs font-semibold text-arka-text">Etiqueta pública del conductor</label>
                                <select :id="`public-category-${profile.id}`" v-model="categorySelections[profile.id]" class="mt-2 block w-full rounded-arka border-arka-text-muted/25 bg-arka-card text-sm text-arka-text" @change="approvalError = null">
                                    <option value="">Seleccione antes de activar</option>
                                    <option v-for="(category, key) in publicDriverCategories" :key="key" :value="key">{{ category.label }}</option>
                                </select>
                                <p v-if="categorySelections[profile.id]" class="mt-2 text-xs text-arka-text-muted">{{ publicDriverCategories[categorySelections[profile.id]].description }}</p>
                                <p v-if="approvalError === profile.id" class="mt-2 text-xs font-medium text-arka-danger">Seleccione la etiqueta pública antes de aprobar.</p>
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

        <Modal :show="viewingDocument !== null" max-width="lg" @close="viewingDocument = null"><div v-if="viewingDocument" class="space-y-4 p-6"><h3 class="text-lg font-medium text-arka-text">{{ viewingDocument.label }}</h3><iframe :src="viewingDocument.url" :title="viewingDocument.label" class="h-[70vh] w-full rounded-arka border border-arka-text-muted/20 bg-white" /><div class="flex justify-end"><SecondaryButton @click="viewingDocument = null">Cerrar</SecondaryButton></div></div></Modal>
    </AdminLayout>
</template>
