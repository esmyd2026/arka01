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

// Detalle de imágenes (pedido explícito del usuario: "debe ser posible
// visualizar el detalle de todas las imágenes cargadas") — la miniatura de la
// lista es chica para leer una licencia o una placa con confianza.
const viewingImage = ref(null);
</script>

<template>
    <Head title="Admin · Verificaciones" />

    <AdminLayout title="Verificación de conductores">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Licencia y foto del vehículo pendientes de revisión (sección 8 y 9.5-C). Al aprobar, el conductor
                    muestra la insignia "Conductor verificado" en su perfil público.
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

                        <div class="grid grid-cols-2 gap-3">
                            <button
                                v-if="profile.license_photo_url"
                                type="button"
                                class="block"
                                title="Ver licencia completa"
                                @click="viewingImage = { url: profile.license_photo_url, label: 'Licencia' }"
                            >
                                <img
                                    :src="profile.license_photo_url"
                                    alt="Licencia"
                                    class="h-32 w-full object-cover rounded-arka hover:opacity-80 transition"
                                />
                            </button>
                            <button
                                v-if="profile.vehicle_photo_url"
                                type="button"
                                class="block"
                                title="Ver foto del vehículo completa"
                                @click="viewingImage = { url: profile.vehicle_photo_url, label: 'Vehículo' }"
                            >
                                <img
                                    :src="profile.vehicle_photo_url"
                                    alt="Vehículo"
                                    class="h-32 w-full object-cover rounded-arka hover:opacity-80 transition"
                                />
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

        <!-- Imagen completa, sin recortar (pedido explícito del usuario). -->
        <Modal :show="viewingImage !== null" max-width="lg" @close="viewingImage = null">
            <div v-if="viewingImage" class="p-6 space-y-4">
                <h3 class="text-lg font-medium text-arka-text">{{ viewingImage.label }}</h3>
                <img :src="viewingImage.url" :alt="viewingImage.label" class="w-full max-h-[70vh] object-contain rounded-arka border border-arka-text-muted/20 bg-arka-base" />
                <div class="flex justify-end">
                    <SecondaryButton @click="viewingImage = null">Cerrar</SecondaryButton>
                </div>
            </div>
        </Modal>
    </AdminLayout>
</template>
