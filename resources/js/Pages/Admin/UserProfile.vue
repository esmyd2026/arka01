<script setup>
import { computed } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import RatingStars from '@/Components/RatingStars.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { confirmDialog } from '@/Utils/confirmDialog';
import { tierColorClass, tierLabel } from '@/Utils/tierBadge';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    profileUser: { type: Object, required: true },
    driverPlan: { type: Object, default: null },
    driverTier: { type: Object, default: null },
    clientPlan: { type: Object, default: null },
    fleetsOwned: { type: Array, required: true },
    averageRating: { type: Number, required: true },
    reviewCount: { type: Number, required: true },
    recentReviews: { type: Array, required: true },
});

const VERIFICATION_LABELS = {
    pending: 'Pendiente de revisión',
    approved: 'Verificado',
    rejected: 'Rechazada',
};

const STATUS_LABEL = { active: 'Activa', grace: 'En gracia (por vencer)', expired: 'Vencida', cancelled: 'Cancelada' };

function subscriptionLine(plan) {
    if (!plan.subscription_status) return 'Plan Gratis — no vence.';
    const status = STATUS_LABEL[plan.subscription_status] ?? plan.subscription_status;
    if (!plan.expires_at) return `${status} — sin fecha de vencimiento.`;
    return `${status} — vence el ${new Date(plan.expires_at).toLocaleDateString('es-EC', { dateStyle: 'medium' })}.`;
}

// Bloqueo de cuenta (pedido explícito del usuario): la propia cuenta se
// puede bloquear desde el aviso de "si no fue usted" (App\Http\Controllers\Auth\SessionTakeoverController::lock())
// — reactivarla es a propósito una acción de admin, no algo que se
// deshaga solo.
async function unlockAccount() {
    if (!(await confirmDialog(`¿Reactivar la cuenta de ${props.profileUser.name}?`))) return;
    router.post(route('admin.users.unlock', props.profileUser.id), {}, { preserveScroll: true });
}

// Ajuste manual de puntos (pedido explícito del usuario): hoy solo suben
// solos, uno por carrera completada — acá se corrige a mano un caso puntual
// (ver Admin\UserProfileController::updatePoints()). Cambia la medalla al
// toque, así que puede habilitar/quitar el directorio público de inmediato.
const pointsForm = useForm({ total_points: props.profileUser.driver_profile?.total_points ?? 0 });
function updatePoints() {
    pointsForm.patch(route('admin.users.update-points', props.profileUser.id), { preserveScroll: true });
}

// Eliminar cuenta (pedido explícito del usuario): borra archivos y, por el
// cascade que ya tienen las FKs, historial de carreras, flotas/membresías,
// reseñas, suscripciones, tickets de soporte, etc. — ver
// Admin\UserProfileController::destroy(). Es irreversible, así que en vez
// de un simple confirmDialog se exige escribir el correo exacto de la
// cuenta antes de habilitar el botón — misma fricción extra que usan otras
// apps para borrados que no se pueden deshacer.
const deleteForm = useForm({ confirm_email: '' });
const canDelete = computed(
    () => deleteForm.confirm_email.trim().toLowerCase() === props.profileUser.email.toLowerCase()
);

function destroyAccount() {
    if (!canDelete.value) return;
    deleteForm.delete(route('admin.users.destroy', props.profileUser.id));
}
</script>

