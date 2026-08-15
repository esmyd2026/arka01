<script setup>
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import Modal from '@/Components/Modal.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps({
    pending: { type: Array, required: true },
});

function approve(id) {
    router.post(route('admin.driver-verifications.approve', id), {}, { preserveScroll: true });
}

// Pedido explícito del usuario: al rechazar, el admin tiene que dejar
// asentado el motivo — antes se rechazaba sin ningún detalle, y el
// conductor no tenía forma de saber qué corregir.
const rejectingProfileId = ref(null);
const rejectReason = ref('');

function startReject(id) {
    rejectingProfileId.value = id;
    rejectReason.value = '';
}

function confirmReject(id) {
    router.post(
        route('admin.driver-verifications.reject', id),
        { reason: rejectReason.value },
        { preserveScroll: true, onSuccess: () => (rejectingProfileId.value = null) }
    );
}

const viewingDocument = ref(null);
</script>

<template>
    <Head title="Admin · Verificaciones" />

    <AdminLayout title="Verificación de conductores">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Cédula, licencia, antecedentes penales y fotografía de perfil pendientes de revisión. Al aprobar,
                    el conductor muestra la insignia correspondiente a su tipo en el perfil público.
                </p>

                <p v-if="!pending.length" class="text-sm text-arka-text-muted">No hay verificaciones pendientes.</p>

                <ul v-else class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <li v-for="profile in pending" :key="profile.id" class="p-4 sm:p-6 space-y-3">
                        <div class="flex items-center justify-between gap-2 flex-wrap">
                            <div class="flex items-center gap-2">
                                <UserAvatar :user="profile.user" size-class="h-9 w-9 text-sm shrink-0" />
                                <p class="text-arka-text font-medium">{{ profile.user.name }}</p>
                            </div>
                            <!-- Pedido explícito del usuario: acceso al perfil completo
                                 del conductor para decidir con toda la información. -->
                            <Link :href="route('admin.users.show', profile.user.id)" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                                Ver perfil completo &rarr;
                            </Link>
                        </div>
                        <p class="text-sm text-arka-text-muted">
                            Licencia {{ profile.license_number }}
                            <span v-if="profile.vehicle_plate"> · Placa {{ profile.vehicle_plate }}</span>
                        </p>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <button
                                v-if="profile.identity_document_url"
                                type="button"
                                class="rounded-arka border border-arka-text-muted/20 p-3 text-left text-sm font-medium text-arka-primary hover:bg-arka-primary/10"
                                @click="viewingDocument = { url: profile.identity_document_url, label: 'Cédula de identidad' }"
                            >
                                Ver cédula
                            </button>
                            <button
                                v-if="profile.license_photo_url"
                                type="button"
                                class="rounded-arka border border-arka-text-muted/20 p-3 text-left text-sm font-medium text-arka-primary hover:bg-arka-primary/10"
                                @click="viewingDocument = { url: profile.license_photo_url, label: 'Licencia de conducir' }"
                            >
                                Ver licencia
                            </button>
                            <button
                                v-if="profile.police_record_url"
                                type="button"
                                class="rounded-arka border border-arka-text-muted/20 p-3 text-left text-sm font-medium text-arka-primary hover:bg-arka-primary/10"
                                @click="viewingDocument = { url: profile.police_record_url, label: 'Antecedentes penales' }"
                            >
                                Ver antecedentes
                            </button>
                        </div>

                        <div v-if="rejectingProfileId !== profile.id" class="flex gap-2">
                            <PrimaryButton @click="approve(profile.id)">Aprobar</PrimaryButton>
                            <DangerButton @click="startReject(profile.id)">Rechazar</DangerButton>
                        </div>
                        <div v-else class="space-y-2">
                            <TextInput
                                v-model="rejectReason"
                                type="text"
                                class="w-full"
                                placeholder="Motivo del rechazo (ej: foto de licencia borrosa, placa no coincide)"
                            />
                            <div class="flex gap-2">
                                <DangerButton :disabled="!rejectReason.trim()" @click="confirmReject(profile.id)">Confirmar rechazo</DangerButton>
                                <SecondaryButton @click="rejectingProfileId = null">Cancelar</SecondaryButton>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>
        </div>

        <Modal :show="viewingDocument !== null" max-width="lg" @close="viewingDocument = null">
            <div v-if="viewingDocument" class="p-6 space-y-4">
                <h3 class="text-lg font-medium text-arka-text">{{ viewingDocument.label }}</h3>
                <iframe :src="viewingDocument.url" :title="viewingDocument.label" class="h-[70vh] w-full rounded-arka border border-arka-text-muted/20 bg-white" />
                <div class="flex justify-end">
                    <SecondaryButton @click="viewingDocument = null">Cerrar</SecondaryButton>
                </div>
            </div>
        </Modal>
    </AdminLayout>
</template>
