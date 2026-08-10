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
    promotions: { type: Array, required: true },
    plans: { type: Array, required: true },
});

const editingId = ref(null);
const creating = ref(false);

const blankForm = () => ({
    subscription_plan_id: props.plans[0]?.id ?? null,
    label: '',
    promo_price: '',
    starts_at: '',
    ends_at: '',
    is_active: true,
});

const form = useForm(blankForm());

function startEdit(promotion) {
    editingId.value = promotion.id;
    creating.value = false;
    form.clearErrors();
    form.subscription_plan_id = promotion.subscription_plan_id;
    form.label = promotion.label;
    form.promo_price = promotion.promo_price;
    form.starts_at = promotion.starts_at ?? '';
    form.ends_at = promotion.ends_at ?? '';
    form.is_active = promotion.is_active;
}

function startCreate() {
    creating.value = true;
    editingId.value = null;
    form.clearErrors();
    Object.assign(form, blankForm());
}

function cancel() {
    editingId.value = null;
    creating.value = false;
}

function submit() {
    if (editingId.value) {
        form.patch(route('admin.plan-promotions.update', editingId.value), { onSuccess: cancel });
    } else {
        form.post(route('admin.plan-promotions.store'), { onSuccess: cancel });
    }
}

async function destroyPromotion(promotion) {
    if (!(await confirmDialog(`¿Eliminar la promoción "${promotion.label}"?`, { danger: true }))) return;
    router.delete(route('admin.plan-promotions.destroy', promotion.id));
}

// Activar/desactivar de un clic (mismo criterio que Admin/AdBanners.vue),
// sin abrir el formulario completo.
function toggleActive(promotion) {
    router.patch(
        route('admin.plan-promotions.update', promotion.id),
        {
            subscription_plan_id: promotion.subscription_plan_id,
            label: promotion.label,
            promo_price: promotion.promo_price,
            starts_at: promotion.starts_at,
            ends_at: promotion.ends_at,
            is_active: !promotion.is_active,
        },
        { preserveScroll: true }
    );
}
</script>

