<script setup>
import { computed, ref } from 'vue';
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
    averageTicketPrice: { type: Number, required: true },
});

// computed (no const plano): props.plans cambia después de guardar/crear/
// eliminar un plan (Inertia actualiza los props sin recargar la página), y
// estas listas tienen que recalcularse con los datos nuevos en ese momento.
const driverPlans = computed(() => props.plans.filter((p) => p.owner_type === 'driver'));
const clientPlans = computed(() => props.plans.filter((p) => p.owner_type === 'client'));
const cooperativePlans = computed(() => props.plans.filter((p) => p.owner_type === 'cooperative'));

const editingId = ref(null);
const creatingFor = ref(null); // 'driver' | 'client' | null

const blankForm = (ownerType) => ({
    owner_type: ownerType,
    code: '',
    name: '',
    monthly_price: 0,
    // Solo tiene efecto en planes de COOPERATIVA (pedido explícito del
    // usuario): descuento que le da a su conductor afiliado en el plan
    // individual del conductor — ver PlanLimits::driverDiscountFor().
    driver_discount_percent: 0,
    max_clients: '',
    estimated_monthly_rides: '',
    public_visibility: false,
    priority_listing: false,
    verified_badge: false,
    van_trips_enabled: false,
    // Pedido explícito del usuario: a diferencia de las otras (premium,
    // arrancan apagadas), Expresos ya era abierto — un plan nuevo arranca
    // con el módulo habilitado, coherente con el default del backend.
    express_enabled: true,
    // Pedido explícito del usuario: por defecto un conductor solo puede
    // estar afiliado a UNA cooperativa activa — este flag habilita que los
    // conductores de este plan acepten solicitudes de más de una.
    multi_cooperative_enabled: false,
    max_fleets: '',
    max_drivers_per_fleet: '',
    max_cooperatives: '',
    max_units: '',
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
    form.driver_discount_percent = plan.driver_discount_percent ?? 0;
    form.max_clients = plan.max_clients ?? '';
    form.estimated_monthly_rides = plan.estimated_monthly_rides ?? '';
    form.public_visibility = plan.public_visibility;
    form.priority_listing = plan.priority_listing;
    form.verified_badge = plan.verified_badge;
    form.van_trips_enabled = plan.van_trips_enabled;
    form.express_enabled = plan.express_enabled;
    form.multi_cooperative_enabled = plan.multi_cooperative_enabled;
    form.max_fleets = plan.max_fleets ?? '';
    form.max_drivers_per_fleet = plan.max_drivers_per_fleet ?? '';
    form.max_cooperatives = plan.max_cooperatives ?? '';
    form.max_units = plan.max_units ?? '';
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

// Vista previa para el admin mientras carga/edita el número de carreras
// estimadas (mismo cálculo que MyPlanController::attachEarningsProjection()
// hace en el backend para mostrárselo al conductor).
function projectedEarnings(rides) {
    return (Number(rides) * props.averageTicketPrice).toFixed(2);
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
                <template v-for="(list, ownerType) in { driver: driverPlans, client: clientPlans, cooperative: cooperativePlans }" :key="ownerType">
                    <div class="bg-arka-card shadow rounded-arka">
                        <div class="p-4 sm:p-6 flex items-center justify-between border-b border-arka-text-muted/10">
                            <h3 class="text-lg font-medium text-arka-text">
                                Planes de {{ ownerType === 'driver' ? 'conductor' : ownerType === 'client' ? 'cliente' : 'cooperativa' }}
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
                                                <span v-if="plan.van_trips_enabled"> · Rutas y Turismo</span>
                                                <span v-if="!plan.express_enabled" class="text-arka-warning"> · sin Expresos</span>
                                                <span v-if="plan.multi_cooperative_enabled" class="text-arka-primary-bright"> · multi-cooperativa</span>
                                                <span v-if="plan.estimated_monthly_rides" class="text-arka-lime">
                                                    · ~{{ plan.estimated_monthly_rides }} carreras/mes (~${{ projectedEarnings(plan.estimated_monthly_rides) }})
                                                </span>
                                            </template>
                                            <template v-else-if="ownerType === 'client'">
                                                {{ plan.max_fleets }} flota(s) · {{ plan.max_drivers_per_fleet }} conductores/flota · {{ plan.max_cooperatives ?? 'sin límite' }} cooperativas
                                            </template>
                                            <template v-else>
                                                {{ plan.max_units ?? 'sin límite' }} unidades/conductores
                                                <span v-if="plan.driver_discount_percent > 0" class="text-arka-primary-bright">
                                                    · {{ plan.driver_discount_percent }}% descuento al conductor
                                                </span>
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
                                            <div>
                                                <InputLabel value="Carreras estimadas por mes (referencia)" />
                                                <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.estimated_monthly_rides" />
                                                <p v-if="form.estimated_monthly_rides" class="mt-1 text-xs text-arka-lime">
                                                    ≈ ${{ projectedEarnings(form.estimated_monthly_rides) }}/mes estimados para el conductor
                                                </p>
                                            </div>
                                        </template>
                                        <template v-else-if="ownerType === 'client'">
                                            <div>
                                                <InputLabel value="Máx. flotas" />
                                                <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.max_fleets" />
                                            </div>
                                            <div>
                                                <InputLabel value="Máx. conductores por flota" />
                                                <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.max_drivers_per_fleet" />
                                            </div>
                                            <div>
                                                <InputLabel value="Máx. cooperativas en la red" />
                                                <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.max_cooperatives" />
                                            </div>
                                        </template>
                                        <template v-else>
                                            <div>
                                                <InputLabel value="Máx. unidades/conductores (vacío = sin límite)" />
                                                <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.max_units" />
                                            </div>
                                            <div>
                                                <InputLabel value="% descuento al conductor afiliado" />
                                                <TextInput type="number" min="0" max="100" class="mt-1 block w-full" v-model="form.driver_discount_percent" />
                                                <p class="mt-1 text-xs text-arka-text-muted">
                                                    Descuento que este plan le da al conductor en SU propio plan individual, mientras esté afiliado a una cooperativa aprobada con este plan.
                                                </p>
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
                                            <Checkbox v-model:checked="form.van_trips_enabled" /> Rutas y Turismo
                                        </label>
                                        <label class="flex items-center gap-2 text-sm text-arka-text">
                                            <Checkbox v-model:checked="form.express_enabled" /> Expresos (rutas fijas y recurrentes)
                                        </label>
                                        <label class="flex items-center gap-2 text-sm text-arka-text">
                                            <Checkbox v-model:checked="form.multi_cooperative_enabled" /> Puede afiliarse a más de una cooperativa
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
                                        <div>
                                            <InputLabel value="Carreras estimadas por mes (referencia)" />
                                            <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.estimated_monthly_rides" />
                                            <p v-if="form.estimated_monthly_rides" class="mt-1 text-xs text-arka-lime">
                                                ≈ ${{ projectedEarnings(form.estimated_monthly_rides) }}/mes estimados para el conductor
                                            </p>
                                        </div>
                                    </template>
                                    <template v-else-if="ownerType === 'client'">
                                        <div>
                                            <InputLabel value="Máx. flotas" />
                                            <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.max_fleets" required />
                                        </div>
                                        <div>
                                            <InputLabel value="Máx. conductores por flota" />
                                            <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.max_drivers_per_fleet" required />
                                        </div>
                                        <div>
                                            <InputLabel value="Máx. cooperativas en la red" />
                                            <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.max_cooperatives" />
                                        </div>
                                    </template>
                                    <template v-else>
                                        <div>
                                            <InputLabel value="Máx. unidades/conductores (vacío = sin límite)" />
                                            <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.max_units" />
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
                                        <Checkbox v-model:checked="form.van_trips_enabled" /> Rutas y Turismo
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-arka-text">
                                        <Checkbox v-model:checked="form.express_enabled" /> Expresos (rutas fijas y recurrentes)
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-arka-text">
                                        <Checkbox v-model:checked="form.multi_cooperative_enabled" /> Puede afiliarse a más de una cooperativa
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