<template>
    <Head :title="`Admin · Perfil de ${profileUser.name}`" />

    <AdminLayout :title="`Perfil de ${profileUser.name}`">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Bloqueo de cuenta (pedido explícito del usuario): visible
                     y accionable de una, no escondido entre otros datos. -->
                <div v-if="profileUser.locked_at" class="p-4 bg-arka-danger/10 border border-arka-danger/30 rounded-arka flex items-center justify-between gap-4">
                    <div>
                        <p class="text-arka-danger font-medium">Cuenta bloqueada</p>
                        <p class="text-sm text-arka-text-muted">
                            Desde el {{ new Date(profileUser.locked_at).toLocaleString('es-EC', { dateStyle: 'medium', timeStyle: 'short' }) }} —
                            no puede iniciar sesión hasta que se reactive.
                        </p>
                    </div>
                    <SecondaryButton @click="unlockAccount">Reactivar cuenta</SecondaryButton>
                </div>

                <!-- Identidad -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka flex items-start gap-4">
                    <UserAvatar :user="profileUser" size-class="h-16 w-16 text-lg shrink-0" />
                    <div class="min-w-0">
                        <p class="text-xl font-medium text-arka-text">{{ profileUser.name }}</p>
                        <p class="text-sm text-arka-text-muted">{{ profileUser.email }}</p>
                        <p v-if="profileUser.phone" class="text-sm text-arka-text-muted">{{ profileUser.phone }}</p>
                        <p class="text-sm text-arka-text-muted">
                            @{{ profileUser.username }} · Socio #{{ profileUser.member_code }}
                            <span v-if="profileUser.city"> · {{ profileUser.city.name }}</span>
                        </p>
                        <div class="mt-2 flex items-center gap-2">
                            <span
                                class="px-2 py-0.5 rounded-full text-xs font-medium"
                                :class="{
                                    'bg-arka-warning/15 text-arka-warning': profileUser.role === 'admin',
                                    'bg-arka-primary/15 text-arka-primary-bright': profileUser.role !== 'admin',
                                }"
                            >
                                {{ { admin: 'Administrador', conductor: 'Conductor', cliente: 'Cliente' }[profileUser.role] }}
                            </span>
                            <RatingStars v-if="reviewCount > 0" readonly :rating="averageRating" :count="reviewCount" />
                            <span v-else class="text-sm text-arka-text-muted">Sin calificaciones todavía.</span>
                        </div>
                    </div>
                </div>

                <!-- Perfil de conductor, si tiene -->
                <div v-if="profileUser.driver_profile" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <h3 class="text-lg font-medium text-arka-text">Perfil de conductor</h3>
                        <span
                            class="text-xs px-2 py-1 rounded-arka"
                            :class="{
                                'bg-arka-primary/10 text-arka-primary-bright': profileUser.driver_profile.verification_status === 'approved',
                                'bg-arka-warning/10 text-arka-warning': profileUser.driver_profile.verification_status === 'pending',
                                'bg-arka-danger/10 text-arka-danger': profileUser.driver_profile.verification_status === 'rejected',
                            }"
                        >
                            {{ VERIFICATION_LABELS[profileUser.driver_profile.verification_status] }}
                        </span>
                    </div>

                    <p v-if="profileUser.driver_profile.verification_rejection_reason" class="text-sm text-arka-danger">
                        Motivo del rechazo: {{ profileUser.driver_profile.verification_rejection_reason }}
                    </p>

                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <dt class="text-arka-text-muted">Licencia</dt>
                        <dd class="text-arka-text">{{ profileUser.driver_profile.license_number }}</dd>
                        <dt class="text-arka-text-muted">Vehículo</dt>
                        <dd class="text-arka-text">
                            {{ profileUser.driver_profile.vehicle_make }} {{ profileUser.driver_profile.vehicle_model }}
                            {{ profileUser.driver_profile.vehicle_color }} ({{ profileUser.driver_profile.vehicle_year }})
                        </dd>
                        <dt class="text-arka-text-muted">Placa</dt>
                        <dd class="text-arka-text">{{ profileUser.driver_profile.vehicle_plate }}</dd>
                        <dt class="text-arka-text-muted">Capacidad</dt>
                        <dd class="text-arka-text">
                            {{ profileUser.driver_profile.passenger_capacity }} pasajero(s)
                            <span v-if="profileUser.driver_profile.has_trunk"> · con cajuela</span>
                        </dd>
                        <dt class="text-arka-text-muted">Tarifa</dt>
                        <dd class="text-arka-text">${{ profileUser.driver_profile.rate_per_km }}/km</dd>
                        <dt class="text-arka-text-muted">Disponible ahora</dt>
                        <dd class="text-arka-text">{{ profileUser.driver_profile.is_available ? 'Sí' : 'No' }}</dd>
                        <dt class="text-arka-text-muted">Directorio público</dt>
                        <dd class="text-arka-text">{{ profileUser.driver_profile.is_public ? 'Visible' : 'No visible' }}</dd>
                        <dt class="text-arka-text-muted">Medalla</dt>
                        <dd class="text-arka-text">
                            <span v-if="driverTier" class="px-1.5 py-0.5 rounded text-xs font-medium" :class="tierColorClass(driverTier.color_key)">
                                {{ tierLabel(driverTier) }}
                            </span>
                            ({{ profileUser.driver_profile.total_points }} puntos)
                        </dd>
                        <dt v-if="profileUser.driver_profile.suspended_at" class="text-arka-danger">Suspendido</dt>
                        <dd v-if="profileUser.driver_profile.suspended_at" class="text-arka-danger">
                            {{ new Date(profileUser.driver_profile.suspended_at).toLocaleDateString('es-EC', { dateStyle: 'medium' }) }}
                        </dd>
                    </dl>

                    <!-- Ajuste manual de puntos (pedido explícito del usuario:
                         "¿dónde actualizo los puntos de un conductor?" — hoy
                         solo suben solos, uno por carrera completada). -->
                    <form @submit.prevent="updatePoints" class="flex items-end gap-2 pt-2 border-t border-arka-text-muted/10">
                        <div>
                            <InputLabel for="total_points" value="Ajustar puntos" />
                            <TextInput
                                id="total_points"
                                type="number"
                                min="0"
                                class="mt-1 w-28"
                                v-model="pointsForm.total_points"
                            />
                            <InputError class="mt-1" :message="pointsForm.errors.total_points" />
                        </div>
                        <PrimaryButton type="submit" :disabled="pointsForm.processing">Guardar</PrimaryButton>
                        <span v-if="pointsForm.recentlySuccessful" class="text-xs text-arka-primary-bright">¡Listo!</span>
                    </form>

                    <div class="grid grid-cols-2 gap-3">
                        <img v-if="profileUser.driver_profile.license_photo_url" :src="profileUser.driver_profile.license_photo_url" alt="Licencia" class="h-32 w-full object-cover rounded-arka" />
                        <img v-if="profileUser.driver_profile.vehicle_photo_url" :src="profileUser.driver_profile.vehicle_photo_url" alt="Vehículo" class="h-32 w-full object-cover rounded-arka" />
                    </div>

                    <div v-if="driverPlan" class="pt-2 border-t border-arka-text-muted/10">
                        <p class="text-sm text-arka-text">Plan {{ driverPlan.plan_name }}</p>
                        <p class="text-xs text-arka-text-muted">{{ subscriptionLine(driverPlan) }}</p>
                    </div>
                </div>

                <!-- Flotas, si es cliente -->
                <div v-if="fleetsOwned.length" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <h3 class="text-lg font-medium text-arka-text">Flotas propias</h3>
                    <ul class="space-y-1.5">
                        <li v-for="fleet in fleetsOwned" :key="fleet.id" class="text-sm text-arka-text">
                            {{ fleet.name }} — {{ fleet.active_members_count }} conductor(es)
                        </li>
                    </ul>

                    <div v-if="clientPlan" class="pt-2 border-t border-arka-text-muted/10">
                        <p class="text-sm text-arka-text">Plan {{ clientPlan.plan_name }}</p>
                        <p class="text-xs text-arka-text-muted">{{ subscriptionLine(clientPlan) }}</p>
                    </div>
                </div>

                <!-- Reseñas recientes -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <h3 class="text-lg font-medium text-arka-text">Calificaciones recibidas</h3>
                    <p v-if="!recentReviews.length" class="text-sm text-arka-text-muted">Todavía no recibió ninguna.</p>
                    <ul v-else class="divide-y divide-arka-text-muted/10">
                        <li v-for="review in recentReviews" :key="review.id" class="py-2.5 text-sm space-y-1">
                            <div class="flex items-center gap-2">
                                <span class="text-arka-text font-medium">{{ review.reviewer.name }}</span>
                                <RatingStars readonly :rating="review.rating" />
                            </div>
                            <p v-if="review.comment" class="text-arka-text-muted italic">"{{ review.comment }}"</p>
                        </li>
                    </ul>
                </div>

                <!-- Zona de peligro: eliminar cuenta (pedido explícito del
                     usuario) — nunca se ofrece para cuentas admin, mismo
                     criterio que "Reiniciar demo" (Admin/System.vue). -->
                <div v-if="profileUser.role !== 'admin'" class="p-4 sm:p-6 bg-arka-danger/10 border border-arka-danger/30 rounded-arka space-y-3">
                    <div>
                        <p class="text-arka-danger font-medium">Zona de peligro</p>
                        <p class="text-sm text-arka-text-muted mt-1">
                            Elimina esta cuenta para siempre: su perfil, fotos y comprobantes subidos, historial de
                            carreras, flotas y membresías, calificaciones hechas y recibidas, suscripciones y
                            tickets de soporte. No se puede deshacer.
                        </p>
                    </div>

                    <div>
                        <label class="text-xs text-arka-text-muted">
                            Escriba <span class="font-mono text-arka-text">{{ profileUser.email }}</span> para confirmar
                        </label>
                        <TextInput
                            type="text"
                            class="mt-1 block w-full sm:w-80"
                            v-model="deleteForm.confirm_email"
                            autocomplete="off"
                        />
                        <InputError class="mt-1" :message="deleteForm.errors.confirm_email" />
                    </div>

                    <DangerButton :disabled="!canDelete || deleteForm.processing" @click="destroyAccount">
                        Eliminar cuenta definitivamente
                    </DangerButton>
                </div>

                <Link :href="route('admin.subscriptions.index')" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                    &larr; Volver a Suscripciones
                </Link>
            </div>
        </div>
    </AdminLayout>
</template>
