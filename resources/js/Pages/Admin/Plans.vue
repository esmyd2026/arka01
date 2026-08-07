<script setup>
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { confirmDialog } from '@/Utils/confirmDialog';

const props = defineProps({
    plans: { type: Array, required: true },
});

const driverPlans = props.plans.filter((p) => p.owner_type === 'driver');
const clientPlans = props.plans.filter((p) => p.owner_type === 'client');

const editingId = ref(null);
const creatingFor = ref(null); // 'driver' | 'client' | null

const blankForm = (ownerType) => ({
    owner_type: ownerType,
    code: '',
    name: '',
    monthly_price: 0,
    max_clients: '',
    public_visibility: false,
    priority_listing: false,
    verified_badge: false,
    van_trips_enabled: false,
    // Pedido explícito del usuario: a diferencia de las otras (premium,
    // arrancan apagadas), Expresos ya era abierto — un plan nuevo arranca
    // con el módulo habilitado, coherente con el default del backend.
    express_enabled: true,
    max_fleets: '',
    max_drivers_per_fleet: '',
    is_active: true,
    sort_order: 0,
});

const form = useForm(blankForm('driver'));

function startEdit(plan) {
    editingId.value = plan.id;
    creatingFor.value = null;
    form.clearErrors();
    form.owner_type = plan.owner_type;
    form.code = plan.code;
    form.name = plan.name;
    form.monthly_price = plan.monthly_price;
    form.max_clients = plan.max_clients ?? '';
    form.public_visibility = plan.public_visibility;
    form.priority_listing = plan.priority_listing;
    form.verified_badge = plan.verified_badge;
    form.van_trips_enabled = plan.van_trips_enabled;
    form.express_enabled = plan.express_enabled;
    form.max_fleets = plan.max_fleets ?? '';
    form.max_drivers_per_fleet = plan.max_drivers_per_fleet ?? '';
    form.is_active = plan.is_active;
    form.sort_order = plan.sort_order;
}

function startCreate(ownerType) {
    creatingFor.value = ownerType;
    editingId.value = null;
    form.clearErrors();
    Object.assign(form, blankForm(ownerType));
}

function cancel() {
    editingId.value = null;
    creatingFor.value = null;
}

function submit() {
    if (editingId.value) {
        form.patch(route('admin.plans.update', editingId.value), { onSuccess: cancel });
    } else {
        form.post(route('admin.plans.store'), { onSuccess: cancel });
    }
}

async function destroyPlan(plan) {
    if (!(await confirmDialog(`¿Eliminar el plan "${plan.name}"? Solo se puede si nunca tuvo suscriptores.`, { danger: true }))) return;
    router.delete(route('admin.plans.destroy', plan.id));
}
</script>

