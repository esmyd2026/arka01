<script setup>
import { computed, ref } from 'vue';
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
    filters: { type: Object, required: true },
});

// Rol legible + color de insignia por rol (sección 3.1: cada cuenta es
// cliente O conductor, nunca las dos — el admin no tiene plan).
const ROLE_LABELS = { admin: 'Administrador', conductor: 'Conductor', cliente: 'Cliente' };
const ROLE_BADGE_CLASS = {
    admin: 'bg-arka-warning/10 text-arka-warning',
    conductor: 'bg-arka-primary/10 text-arka-primary-bright',
    cliente: 'bg-arka-lime/10 text-arka-lime',
};

// De qué lado (owner_type de SubscriptionPlan) es un pedido de plan — ahora
// que las cooperativas también pueden pedir el suyo (pedido explícito del
// usuario: pantalla "Mi plan de cooperativa"), ya no alcanza con el
// driver/cliente binario que había antes acá.
const OWNER_TYPE_LABELS = { driver: 'Conductor', client: 'Cliente', cooperative: 'Cooperativa' };
const OWNER_TYPE_BADGE_CLASS = {
    driver: 'bg-arka-primary/15 text-arka-primary-bright',
    client: 'bg-arka-lime/15 text-arka-lime',
    cooperative: 'bg-arka-warning/15 text-arka-warning',
};

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

// Filtros + orden (pedido explícito del usuario: la lista no tenía forma de
// acotar ni de ordenar). Cada cambio pega directo al servidor — el filtrado
// por rol/plan y el orden por vencimiento dependen de datos que no vinieron
// en esta página (otros usuarios, otras suscripciones), no se puede resolver
// solo en el navegador.
const searchTerm = ref(props.search);
const roleFilter = ref(props.filters.role);
const planStatusFilter = ref(props.filters.plan_status);
const sortColumn = ref(props.filters.sort);
const sortDirection = ref(props.filters.direction);

const hasActiveFilters = computed(() => searchTerm.value !== '' || roleFilter.value !== '' || planStatusFilter.value !== '');

function applyFilters() {
    router.get(
        route('admin.subscriptions.index'),
        {
            q: searchTerm.value,
            role: roleFilter.value,
            plan_status: planStatusFilter.value,
            sort: sortColumn.value,
            direction: sortDirection.value,
        },
        { preserveState: true, preserveScroll: true, replace: true }
    );
}

function clearFilters() {
    searchTerm.value = '';
    roleFilter.value = '';
    planStatusFilter.value = '';
    applyFilters();
}

function toggleSort(column) {
    sortDirection.value = sortColumn.value === column && sortDirection.value === 'asc' ? 'desc' : 'asc';
    sortColumn.value = column;
    applyFilters();
}

// El formulario de activación se abre en un modal "anclado" a un usuario
// puntual (botón "Activar" de su fila), así el admin no tiene que buscarlo
// dos veces. Antes se abría inline dentro de la fila y empujaba el resto de
// la lista hacia abajo — no calza con una tabla de columnas fijas.
const activatingUser = ref(null);

const form = useForm({
    user_id: null,
    subscription_plan_id: '',
    expires_at: '',
    note: '',
});

function openActivation(user) {
    activatingUser.value = user;
    form.reset();
    form.user_id = user.id;
}

function closeActivation() {
    activatingUser.value = null;
    form.reset();
}

