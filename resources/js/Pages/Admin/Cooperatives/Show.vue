<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ cooperative: { type: Object, required: true }, auditLogs: { type: Array, required: true } });
const reason = ref('');
const documentReasons = ref({});

function action(name, id, needsReason = false) {
    router.post(route(`admin.cooperatives.${name}`, id), needsReason ? { reason: reason.value } : {}, { preserveScroll: true });
}

function reviewDocument(document, status) {
    router.post(route('admin.cooperative-documents.review', document.id), { status, reason: documentReasons.value[document.id] || null }, { preserveScroll: true });
}

function toggleWhatsApp() {
    router.patch(route('admin.cooperatives.whatsapp', props.cooperative.id), { enabled: !props.cooperative.whatsapp_ride_actions_enabled }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Admin · ${cooperative.name || 'Cooperativa'}`" />
    <AdminLayout title="Revisión de cooperativa">
        <div class="py-10"><div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6">
            <section class="overflow-hidden rounded-arka bg-arka-card shadow-xl">
                <div class="flex flex-col gap-4 bg-gradient-to-r from-arka-primary/20 to-transparent p-6 sm:flex-row sm:items-center">
                    <img v-if="cooperative.logo_url" :src="cooperative.logo_url" class="h-20 w-20 rounded-xl bg-white object-contain p-1" alt="Logo" />
                    <div class="flex-1"><p class="text-xs uppercase text-arka-primary">{{ cooperative.status }}</p><h1 class="mt-1 text-2xl font-bold text-arka-text">{{ cooperative.name || 'Registro incompleto' }}</h1><p class="mt-1 text-sm text-arka-text-muted">{{ cooperative.legal_name }} · RUC {{ cooperative.ruc }}</p></div>
                    <Link :href="route('cooperatives.show', cooperative.public_id)" class="text-sm font-medium text-arka-primary">Vista pública →</Link>
                </div>
                <div class="grid gap-4 p-6 text-sm sm:grid-cols-2"><p><span class="text-arka-text-muted">Representante:</span> <span class="text-arka-text">{{ cooperative.legal_representative }}</span></p><p><span class="text-arka-text-muted">Contacto:</span> <span class="text-arka-text">{{ cooperative.phone }} · {{ cooperative.email }}</span></p><p><span class="text-arka-text-muted">Ubicación:</span> <span class="text-arka-text">{{ cooperative.main_address }}, {{ cooperative.city?.name }}, {{ cooperative.province }}</span></p><p><span class="text-arka-text-muted">Capacidad:</span> <span class="text-arka-text">{{ cooperative.declared_driver_count }} conductores · {{ cooperative.declared_unit_count }} unidades</span></p><p><span class="text-arka-text-muted">Seguro:</span> <span :class="cooperative.has_insurance ? 'text-arka-primary-bright' : 'text-arka-danger'">{{ cooperative.has_insurance ? '✓ Declarado' : '✗ Sin declarar' }}</span></p><p class="sm:col-span-2"><span class="text-arka-text-muted">Cobertura:</span> <span class="text-arka-text">{{ cooperative.geographic_coverage }}</span></p><p class="sm:col-span-2"><span class="text-arka-text-muted">Horario:</span> <span class="text-arka-text">{{ cooperative.operating_hours }}</span></p></div>
            </section>

            <section class="rounded-arka bg-arka-card p-6 shadow-xl">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div><h2 class="text-lg font-semibold text-arka-text">Carreras por WhatsApp</h2><p class="mt-1 text-sm text-arka-text-muted">Controla aceptar, rechazar y comunicarse desde WhatsApp para todos los conductores asociados.</p></div>
                    <button type="button" class="rounded-arka border px-4 py-2 text-sm font-semibold" :class="cooperative.whatsapp_ride_actions_enabled ? 'border-arka-primary text-arka-primary' : 'border-arka-text-muted/30 text-arka-text-muted'" @click="toggleWhatsApp">
                        {{ cooperative.whatsapp_ride_actions_enabled ? 'Operación habilitada' : 'Solo notificaciones' }}
                    </button>
                </div>
            </section>

            <section class="rounded-arka bg-arka-card p-6 shadow-xl">
                <h2 class="text-lg font-semibold text-arka-text">Documentación privada</h2>
                <div class="mt-4 space-y-3">
                    <div v-for="document in cooperative.documents" :key="document.id" class="rounded-arka border border-arka-text-muted/10 p-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                            <div class="flex-1"><p class="font-medium text-arka-text">{{ document.label || 'Otro documento' }}</p><p class="text-xs text-arka-text-muted">{{ document.original_name }} · {{ document.status }}</p><p v-if="document.rejection_reason" class="mt-1 text-xs text-arka-danger">{{ document.rejection_reason }}</p></div>
                            <Link :href="route('cooperative.documents.show', document.id)" class="text-sm text-arka-primary">Descargar</Link>
                        </div>
                        <div class="mt-3 flex flex-col gap-2 sm:flex-row"><TextInput v-model="documentReasons[document.id]" class="flex-1" placeholder="Motivo si se rechaza" /><PrimaryButton @click="reviewDocument(document, 'approved')">Aprobar documento</PrimaryButton><DangerButton :disabled="!documentReasons[document.id]" @click="reviewDocument(document, 'rejected')">Rechazar</DangerButton></div>
                    </div>
                </div>
            </section>

            <section class="rounded-arka bg-arka-card p-6 shadow-xl">
                <h2 class="text-lg font-semibold text-arka-text">Decisión administrativa</h2>
                <p v-if="cooperative.rejection_reason" class="mt-2 rounded-arka bg-arka-danger/10 p-3 text-sm text-arka-danger">{{ cooperative.rejection_reason }}</p>
                <TextInput v-model="reason" class="mt-4 w-full" placeholder="Motivo obligatorio para rechazar o suspender" />
                <div class="mt-4 flex flex-wrap gap-2"><SecondaryButton v-if="cooperative.status === 'pending'" @click="action('review', cooperative.id)">Marcar en revisión</SecondaryButton><PrimaryButton v-if="['pending','in_review','rejected'].includes(cooperative.status)" @click="action('approve', cooperative.id)">Aprobar cooperativa</PrimaryButton><DangerButton v-if="['pending','in_review'].includes(cooperative.status)" :disabled="!reason" @click="action('reject', cooperative.id, true)">Rechazar</DangerButton><DangerButton v-if="cooperative.status === 'approved'" :disabled="!reason" @click="action('suspend', cooperative.id, true)">Suspender</DangerButton><PrimaryButton v-if="cooperative.status === 'suspended'" @click="action('reactivate', cooperative.id)">Reactivar</PrimaryButton></div>
            </section>

            <section class="rounded-arka bg-arka-card p-6 shadow-xl"><h2 class="text-lg font-semibold text-arka-text">Conductores asociados</h2><p v-if="!cooperative.driver_memberships.length" class="mt-3 text-sm text-arka-text-muted">Sin vínculos todavía.</p><div v-else class="mt-3 divide-y divide-arka-text-muted/10"><div v-for="membership in cooperative.driver_memberships" :key="membership.id" class="flex justify-between py-3 text-sm"><span class="text-arka-text">{{ membership.driver.name }}</span><span class="text-arka-text-muted">{{ membership.status }}</span></div></div></section>

            <section class="rounded-arka bg-arka-card p-6 shadow-xl">
                <h2 class="text-lg font-semibold text-arka-text">Historial y auditoría</h2>
                <p v-if="!auditLogs.length" class="mt-3 text-sm text-arka-text-muted">Todavía no hay decisiones administrativas registradas para esta cooperativa.</p>
                <div v-else class="mt-3 divide-y divide-arka-text-muted/10">
                    <div v-for="log in auditLogs" :key="log.id" class="py-3 text-sm">
                        <div class="flex flex-wrap justify-between gap-2"><span class="font-medium text-arka-text">{{ log.action }}</span><span class="text-xs text-arka-text-muted">{{ new Date(log.created_at).toLocaleString('es-EC') }}</span></div>
                        <p class="mt-1 text-xs text-arka-text-muted">Administrador: {{ log.admin?.name || 'Cuenta eliminada' }}</p>
                    </div>
                </div>
            </section>
        </div></div>
    </AdminLayout>
</template>