<template>
    <Head title="Admin · Planes" />

    <AdminLayout title="Catálogo de planes">
        <div class="py-12">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
                <template v-for="(list, ownerType) in { driver: driverPlans, client: clientPlans }" :key="ownerType">
                    <div class="bg-arka-card shadow rounded-arka">
                        <div class="p-4 sm:p-6 flex items-center justify-between border-b border-arka-text-muted/10">
                            <h3 class="text-lg font-medium text-arka-text">
                                Planes de {{ ownerType === 'driver' ? 'conductor' : 'cliente' }}
                            </h3>
                            <PrimaryButton @click="startCreate(ownerType)">Nuevo plan</PrimaryButton>
                        </div>

                        <div class="divide-y divide-arka-text-muted/10">
                            <div v-for="plan in list" :key="plan.id" class="p-4 sm:p-6">
                                <div v-if="editingId !== plan.id" class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-arka-text font-medium">
                                            {{ plan.name }}
                                            <span class="text-xs text-arka-text-muted">({{ plan.code }})</span>
                                            <span v-if="!plan.is_active" class="text-xs text-arka-warning">· inactivo</span>
                                        </p>
                                        <p class="text-sm text-arka-text-muted">
                                            ${{ plan.monthly_price }}/mes ·
                                            <template v-if="ownerType === 'driver'">
                                                {{ plan.max_clients ?? 'sin límite' }} clientes
                                                <span v-if="plan.public_visibility"> · directorio</span>
                                                <span v-if="plan.priority_listing"> · prioridad</span>
                                                <span v-if="plan.verified_badge"> · insignia</span>
                                                <span v-if="plan.van_trips_enabled"> · VAN</span>
                                                <span v-if="!plan.express_enabled" class="text-arka-warning"> · sin Expresos</span>
                                            </template>
                                            <template v-else>
                                                {{ plan.max_fleets }} flota(s) · {{ plan.max_drivers_per_fleet }} conductores/flota
                                            </template>
                                            <span class="text-xs"> · {{ plan.subscriptions_count }} suscriptor(es) histórico(s)</span>
                                        </p>
                                    </div>
                                    <div class="flex gap-2 shrink-0">
                                        <SecondaryButton @click="startEdit(plan)">Editar</SecondaryButton>
                                        <DangerButton @click="destroyPlan(plan)">Eliminar</DangerButton>
                                    </div>
                                </div>

                                <!-- Formulario inline de edición -->
                                <form v-else @submit.prevent="submit" class="space-y-3">
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <InputLabel value="Código (estable, no cambiar sin necesidad)" />
                                            <TextInput class="mt-1 block w-full" v-model="form.code" />
                                            <InputError class="mt-1" :message="form.errors.code" />
                                        </div>
                                        <div>
                                            <InputLabel value="Nombre visible" />
                                            <TextInput class="mt-1 block w-full" v-model="form.name" />
                                            <InputError class="mt-1" :message="form.errors.name" />
                                        </div>
                                        <div>
                                            <InputLabel value="Precio mensual (USD)" />
                                            <TextInput type="number" step="0.01" min="0" class="mt-1 block w-full" v-model="form.monthly_price" />
                                        </div>
                                        <div>
                                            <InputLabel value="Orden en el catálogo" />
                                            <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.sort_order" />
                                        </div>

                                        <template v-if="ownerType === 'driver'">
                                            <div>
                                                <InputLabel value="Máx. clientes de confianza (vacío = sin límite)" />
                                                <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.max_clients" />
                                            </div>
                                        </template>
                                        <template v-else>
                                            <div>
                                                <InputLabel value="Máx. flotas" />
                                                <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.max_fleets" />
                                            </div>
                                            <div>
                                                <InputLabel value="Máx. conductores por flota" />
                                                <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.max_drivers_per_fleet" />
                                            </div>
                                        </template>
                                    </div>

                                    <div v-if="ownerType === 'driver'" class="flex flex-wrap gap-4">
                                        <label class="flex items-center gap-2 text-sm text-arka-text">
                                            <Checkbox v-model:checked="form.public_visibility" /> Directorio público
                                        </label>
                                        <label class="flex items-center gap-2 text-sm text-arka-text">
                                            <Checkbox v-model:checked="form.priority_listing" /> Prioridad
                                        </label>
                                        <label class="flex items-center gap-2 text-sm text-arka-text">
                                            <Checkbox v-model:checked="form.verified_badge" /> Insignia de verificado
                                        </label>
                                        <label class="flex items-center gap-2 text-sm text-arka-text">
                                            <Checkbox v-model:checked="form.van_trips_enabled" /> Viajes tipo VAN/turismo
                                        </label>
                                        <label class="flex items-center gap-2 text-sm text-arka-text">
                                            <Checkbox v-model:checked="form.express_enabled" /> Expresos (rutas fijas y recurrentes)
                                        </label>
                                    </div>

                                    <label class="flex items-center gap-2 text-sm text-arka-text">
                                        <Checkbox v-model:checked="form.is_active" /> Activo (visible para nuevas altas)
                                    </label>

                                    <div class="flex gap-2">
                                        <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>
                                        <SecondaryButton type="button" @click="cancel">Cancelar</SecondaryButton>
                                    </div>
                                </form>
                            </div>

                            <!-- Formulario de creación -->
                            <form v-if="creatingFor === ownerType" @submit.prevent="submit" class="p-4 sm:p-6 space-y-3">
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <InputLabel value="Código (slug, ej. premium)" />
                                        <TextInput class="mt-1 block w-full" v-model="form.code" required />
                                        <InputError class="mt-1" :message="form.errors.code" />
                                    </div>
                                    <div>
                                        <InputLabel value="Nombre visible" />
                                        <TextInput class="mt-1 block w-full" v-model="form.name" required />
                                        <InputError class="mt-1" :message="form.errors.name" />
                                    </div>
                                    <div>
                                        <InputLabel value="Precio mensual (USD)" />
                                        <TextInput type="number" step="0.01" min="0" class="mt-1 block w-full" v-model="form.monthly_price" required />
                                    </div>
                                    <div>
                                        <InputLabel value="Orden en el catálogo" />
                                        <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.sort_order" />
                                    </div>

                                    <template v-if="ownerType === 'driver'">
                                        <div>
                                            <InputLabel value="Máx. clientes de confianza (vacío = sin límite)" />
                                            <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.max_clients" />
                                        </div>
                                    </template>
                                    <template v-else>
                                        <div>
                                            <InputLabel value="Máx. flotas" />
                                            <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.max_fleets" required />
                                        </div>
                                        <div>
                                            <InputLabel value="Máx. conductores por flota" />
                                            <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.max_drivers_per_fleet" required />
                                        </div>
                                    </template>
                                </div>

                                <div v-if="ownerType === 'driver'" class="flex flex-wrap gap-4">
                                    <label class="flex items-center gap-2 text-sm text-arka-text">
                                        <Checkbox v-model:checked="form.public_visibility" /> Directorio público
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-arka-text">
                                        <Checkbox v-model:checked="form.priority_listing" /> Prioridad
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-arka-text">
                                        <Checkbox v-model:checked="form.verified_badge" /> Insignia de verificado
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-arka-text">
                                        <Checkbox v-model:checked="form.van_trips_enabled" /> Viajes tipo VAN/turismo
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-arka-text">
                                        <Checkbox v-model:checked="form.express_enabled" /> Expresos (rutas fijas y recurrentes)
                                    </label>
                                </div>

                                <div class="flex gap-2">
                                    <PrimaryButton :disabled="form.processing">Crear plan</PrimaryButton>
                                    <SecondaryButton type="button" @click="cancel">Cancelar</SecondaryButton>
                                </div>
                            </form>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </AdminLayout>
</template>