function submitActivation() {
    form.post(route('admin.subscriptions.store'), {
        preserveScroll: true,
        onSuccess: closeActivation,
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
                                    :class="OWNER_TYPE_BADGE_CLASS[req.plan.owner_type]"
                                >
                                    {{ OWNER_TYPE_LABELS[req.plan.owner_type] }}
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
                            <!-- Descuento por cooperativa (pedido explícito del usuario):
                                 mismo criterio que la promoción de arriba — sin esto el
                                 admin esperaría el precio de lista completo. -->
                            <p v-else-if="req.cooperative_discount" class="text-sm text-arka-primary-bright mt-0.5">
                                🏢 Descuento por cooperativa ({{ req.cooperative_discount.percent }}%) — correspondía
                                ${{ req.cooperative_discount.discounted_price }}/mes
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
                            <dd class="text-arka-text">{{ OWNER_TYPE_LABELS[viewingRequest.plan.owner_type] }}</dd>
                            <dt class="text-arka-text-muted">Plan solicitado</dt>
                            <dd class="text-arka-text">{{ viewingRequest.plan.name }}</dd>
                            <dt class="text-arka-text-muted">Monto a transferir</dt>
                            <dd class="text-arka-text font-semibold">
                                ${{
                                    viewingRequest.plan_promotion
                                        ? viewingRequest.plan_promotion.promo_price
                                        : viewingRequest.cooperative_discount
                                          ? viewingRequest.cooperative_discount.discounted_price
                                          : viewingRequest.plan.monthly_price
                                }}/mes
                            </dd>
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

                <!-- Formulario de activación de plan, en modal (pedido explícito del
                     usuario: la lista pasó a ser una tabla de columnas fijas, un
                     formulario que se abría inline empujando la fila hacia abajo ya
                     no calzaba con eso). -->
                <Modal :show="activatingUser !== null" max-width="md" @close="closeActivation">
                    <form v-if="activatingUser" @submit.prevent="submitActivation" class="p-6 space-y-4">
                        <h3 class="text-lg font-medium text-arka-text">Activar plan a {{ activatingUser.name }}</h3>

                        <div>
                            <!-- Pedido explícito del usuario: cada cuenta es cliente O
                                 conductor, nunca las dos — el select ya no ofrece planes
                                 del lado que no le corresponde a esta cuenta puntual. -->
                            <InputLabel :value="`Plan a activar (${activatingUser.role === 'conductor' ? 'conductor' : 'cliente'})`" />
                            <select
                                v-model="form.subscription_plan_id"
                                class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text"
                                required
                            >
                                <option value="" disabled>Elegí un plan</option>
                                <option
                                    v-for="plan in plans.filter((p) => p.owner_type === (activatingUser.role === 'conductor' ? 'driver' : 'client'))"
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
                            <SecondaryButton type="button" @click="closeActivation">Cancelar</SecondaryButton>
                        </div>
                    </form>
                </Modal>

                <!-- Filtros + orden (pedido explícito del usuario: antes solo había
                     un buscador de texto, sin forma de acotar por rol o por si tiene
                     plan pago, ni de ordenar la lista). -->
                <div class="bg-arka-card shadow rounded-arka p-4 sm:p-6">
                    <div class="flex flex-wrap items-end gap-3">
                        <div>
                            <InputLabel value="Buscar" />
                            <TextInput
                                v-model="searchTerm"
                                type="text"
                                placeholder="Nombre o correo"
                                class="mt-1 w-56"
                                @keyup.enter="applyFilters"
                            />
                        </div>
                        <div>
                            <InputLabel value="Rol" />
                            <select
                                v-model="roleFilter"
                                class="mt-1 block rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text text-sm"
                                @change="applyFilters"
                            >
                                <option value="">Todos</option>
                                <option value="cliente">Clientes</option>
                                <option value="conductor">Conductores</option>
                                <option value="admin">Administradores</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Plan" />
                            <select
                                v-model="planStatusFilter"
                                class="mt-1 block rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text text-sm"
                                @change="applyFilters"
                            >
                                <option value="">Todos</option>
                                <option value="con_plan">Con plan pago</option>
                                <option value="gratis">En el gratis</option>
                            </select>
                        </div>
                        <SecondaryButton type="button" @click="applyFilters">Buscar</SecondaryButton>
                        <button
                            v-if="hasActiveFilters"
                            type="button"
                            class="text-xs text-arka-text-muted hover:text-arka-text"
                            @click="clearFilters"
                        >
                            Limpiar filtros
                        </button>
                        <span class="ml-auto text-xs text-arka-text-muted self-center">{{ users.total }} usuario(s)</span>
                    </div>
                </div>

                <!-- Usuarios y su plan vigente por lado (conductor / cliente), en
                     tabla — la lista en tarjetas apiladas ocupaba mucho espacio por
                     cada suscriptor (pedido explícito del usuario). -->
                <div class="bg-arka-card shadow rounded-arka overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-arka-text-muted/10 text-left text-xs text-arka-text-muted uppercase tracking-wide">
                                    <th class="px-4 sm:px-6 py-3 font-medium">
                                        <button type="button" class="flex items-center gap-1 hover:text-arka-text" @click="toggleSort('name')">
                                            Usuario
                                            <span v-if="sortColumn === 'name'">{{ sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </button>
                                    </th>
                                    <th class="px-4 py-3 font-medium">Rol</th>
                                    <th class="px-4 py-3 font-medium">
                                        <button type="button" class="flex items-center gap-1 hover:text-arka-text" @click="toggleSort('expiry')">
                                            Plan vigente
                                            <span v-if="sortColumn === 'expiry'">{{ sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </button>
                                    </th>
                                    <!-- Pedido explícito del usuario: fecha de registro/actualización
                                         visibles, con orden por defecto por registro descendente (los
                                         suscriptores más nuevos primero). -->
                                    <th class="px-4 py-3 font-medium whitespace-nowrap">
                                        <button type="button" class="flex items-center gap-1 hover:text-arka-text" @click="toggleSort('created_at')">
                                            Registro
                                            <span v-if="sortColumn === 'created_at'">{{ sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </button>
                                    </th>
                                    <th class="px-4 py-3 font-medium whitespace-nowrap">
                                        <button type="button" class="flex items-center gap-1 hover:text-arka-text" @click="toggleSort('updated_at')">
                                            Actualización
                                            <span v-if="sortColumn === 'updated_at'">{{ sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                        </button>
                                    </th>
                                    <th class="px-4 sm:px-6 py-3 font-medium text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-arka-text-muted/10">
                                <tr v-for="user in users.data" :key="user.id" class="hover:bg-arka-base/40 transition">
                                    <td class="px-4 sm:px-6 py-3">
                                        <div class="flex items-center gap-3 min-w-0">
                                            <UserAvatar :user="user" size-class="h-9 w-9 text-xs shrink-0" />
                                            <div class="min-w-0">
                                                <p class="text-arka-text font-medium truncate">{{ user.name }}</p>
                                                <p class="text-xs text-arka-text-muted truncate">{{ user.email }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span class="px-2 py-1 rounded-arka text-xs" :class="ROLE_BADGE_CLASS[user.role]">
                                            {{ ROLE_LABELS[user.role] }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span v-if="user.role === 'admin'" class="text-xs text-arka-text-muted">Sin plan</span>
                                        <template v-else>
                                            <p class="text-arka-text">
                                                {{ (user.role === 'conductor' ? driverPlanOf(user) : clientPlanOf(user))?.plan.name ?? 'Gratis' }}
                                            </p>
                                            <p
                                                v-if="expiryLabel(user.role === 'conductor' ? driverPlanOf(user) : clientPlanOf(user))"
                                                class="text-xs text-arka-text-muted"
                                            >
                                                {{ expiryLabel(user.role === 'conductor' ? driverPlanOf(user) : clientPlanOf(user)) }}
                                            </p>
                                        </template>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-xs text-arka-text-muted">
                                        {{ new Date(user.created_at).toLocaleDateString('es-EC', { dateStyle: 'medium' }) }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-xs text-arka-text-muted">
                                        {{ new Date(user.updated_at).toLocaleDateString('es-EC', { dateStyle: 'medium' }) }}
                                    </td>
                                    <td class="px-4 sm:px-6 py-3">
                                        <!-- Pedido explícito del usuario ("mejorar esos estilos de
                                             botones", con captura): los botones de tamaño normal se
                                             veían pesados y repetitivos fila tras fila — acá alcanza
                                             con la variante compacta (size="sm"), mismo color/semántica
                                             pero a la escala de una tabla, no de una acción sola. -->
                                        <div class="flex items-center justify-end gap-2 flex-wrap">
                                            <Link
                                                :href="route('admin.users.show', user.id)"
                                                class="text-xs font-medium text-arka-primary hover:text-arka-primary-bright"
                                            >
                                                Ver perfil
                                            </Link>
                                            <PrimaryButton v-if="user.role !== 'admin'" size="sm" @click="openActivation(user)">Activar</PrimaryButton>
                                            <DangerButton v-if="driverPlanOf(user)" size="sm" @click="expireSubscription(driverPlanOf(user).id)">
                                                Baja
                                            </DangerButton>
                                            <DangerButton v-if="clientPlanOf(user)" size="sm" @click="expireSubscription(clientPlanOf(user).id)">
                                                Baja
                                            </DangerButton>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!users.data.length">
                                    <td colspan="6" class="px-4 sm:px-6 py-8 text-center text-sm text-arka-text-muted">
                                        No se encontraron usuarios con esos filtros.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
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
