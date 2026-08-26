<script setup>
import { computed, ref } from 'vue';
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
    driverClients: { type: Array, required: true },
    // Pedido explícito del usuario: "cuales son las carreras que a
    // realizado con su detalle" — mismo formato que Admin/Rides.vue.
    rideHistory: { type: Array, required: true },
    averageRating: { type: Number, required: true },
    reviewCount: { type: Number, required: true },
    recentReviews: { type: Array, required: true },
    whatsappMessages: { type: Array, required: true },
    countryCodes: { type: Array, required: true },
});

const VERIFICATION_LABELS = {
    pending: 'Pendiente de revisión',
    approved: 'Verificado',
    rejected: 'Rechazada',
};

// Mismo criterio que Admin/Rides.vue, para que el historial se lea igual en
// las dos pantallas.
const RIDE_STATUS_LABEL = {
    scheduled: 'Programada',
    in_progress: 'En curso',
    completed: 'Completada',
    cancelled: 'Cancelada',
};
const RIDE_STATUS_CLASS = {
    scheduled: 'text-arka-lime',
    in_progress: 'text-arka-primary-bright',
    completed: 'text-arka-text-muted',
    cancelled: 'text-arka-danger',
};
function formatRideDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('es-EC', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

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

// Activación manual de un conductor puntual (pedido explícito del usuario:
// "permiteme colocar a un conductor activo asi no mande toda la
// informacion... para que pueda operar y se pueda poner disponible") — ver
// Admin\UserProfileController::forceActivate(). La nota es obligatoria:
// salta un requisito de seguridad, tiene que quedar registrado por qué.
const activationForm = useForm({ note: '' });
function forceActivateDriver() {
    activationForm.post(route('admin.users.force-activate-driver', props.profileUser.id), {
        preserveScroll: true,
        onSuccess: () => activationForm.reset(),
    });
}

async function revokeForceActivate() {
    if (!(await confirmDialog(`¿Revocar la activación manual de ${props.profileUser.name}? Volverá a exigírsele la información completa para operar.`))) return;
    router.delete(route('admin.users.revoke-force-activate-driver', props.profileUser.id), { preserveScroll: true });
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

// Pedido explícito del usuario: "ver el detalle de los clientes que tiene
// cada conductor... y que pueda eliminarle" — mismo mecanismo que ya usa el
// propio cliente para sacar a un conductor de su flota, disparado acá por
// un admin.
async function removeClient(member) {
    if (!(await confirmDialog(`¿Sacar a ${member.client_name} de la flota de ${props.profileUser.name}?`, { danger: true }))) return;
    router.delete(route('admin.users.remove-client', [props.profileUser.id, member.member_id]), { preserveScroll: true });
}

// Corregir correo/teléfono (pedido explícito del usuario: "permiteme
// actualizar el correo y el telefono") — mismo criterio de país + número
// local que usa el resto de la app (ver Admin\UserProfileController::
// updateContact()). El teléfono ya guardado viene completo (+593...); se
// separa el prefijo conocido para precargar el formulario, mejor esfuerzo
// nada más — si no matchea ninguno, el campo local queda vacío y el admin
// lo escribe de nuevo.
function splitPhone(phone) {
    if (!phone) return { country_code: '+593', phone_local: '' };
    const code = props.countryCodes.find((c) => phone.startsWith(c));
    return code ? { country_code: code, phone_local: phone.slice(code.length) } : { country_code: '+593', phone_local: '' };
}

const editingContact = ref(false);
const contactForm = useForm({
    email: props.profileUser.email,
    ...splitPhone(props.profileUser.phone),
});

function startEditingContact() {
    contactForm.reset();
    contactForm.email = props.profileUser.email;
    Object.assign(contactForm, splitPhone(props.profileUser.phone));
    editingContact.value = true;
}

function saveContact() {
    contactForm.patch(route('admin.users.update-contact', props.profileUser.id), {
        preserveScroll: true,
        onSuccess: () => (editingContact.value = false),
    });
}

// "Dar de baja" un número (pedido explícito del usuario) — lo libera del
// todo, acción aparte de la edición de arriba porque es más drástica (ver
// Admin\UserProfileController::releasePhone()).
async function releasePhone() {
    if (!(await confirmDialog(`¿Dar de baja el número ${props.profileUser.phone}? Queda libre para que otra cuenta lo registre.`, { danger: true }))) return;
    router.delete(route('admin.users.release-phone', props.profileUser.id), { preserveScroll: true });
}

function formatMessageTime(value) {
    return new Date(value).toLocaleString('es-EC', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
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
                    <div class="min-w-0 flex-1">
                        <p class="text-xl font-medium text-arka-text">{{ profileUser.name }}</p>

                        <!-- Correo/teléfono (pedido explícito del usuario:
                             "permiteme actualizar el correo y el telefono") —
                             de solo lectura hasta que toque "Editar", para no
                             ensuciar la ficha con un formulario abierto todo
                             el tiempo. -->
                        <template v-if="!editingContact">
                            <p class="text-sm text-arka-text-muted">{{ profileUser.email }}</p>
                            <p v-if="profileUser.phone" class="text-sm text-arka-text-muted flex items-center gap-2">
                                {{ profileUser.phone }}
                                <button type="button" class="text-xs text-arka-danger hover:underline" @click="releasePhone">
                                    Dar de baja
                                </button>
                            </p>
                            <p v-else class="text-sm text-arka-text-muted italic">Sin número declarado.</p>
                            <button type="button" class="mt-1 text-xs text-arka-primary hover:text-arka-primary-bright" @click="startEditingContact">
                                Editar correo/teléfono
                            </button>
                        </template>
                        <form v-else @submit.prevent="saveContact" class="mt-1 space-y-2 max-w-sm">
                            <div>
                                <InputLabel for="contact_email" value="Correo" />
                                <TextInput id="contact_email" type="email" class="mt-1 w-full" v-model="contactForm.email" />
                                <InputError class="mt-1" :message="contactForm.errors.email" />
                            </div>
                            <div class="flex gap-2">
                                <div class="w-24">
                                    <InputLabel for="contact_country_code" value="País" />
                                    <select id="contact_country_code" v-model="contactForm.country_code" class="mt-1 w-full rounded-arka border-arka-text-muted/30 bg-arka-base text-arka-text text-sm">
                                        <option v-for="code in countryCodes" :key="code" :value="code">{{ code }}</option>
                                    </select>
                                </div>
                                <div class="flex-1">
                                    <InputLabel for="contact_phone_local" value="Teléfono (sin el 0 inicial)" />
                                    <TextInput id="contact_phone_local" class="mt-1 w-full" v-model="contactForm.phone_local" placeholder="991234567" />
                                </div>
                            </div>
                            <InputError :message="contactForm.errors.phone_local" />
                            <div class="flex items-center gap-2">
                                <PrimaryButton type="submit" :disabled="contactForm.processing">Guardar</PrimaryButton>
                                <SecondaryButton type="button" @click="editingContact = false">Cancelar</SecondaryButton>
                            </div>
                        </form>

                        <p class="text-sm text-arka-text-muted mt-1">
                            @{{ profileUser.username }} · Socio #{{ profileUser.member_code }}
                            <span v-if="profileUser.city"> · {{ profileUser.city.name }}</span>
                        </p>

                        <!-- De dónde es (pedido explícito del usuario: "en el
                             perfil quiero saber de donde son") — país fijo
                             (única que opera la plataforma), provincia y
                             ciudad del catálogo, más el barrio aproximado y la
                             coordenada real dados al registrarse (mismo dato
                             que ya se ve agregado en Registros por ubicación,
                             acá puntual para esta cuenta). -->
                        <p v-if="profileUser.city || profileUser.registration_neighborhood" class="text-xs text-arka-text-muted mt-0.5">
                            📍 Ecuador
                            <span v-if="profileUser.city?.province"> · {{ profileUser.city.province }}</span>
                            <span v-if="profileUser.city"> · {{ profileUser.city.name }}</span>
                            <span v-if="profileUser.registration_neighborhood"> · {{ profileUser.registration_neighborhood }}</span>
                            <span v-if="profileUser.registration_lat && profileUser.registration_lng" class="font-mono">
                                ({{ profileUser.registration_lat }}, {{ profileUser.registration_lng }})
                            </span>
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

                    <!-- Activación manual (pedido explícito del usuario: "permiteme
                         colocar a un conductor activo asi no mande toda la
                         informacion... para que pueda operar y se pueda poner
                         disponible") — salta el requisito de documentos/seguro
                         completos solo para este conductor, con nota obligatoria
                         (ver Admin\UserProfileController::forceActivate()). -->
                    <div v-if="profileUser.driver_profile.admin_activated_at" class="p-3 rounded-arka bg-arka-warning/10 border border-arka-warning/30 space-y-2">
                        <p class="text-sm text-arka-warning">
                            ⚠️ Activado a mano el {{ new Date(profileUser.driver_profile.admin_activated_at).toLocaleDateString('es-EC', { dateStyle: 'medium' }) }}
                            por {{ profileUser.driver_profile.activated_by?.name ?? 'un admin' }} — no se le exige información completa.
                        </p>
                        <p class="text-sm text-arka-text-muted">Motivo: {{ profileUser.driver_profile.admin_activation_note }}</p>
                        <SecondaryButton @click="revokeForceActivate">Revocar activación manual</SecondaryButton>
                    </div>
                    <form v-else @submit.prevent="forceActivateDriver" class="p-3 rounded-arka border border-arka-text-muted/15 space-y-2">
                        <InputLabel for="activation_note" value="Activar a este conductor sin exigirle documentos/seguro completos" />
                        <TextInput
                            id="activation_note"
                            class="w-full"
                            v-model="activationForm.note"
                            placeholder="Motivo (ej: ya vetado por su cooperativa, cuenta de demo)"
                        />
                        <InputError :message="activationForm.errors.note" />
                        <SecondaryButton type="submit" :disabled="activationForm.processing">Activar igual</SecondaryButton>
                    </form>

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

                <!-- Clientes de este conductor (pedido explícito del usuario:
                     "ver el detalle de los clientes que tiene cada
                     conductor... y que pueda eliminarle"). -->
                <div v-if="profileUser.driver_profile" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <h3 class="text-lg font-medium text-arka-text">Clientes de confianza ({{ driverClients.length }})</h3>
                    <p v-if="!driverClients.length" class="text-sm text-arka-text-muted">
                        Todavía no forma parte de ninguna flota.
                    </p>
                    <ul v-else class="divide-y divide-arka-text-muted/10">
                        <li
                            v-for="member in driverClients"
                            :key="member.member_id"
                            class="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3"
                        >
                            <div class="flex items-center gap-3 min-w-0">
                                <UserAvatar :user="{ name: member.client_name, avatar_url: member.client_avatar_url }" size-class="h-11 w-11 text-sm shrink-0" />
                                <div class="min-w-0">
                                    <Link
                                        :href="route('admin.users.show', member.client_id)"
                                        class="text-arka-text font-medium hover:text-arka-primary-bright"
                                    >
                                        {{ member.client_name }}
                                    </Link>
                                    <p class="text-sm text-arka-text-muted">
                                        {{ member.client_phone }} · Flota "{{ member.fleet_name }}"
                                    </p>
                                    <p class="text-xs text-arka-text-muted">
                                        {{ member.rides_together_count }} carrera(s) completada(s) juntos · se unió el
                                        {{ new Date(member.joined_at).toLocaleDateString('es-EC', { dateStyle: 'medium' }) }}
                                    </p>
                                </div>
                            </div>
                            <DangerButton class="sm:shrink-0" @click="removeClient(member)">Sacar</DangerButton>
                        </li>
                    </ul>
                </div>

                <!-- Flotas, si es cliente (pedido explícito del usuario:
                     "cuales son los conductores que tiene ese cliente" —
                     antes solo se veía la cantidad, no quiénes eran). -->
                <div v-if="fleetsOwned.length" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-4">
                    <h3 class="text-lg font-medium text-arka-text">Flotas propias</h3>

                    <div v-for="fleet in fleetsOwned" :key="fleet.id">
                        <p class="text-sm font-medium text-arka-text">{{ fleet.name }} — {{ fleet.drivers.length }} conductor(es)</p>
                        <p v-if="!fleet.drivers.length" class="text-sm text-arka-text-muted mt-1">Todavía no tiene conductores.</p>
                        <ul v-else class="mt-2 divide-y divide-arka-text-muted/10">
                            <li
                                v-for="driver in fleet.drivers"
                                :key="driver.user_id"
                                class="py-2.5 flex items-center gap-3"
                            >
                                <UserAvatar :user="{ name: driver.name, avatar_url: driver.avatar_url }" size-class="h-10 w-10 text-sm shrink-0" />
                                <div class="min-w-0 flex-1">
                                    <Link
                                        :href="route('admin.users.show', driver.user_id)"
                                        class="text-arka-text font-medium hover:text-arka-primary-bright"
                                    >
                                        {{ driver.name }}
                                    </Link>
                                    <p class="text-sm text-arka-text-muted">
                                        {{ driver.phone || 'Sin teléfono' }}
                                        <span v-if="driver.vehicle"> · {{ driver.vehicle }}</span>
                                    </p>
                                    <p class="text-xs text-arka-text-muted">
                                        {{ driver.rides_together_count }} carrera(s) completada(s) juntos · se unió el
                                        {{ new Date(driver.joined_at).toLocaleDateString('es-EC', { dateStyle: 'medium' }) }}
                                    </p>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <div v-if="clientPlan" class="pt-2 border-t border-arka-text-muted/10">
                        <p class="text-sm text-arka-text">Plan {{ clientPlan.plan_name }}</p>
                        <p class="text-xs text-arka-text-muted">{{ subscriptionLine(clientPlan) }}</p>
                    </div>
                </div>

                <!-- Historial de carreras (pedido explícito del usuario:
                     "cuales son las carreras que a realizado con su
                     detalle") — tanto si esta cuenta viajó como cliente o
                     manejó como conductor, ver UserProfileController::show(). -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <h3 class="text-lg font-medium text-arka-text">Historial de carreras ({{ rideHistory.length }})</h3>
                    <p v-if="!rideHistory.length" class="text-sm text-arka-text-muted">Todavía no hizo ninguna carrera.</p>
                    <ul v-else class="divide-y divide-arka-text-muted/10">
                        <li v-for="ride in rideHistory" :key="ride.id" class="py-3 space-y-1">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm text-arka-text">
                                    <span class="text-arka-text-muted">{{ ride.counterpart_role }}:</span>
                                    <strong class="font-medium">{{ ride.counterpart_name || 'Cuenta eliminada' }}</strong>
                                </p>
                                <span class="text-xs font-semibold shrink-0" :class="RIDE_STATUS_CLASS[ride.status]">
                                    {{ RIDE_STATUS_LABEL[ride.status] || ride.status }}
                                </span>
                            </div>
                            <p class="text-sm text-arka-text">
                                <span class="text-arka-primary">●</span> {{ ride.origin_address || 'Origen sin dirección' }}
                                <span class="px-1 text-arka-text-muted">→</span>
                                {{ ride.destination_address || 'Destino sin dirección' }}
                            </p>
                            <p class="text-xs text-arka-text-muted">
                                {{ formatRideDate(ride.started_at || ride.created_at) }}
                                <span v-if="ride.price != null"> · ${{ ride.price.toFixed(2) }}</span>
                                <span v-if="ride.distance_km != null"> · {{ ride.distance_km.toFixed(1) }} km</span>
                            </p>
                        </li>
                    </ul>
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

                <!-- Trazabilidad de WhatsApp (pedido explícito del usuario:
                     "ayudame a ver la trazabilidad de los whatsapp en el
                     perfil de cada usuario") — TODO lo que entró y salió por
                     WhatsApp con esta cuenta, no solo el menú del bot: avisos
                     de carrera, recordatorios, etc. también quedan acá (ver
                     ChatbotMessage). De solo lectura — para responder de
                     verdad hace falta un ticket de soporte, ver /admin/soporte. -->
                <div v-if="whatsappMessages.length" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <h3 class="text-lg font-medium text-arka-text">Conversación de WhatsApp</h3>
                    <div class="max-h-96 overflow-y-auto space-y-2 pe-1">
                        <div
                            v-for="message in whatsappMessages"
                            :key="message.id"
                            class="max-w-[80%] px-3 py-2 rounded-arka text-sm"
                            :class="message.direction === 'out'
                                ? 'ms-auto bg-arka-primary text-arka-base'
                                : 'bg-arka-base text-arka-text'"
                        >
                            <p class="whitespace-pre-wrap">{{ message.body || '[ubicación]' }}</p>
                            <p class="mt-1 text-[10px] opacity-70">{{ formatMessageTime(message.created_at) }}</p>
                        </div>
                    </div>
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
