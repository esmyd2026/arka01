<script setup>
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { confirmDialog } from '@/Utils/confirmDialog';

const props = defineProps({
    users: { type: Object, required: true },
    plans: { type: Array, required: true },
    recentChanges: { type: Array, required: true },
    pendingRequests: { type: Array, required: true },
    search: { type: String, default: '' },
});

// Comprobantes de pago esperando revisión (consideración agregada al alcance).
const rejectingRequestId = ref(null);
const rejectNote = ref('');

function approveRequest(id) {
    router.post(route('admin.subscription-requests.approve', id), {}, { preserveScroll: true });
}

function startReject(id) {
    rejectingRequestId.value = id;
    rejectNote.value = '';
}

// Motivos frecuentes de rechazo (pedido del admin: cuando no se distingue el
// comprobante o el monto no coincide, quiere dejarlo asentado sin tener que
// escribirlo entero cada vez). El texto sigue siendo editable después.
const REJECT_REASON_PRESETS = ['No se distingue el comprobante', 'El monto no coincide con el plan'];

function applyRejectPreset(reason) {
    rejectNote.value = reason;
}

function confirmReject(id) {
    router.post(
        route('admin.subscription-requests.reject', id),
        { admin_note: rejectNote.value },
        { preserveScroll: true, onSuccess: () => (rejectingRequestId.value = null) }
    );
}

// Detalle del comprobante (consideración agregada al alcance): la miniatura
// de la lista viene recortada a un cuadrado chico, insuficiente para leer un
// comprobante real — este modal muestra la imagen completa más los datos del
// pago (a quién, qué plan, cuánto debía transferir, cuándo lo subió).
const viewingRequest = ref(null);

const searchTerm = ref(props.search);

function runSearch() {
    router.get(route('admin.subscriptions.index'), { q: searchTerm.value }, { preserveState: true });
}

// El formulario de activación se abre "anclado" a un usuario puntual (botón
// "Activar plan" de su fila), así el admin no tiene que buscarlo dos veces.
const activatingUserId = ref(null);

const form = useForm({
    user_id: null,
    subscription_plan_id: '',
    expires_at: '',
    note: '',
});

function openActivation(user) {
    activatingUserId.value = user.id;
    form.reset();
    form.user_id = user.id;
}

function submitActivation() {
    form.post(route('admin.subscriptions.store'), {
        preserveScroll: true,
        onSuccess: () => {
            activatingUserId.value = null;
            form.reset();
        },
    });
}

async function expireSubscription(subscriptionId) {
    if (!(await confirmDialog('¿Dar de baja esta suscripción? El usuario vuelve al plan Gratis correspondiente.', { danger: true }))) return;
    router.post(route('admin.subscriptions.expire', subscriptionId), {}, { preserveScroll: true });
}

// Pedido explícito del usuario: mostrar también el vencimiento desde el
// panel admin, no solo el nombre del plan — antes no se veía en ningún lado.
function expiryLabel(subscription) {
    if (!subscription?.expires_at) return null;
    return `vence ${new Date(subscription.expires_at).toLocaleDateString('es-EC', { dateStyle: 'medium' })}`;
}

function driverPlanOf(user) {
    return user.subscriptions?.find((s) => s.plan.owner_type === 'driver');
}

function clientPlanOf(user) {
    return user.subscriptions?.find((s) => s.plan.owner_type === 'client');
}
</script>