<template>
    <Head title="Admin · Promociones" />

    <AdminLayout title="Promociones">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Precio promocional por tiempo limitado en un plan (ej. "1 mes gratis"): mientras esté vigente, se le
                    muestra a quien vea ese plan en "Mi plan" — cuánto pagaría y cuánto ahorra — y se oculta sola a
                    quien ya la usó una vez.
                </p>

                <div class="bg-arka-card shadow rounded-arka">
                    <div class="p-4 sm:p-6 flex items-center justify-between border-b border-arka-text-muted/10">
                        <h3 class="text-lg font-medium text-arka-text">Promociones</h3>
                        <PrimaryButton v-if="plans.length" @click="startCreate">Nueva promoción</PrimaryButton>
                    </div>

                    <div class="divide-y divide-arka-text-muted/10">
                        <div v-for="promotion in promotions" :key="promotion.id" class="p-4 sm:p-6">
                            <div v-if="editingId !== promotion.id" class="flex items-start justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    <p class="text-arka-text font-medium">
                                        {{ promotion.label }}
                                        <span v-if="!promotion.is_active" class="text-xs text-arka-warning">· inactiva</span>
                                    </p>
                                    <p class="text-sm text-arka-text-muted">
                                        {{ promotion.plan.name }} ({{ promotion.plan.owner_type === 'driver' ? 'conductor' : 'cliente' }})
                                        — ${{ Number(promotion.promo_price).toFixed(2) }}/mes en vez de ${{ Number(promotion.plan.monthly_price).toFixed(2) }}/mes
                                    </p>
                                    <p class="text-xs text-arka-text-muted">
                                        <span v-if="promotion.starts_at || promotion.ends_at">
                                            Vigente {{ promotion.starts_at ?? 'ya' }} → {{ promotion.ends_at ?? 'sin fin' }}
                                        </span>
                                        <span v-else>Sin fecha de vigencia (siempre visible mientras esté activa)</span>
                                    </p>
                                </div>
                                <div class="flex gap-2 shrink-0">
                                    <SecondaryButton @click="toggleActive(promotion)">
                                        {{ promotion.is_active ? 'Desactivar' : 'Activar' }}
                                    </SecondaryButton>
                                    <SecondaryButton @click="startEdit(promotion)">Editar</SecondaryButton>
                                    <DangerButton @click="destroyPromotion(promotion)">Eliminar</DangerButton>
                                </div>
                            </div>

                            <form v-else @submit.prevent="submit" class="space-y-3">
                                <div>
                                    <InputLabel value="Plan" />
                                    <select
                                        v-model="form.subscription_plan_id"
                                        class="mt-1 block w-full rounded-arka border-arka-text-muted/30 bg-arka-card text-arka-text"
                                    >
                                        <option v-for="plan in plans" :key="plan.id" :value="plan.id">
                                            {{ plan.name }} ({{ plan.owner_type === 'driver' ? 'conductor' : 'cliente' }}) — ${{ Number(plan.monthly_price).toFixed(2) }}/mes
                                        </option>
                                    </select>
                                    <InputError class="mt-1" :message="form.errors.subscription_plan_id" />
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <InputLabel value="Etiqueta (ej: 1 mes gratis)" />
                                        <TextInput class="mt-1 block w-full" v-model="form.label" required />
                                        <InputError class="mt-1" :message="form.errors.label" />
                                    </div>
                                    <div>
                                        <InputLabel value="Precio promocional (USD/mes)" />
                                        <TextInput type="number" step="0.01" min="0" class="mt-1 block w-full" v-model="form.promo_price" required />
                                        <InputError class="mt-1" :message="form.errors.promo_price" />
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <InputLabel value="Vigencia desde (opcional)" />
                                        <TextInput type="date" class="mt-1 block w-full" v-model="form.starts_at" />
                                    </div>
                                    <div>
                                        <InputLabel value="Vigencia hasta (opcional)" />
                                        <TextInput type="date" class="mt-1 block w-full" v-model="form.ends_at" />
                                        <InputError class="mt-1" :message="form.errors.ends_at" />
                                    </div>
                                </div>

                                <label class="flex items-center gap-2 text-sm text-arka-text">
                                    <Checkbox v-model:checked="form.is_active" /> Activa
                                </label>

                                <div class="flex gap-2">
                                    <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>
                                    <SecondaryButton type="button" @click="cancel">Cancelar</SecondaryButton>
                                </div>
                            </form>
                        </div>

                        <form v-if="creating" @submit.prevent="submit" class="p-4 sm:p-6 space-y-3">
                            <div>
                                <InputLabel value="Plan" />
                                <select
                                    v-model="form.subscription_plan_id"
                                    class="mt-1 block w-full rounded-arka border-arka-text-muted/30 bg-arka-card text-arka-text"
                                >
                                    <option v-for="plan in plans" :key="plan.id" :value="plan.id">
                                        {{ plan.name }} ({{ plan.owner_type === 'driver' ? 'conductor' : 'cliente' }}) — ${{ Number(plan.monthly_price).toFixed(2) }}/mes
                                    </option>
                                </select>
                                <InputError class="mt-1" :message="form.errors.subscription_plan_id" />
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <InputLabel value="Etiqueta (ej: 1 mes gratis)" />
                                    <TextInput class="mt-1 block w-full" v-model="form.label" required />
                                    <InputError class="mt-1" :message="form.errors.label" />
                                </div>
                                <div>
                                    <InputLabel value="Precio promocional (USD/mes)" />
                                    <TextInput type="number" step="0.01" min="0" class="mt-1 block w-full" v-model="form.promo_price" required />
                                    <InputError class="mt-1" :message="form.errors.promo_price" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <InputLabel value="Vigencia desde (opcional)" />
                                    <TextInput type="date" class="mt-1 block w-full" v-model="form.starts_at" />
                                </div>
                                <div>
                                    <InputLabel value="Vigencia hasta (opcional)" />
                                    <TextInput type="date" class="mt-1 block w-full" v-model="form.ends_at" />
                                    <InputError class="mt-1" :message="form.errors.ends_at" />
                                </div>
                            </div>

                            <div class="flex gap-2">
                                <PrimaryButton :disabled="form.processing">Crear promoción</PrimaryButton>
                                <SecondaryButton type="button" @click="cancel">Cancelar</SecondaryButton>
                            </div>
                        </form>

                        <p v-if="!promotions.length && !creating" class="p-4 sm:p-6 text-sm text-arka-text-muted">
                            Todavía no hay ninguna promoción.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