<template>
    <Head title="Admin · Suscripciones" />

    <AdminLayout title="Suscripciones">
        <div class="py-12">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Comprobantes de pago esperando revisión (consideración agregada al
                     alcance): lo primero para atender, antes que la lista general. -->
                <div v-if="pendingRequests.length" class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <div class="p-4 sm:p-6 border-b border-arka-text-muted/10">
                        <h3 class="text-lg font-medium text-arka-text">Comprobantes pendientes de revisión</h3>
                    </div>
                    <div v-for="req in pendingRequests" :key="req.id" class="p-4 sm:p-6 flex flex-col sm:flex-row gap-4">
                        <button
                            v-if="req.payment_proof_url"
                            type="button"
                            class="shrink-0"
                            title="Ver comprobante completo"
                            @click="viewingRequest = req"
                        >
                            <img
                                :src="req.payment_proof_url"
                                alt="Comprobante de pago"
                                class="w-full sm:w-40 h-40 object-cover rounded-arka border border-arka-text-muted/20 hover:opacity-80 transition"
                            />
                        </button>
                        <div class="flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <UserAvatar :user="req.user" size-class="h-8 w-8 text-xs shrink-0" />
                                <p class="text-arka-text font-medium">{{ req.user.name }}</p>
                                <!-- Pedido explícito del usuario: de un vistazo, si quien se
                                     está suscribiendo lo hace como cliente o como conductor —
                                     antes solo se veía el nombre del plan, ambigüo si no se
                                     conocía el catálogo de memoria. -->
                                <span
                                    class="px-2 py-0.5 rounded-full text-xs font-medium"
                                    :class="req.plan.owner_type === 'driver' ? 'bg-arka-primary/15 text-arka-primary-bright' : 'bg-arka-lime/15 text-arka-lime'"
                                >
                                    {{ req.plan.owner_type === 'driver' ? 'Conductor' : 'Cliente' }}
                                </span>
                            </div>
                            <p class="text-sm text-arka-text-muted">{{ req.user.email }}</p>
                            <p class="text-sm text-arka-text-muted mt-1">
                                Plan {{ req.plan.name }} · ${{ req.plan.monthly_price }}/mes
                            </p>
                            <!-- Promoción (pedido explícito del usuario): si el pedido viene
                                 de una promo, el monto a revisar en el comprobante es el
                                 promocional, no el de lista — sin esto no había forma de
                                 saberlo desde acá. -->
                            <p v-if="req.plan_promotion" class="text-sm text-arka-lime mt-0.5">
                                🎁 Promoción "{{ req.plan_promotion.label }}" — correspondía ${{ req.plan_promotion.promo_price }}/mes
                            </p>
                            <button
                                v-if="req.payment_proof_url"
                                type="button"
                                class="mt-1 text-sm text-arka-primary hover:text-arka-primary-bright"
                                @click="viewingRequest = req"
                            >
                                Ver detalle del pago &rarr;
                            </button>

                            <div v-if="rejectingRequestId !== req.id" class="mt-3 flex gap-2">
                                <PrimaryButton @click="approveRequest(req.id)">Aprobar y activar</PrimaryButton>
                                <DangerButton @click="startReject(req.id)">Rechazar</DangerButton>
                            </div>
                            <div v-else class="mt-3 space-y-2">
                                <!-- Motivos frecuentes, un clic y se puede editar después. -->
                                <div class="flex flex-wrap gap-1.5">
                                    <button
                                        v-for="reason in REJECT_REASON_PRESETS"
                                        :key="reason"
                                        type="button"
                                        class="px-2 py-1 rounded-arka text-xs bg-arka-base text-arka-text-muted hover:text-arka-text border border-arka-text-muted/20"
                                        @click="applyRejectPreset(reason)"
                                    >
                                        {{ reason }}
                                    </button>
                                </div>
                                <TextInput
                                    v-model="rejectNote"
                                    type="text"
                                    class="w-full"
                                    placeholder="Motivo del rechazo (ej: comprobante ilegible, el monto no coincide)"
                                />
                                <div class="flex gap-2">
                                    <DangerButton @click="confirmReject(req.id)">Confirmar rechazo</DangerButton>
                                    <SecondaryButton @click="rejectingRequestId = null">Cancelar</SecondaryButton>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detalle del comprobante: imagen completa (sin recortar) + datos
                     del pago, para poder cotejar el monto y leer bien la imagen antes
                     de aprobar o rechazar. -->
                <Modal :show="viewingRequest !== null" max-width="lg" @close="viewingRequest = null">
                    <div v-if="viewingRequest" class="p-6 space-y-4">
                        <h3 class="text-lg font-medium text-arka-text">Detalle del pago</h3>

                        <div class="flex items-center gap-2">
                            <UserAvatar :user="viewingRequest.user" size-class="h-10 w-10 text-sm shrink-0" />
                            <div>
                                <p class="text-arka-text font-medium">{{ viewingRequest.user.name }}</p>
                                <p class="text-xs text-arka-text-muted">{{ viewingRequest.user.email }}</p>
                            </div>
                        </div>

                        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <dt class="text-arka-text-muted">Se suscribe como</dt>
                            <dd class="text-arka-text">{{ viewingRequest.plan.owner_type === 'driver' ? 'Conductor' : 'Cliente' }}</dd>
                            <dt class="text-arka-text-muted">Plan solicitado</dt>
                            <dd class="text-arka-text">{{ viewingRequest.plan.name }}</dd>
                            <dt class="text-arka-text-muted">Monto a transferir</dt>
                            <dd class="text-arka-text font-semibold">${{ viewingRequest.plan.monthly_price }}/mes</dd>
                            <dt class="text-arka-text-muted">Comprobante subido</dt>
                            <dd class="text-arka-text">{{ new Date(viewingRequest.updated_at).toLocaleString('es-EC') }}</dd>
                        </dl>

                        <img
                            v-if="viewingRequest.payment_proof_url"
                            :src="viewingRequest.payment_proof_url"
                            alt="Comprobante de pago completo"
                            class="w-full max-h-[70vh] object-contain rounded-arka border border-arka-text-muted/20 bg-arka-base"
                        />

                        <div class="flex justify-end">
                            <SecondaryButton @click="viewingRequest = null">Cerrar</SecondaryButton>
                        </div>
                    </div>
                </Modal>

                <div class="flex items-center justify-end">
                    <TextInput
                        v-model="searchTerm"
                        type="text"
                        placeholder="Buscar por nombre o correo"
                        class="w-64"
                        @keyup.enter="runSearch"
                    />
                </div>

                <!-- Usuarios y su plan vigente por lado (conductor / cliente) -->
                <div class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <div v-for="user in users.data" :key="user.id" class="p-4 sm:p-6">
                        <!-- Bug reportado por el usuario (con captura): en pantallas angostas
                             el avatar y el nombre quedaban superpuestos — la columna de
                             nombre/correo no tenía `min-w-0` (los flex items no se achican
                             por debajo de su ancho natural por defecto), así que un nombre o
                             correo largo empujaba todo en vez de truncar o hacer wrap. Se
                             suma el mismo patrón "apilar en mobile" que ya usa el resto del
                             panel admin para filas con acciones al costado. -->
                        <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                            <div class="flex items-start gap-3 min-w-0">
                                <UserAvatar :user="user" size-class="h-10 w-10 text-sm shrink-0" />
                                <div class="min-w-0 flex-1">
                                    <p class="text-arka-text font-medium truncate">{{ user.name }}</p>
                                    <p class="text-sm text-arka-text-muted truncate">{{ user.email }}</p>
                                    <!-- Pedido explícito del usuario: cada cuenta es cliente O
                                         conductor, nunca las dos (sección 3.1) — mostrar los dos
                                         renglones siempre ("Conductor: Gratis / Cliente: Gratis")
                                         confundía, insinuando que alguien pudiera tener ambos.
                                         Un solo renglón, el del rol real de esta cuenta. -->
                                    <div class="mt-2 flex flex-wrap gap-2 text-xs">
                                        <span
                                            v-if="user.role === 'admin'"
                                            class="px-2 py-1 rounded-arka bg-arka-warning/10 text-arka-warning"
                                        >
                                            Administrador (sin plan)
                                        </span>
                                        <span
                                            v-else-if="user.role === 'conductor'"
                                            class="px-2 py-1 rounded-arka bg-arka-primary/10 text-arka-primary-bright"
                                        >
                                            Conductor: {{ driverPlanOf(user)?.plan.name ?? 'Gratis' }}
                                            <template v-if="expiryLabel(driverPlanOf(user))"> · {{ expiryLabel(driverPlanOf(user)) }}</template>
                                        </span>
                                        <span v-else class="px-2 py-1 rounded-arka bg-arka-primary/10 text-arka-primary-bright">
                                            Cliente: {{ clientPlanOf(user)?.plan.name ?? 'Gratis' }}
                                            <template v-if="expiryLabel(clientPlanOf(user))"> · {{ expiryLabel(clientPlanOf(user)) }}</template>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col items-end gap-2 shrink-0">
                                <Link :href="route('admin.users.show', user.id)" class="text-xs text-arka-primary hover:text-arka-primary-bright">
                                    Ver perfil completo &rarr;
                                </Link>
                                <PrimaryButton v-if="user.role !== 'admin'" @click="openActivation(user)">Activar plan</PrimaryButton>
                                <DangerButton
                                    v-if="driverPlanOf(user)"
                                    @click="expireSubscription(driverPlanOf(user).id)"
                                >
                                    Dar de baja conductor
                                </DangerButton>
                                <DangerButton
                                    v-if="clientPlanOf(user)"
                                    @click="expireSubscription(clientPlanOf(user).id)"
                                >
                                    Dar de baja cliente
                                </DangerButton>
                            </div>
                        </div>

                        <!-- Formulario de activación, inline debajo de la fila del usuario -->
                        <form
                            v-if="activatingUserId === user.id"
                            @submit.prevent="submitActivation"
                            class="mt-4 p-4 rounded-arka border border-arka-text-muted/20 space-y-3"
                        >
                            <div>
                                <!-- Pedido explícito del usuario: cada cuenta es cliente O
                                     conductor, nunca las dos — el select ya no ofrece planes
                                     del lado que no le corresponde a esta cuenta puntual. -->
                                <InputLabel :value="`Plan a activar (${user.role === 'conductor' ? 'conductor' : 'cliente'})`" />
                                <select
                                    v-model="form.subscription_plan_id"
                                    class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text"
                                    required
                                >
                                    <option value="" disabled>Elegí un plan</option>
                                    <option
                                        v-for="plan in plans.filter((p) => p.owner_type === (user.role === 'conductor' ? 'driver' : 'client'))"
                                        :key="plan.id"
                                        :value="plan.id"
                                    >
                                        {{ plan.name }} · ${{ plan.monthly_price }}/mes
                                    </option>
                                </select>
                                <InputError class="mt-2" :message="form.errors.subscription_plan_id" />
                            </div>

                            <div>
                                <InputLabel value="Vence el (opcional, en blanco = sin vencimiento)" />
                                <TextInput type="date" class="mt-1 block w-full" v-model="form.expires_at" />
                            </div>

                            <div>
                                <InputLabel value="Nota (ej: número de comprobante de transferencia)" />
                                <TextInput type="text" class="mt-1 block w-full" v-model="form.note" />
                            </div>

                            <div class="flex gap-2">
                                <PrimaryButton :disabled="form.processing">Confirmar activación</PrimaryButton>
                                <SecondaryButton type="button" @click="activatingUserId = null">Cancelar</SecondaryButton>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Paginado simple (sección 9.7) -->
                <div v-if="users.prev_page_url || users.next_page_url" class="flex justify-between">
                    <Link v-if="users.prev_page_url" :href="users.prev_page_url" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                        &larr; Anterior
                    </Link>
                    <span v-else></span>
                    <Link v-if="users.next_page_url" :href="users.next_page_url" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                        Siguiente &rarr;
                    </Link>
                </div>

                <!-- Bitácora de cambios recientes (sección 9.6: auditoría completa) -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-4">Cambios recientes</h3>

                    <p v-if="!recentChanges.length" class="text-sm text-arka-text-muted">Todavía no hay cambios de plan.</p>

                    <ul v-else class="divide-y divide-arka-text-muted/10">
                        <li v-for="change in recentChanges" :key="change.id" class="py-3 text-sm flex items-center gap-2">
                            <UserAvatar :user="change.user" size-class="h-6 w-6 text-[10px] shrink-0" />
                            <span>
                                <span class="text-arka-text font-medium">{{ change.user.name }}</span>
                                <span class="text-arka-text-muted">
                                    : {{ change.old_plan?.name ?? 'Gratis' }} &rarr; {{ change.new_plan.name }}
                                    <span v-if="change.changed_by"> (por {{ change.changed_by.name }})</span>
                                </span>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
