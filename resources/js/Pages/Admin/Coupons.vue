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
    coupons: { type: Array, required: true },
});

const AUDIENCE_LABEL = { client: 'Cliente', driver: 'Conductor' };

const clientCoupons = props.coupons.filter((c) => c.audience === 'client');
const driverCoupons = props.coupons.filter((c) => c.audience === 'driver');

const editingId = ref(null);
const creatingFor = ref(null); // 'client' | 'driver' | null

const blankForm = (audience) => ({
    audience,
    title: '',
    description: '',
    button_label: '',
    button_url: '',
    is_active: true,
    sort_order: 0,
    expires_at: '',
    image: null,
});

const form = useForm(blankForm('client'));

function startEdit(coupon) {
    editingId.value = coupon.id;
    creatingFor.value = null;
    form.clearErrors();
    form.audience = coupon.audience;
    form.title = coupon.title;
    form.description = coupon.description ?? '';
    form.button_label = coupon.button_label ?? '';
    form.button_url = coupon.button_url ?? '';
    form.is_active = coupon.is_active;
    form.sort_order = coupon.sort_order;
    form.expires_at = coupon.expires_at ?? '';
    form.image = null;
}

function startCreate(audience) {
    creatingFor.value = audience;
    editingId.value = null;
    form.clearErrors();
    Object.assign(form, blankForm(audience));
}

function cancel() {
    editingId.value = null;
    creatingFor.value = null;
}

function submit() {
    if (editingId.value) {
        form.post(route('admin.coupons.update', editingId.value), { forceFormData: true, onSuccess: cancel });
    } else {
        form.post(route('admin.coupons.store'), { forceFormData: true, onSuccess: cancel });
    }
}

async function destroyCoupon(coupon) {
    if (!(await confirmDialog(`¿Eliminar el cupón "${coupon.title}"?`, { danger: true }))) return;
    router.delete(route('admin.coupons.destroy', coupon.id));
}

function toggleActive(coupon) {
    router.post(
        route('admin.coupons.update', coupon.id),
        {
            audience: coupon.audience,
            title: coupon.title,
            description: coupon.description,
            button_label: coupon.button_label,
            button_url: coupon.button_url,
            sort_order: coupon.sort_order,
            expires_at: coupon.expires_at,
            is_active: !coupon.is_active,
        },
        { preserveScroll: true }
    );
}
</script>

<template>
    <Head title="Admin · Cupones" />

    <AdminLayout title="Cupones y beneficios">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
                <p class="text-sm text-arka-text-muted">
                    Cupones ilimitados de comercios aliados, separados por audiencia — cliente y conductor tienen
                    promociones completamente independientes.
                </p>

                <template v-for="(list, audience) in { client: clientCoupons, driver: driverCoupons }" :key="audience">
                    <div class="bg-arka-card shadow rounded-arka">
                        <div class="p-4 sm:p-6 flex items-center justify-between border-b border-arka-text-muted/10">
                            <h3 class="text-lg font-medium text-arka-text">Cupones de {{ AUDIENCE_LABEL[audience] }}</h3>
                            <PrimaryButton @click="startCreate(audience)">Nuevo cupón</PrimaryButton>
                        </div>

                        <div class="divide-y divide-arka-text-muted/10">
                            <div v-for="coupon in list" :key="coupon.id" class="p-4 sm:p-6">
                                <div v-if="editingId !== coupon.id" class="flex items-start gap-4">
                                    <img :src="coupon.image_url" :alt="coupon.title" class="h-16 w-28 object-cover rounded-arka shrink-0" />
                                    <div class="flex-1 min-w-0">
                                        <p class="text-arka-text font-medium">
                                            {{ coupon.title }}
                                            <span v-if="!coupon.is_active" class="text-xs text-arka-warning">· inactivo</span>
                                        </p>
                                        <p v-if="coupon.description" class="text-sm text-arka-text-muted line-clamp-1">{{ coupon.description }}</p>
                                        <p v-if="coupon.expires_at" class="text-xs text-arka-text-muted">Vence {{ coupon.expires_at }}</p>
                                    </div>
                                    <div class="flex gap-2 shrink-0">
                                        <SecondaryButton @click="toggleActive(coupon)">
                                            {{ coupon.is_active ? 'Desactivar' : 'Activar' }}
                                        </SecondaryButton>
                                        <SecondaryButton @click="startEdit(coupon)">Editar</SecondaryButton>
                                        <DangerButton @click="destroyCoupon(coupon)">Eliminar</DangerButton>
                                    </div>
                                </div>

                                <form v-else @submit.prevent="submit" class="space-y-3">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <InputLabel value="Título" />
                                            <TextInput class="mt-1 block w-full" v-model="form.title" required />
                                            <InputError class="mt-1" :message="form.errors.title" />
                                        </div>
                                        <div>
                                            <InputLabel value="Botón (texto, opcional)" />
                                            <TextInput class="mt-1 block w-full" v-model="form.button_label" placeholder="Ej: Canjear" />
                                        </div>
                                    </div>
                                    <div>
                                        <InputLabel value="Descripción (opcional)" />
                                        <TextInput class="mt-1 block w-full" v-model="form.description" />
                                    </div>
                                    <div>
                                        <InputLabel value="Enlace (opcional)" />
                                        <TextInput type="url" class="mt-1 block w-full" v-model="form.button_url" placeholder="https://..." />
                                        <InputError class="mt-1" :message="form.errors.button_url" />
                                    </div>
                                    <div>
                                        <InputLabel value="Imagen (dejar vacío para conservar la actual)" />
                                        <input
                                            type="file"
                                            accept="image/*"
                                            class="mt-1 block w-full text-sm text-arka-text-muted file:mr-3 file:py-1.5 file:px-3 file:rounded-arka file:border-0 file:bg-arka-primary file:text-arka-base"
                                            @input="form.image = $event.target.files[0]"
                                        />
                                        <InputError class="mt-1" :message="form.errors.image" />
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <InputLabel value="Fecha de vigencia (opcional)" />
                                            <TextInput type="date" class="mt-1 block w-full" v-model="form.expires_at" />
                                        </div>
                                        <div>
                                            <InputLabel value="Orden de aparición" />
                                            <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.sort_order" />
                                        </div>
                                    </div>
                                    <label class="flex items-center gap-2 text-sm text-arka-text">
                                        <Checkbox v-model:checked="form.is_active" /> Activo
                                    </label>
                                    <div class="flex gap-2">
                                        <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>
                                        <SecondaryButton type="button" @click="cancel">Cancelar</SecondaryButton>
                                    </div>
                                </form>
                            </div>

                            <form v-if="creatingFor === audience" @submit.prevent="submit" class="p-4 sm:p-6 space-y-3">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <InputLabel value="Título" />
                                        <TextInput class="mt-1 block w-full" v-model="form.title" required />
                                        <InputError class="mt-1" :message="form.errors.title" />
                                    </div>
                                    <div>
                                        <InputLabel value="Botón (texto, opcional)" />
                                        <TextInput class="mt-1 block w-full" v-model="form.button_label" placeholder="Ej: Canjear" />
                                    </div>
                                </div>
                                <div>
                                    <InputLabel value="Descripción (opcional)" />
                                    <TextInput class="mt-1 block w-full" v-model="form.description" />
                                </div>
                                <div>
                                    <InputLabel value="Enlace (opcional)" />
                                    <TextInput type="url" class="mt-1 block w-full" v-model="form.button_url" placeholder="https://..." />
                                    <InputError class="mt-1" :message="form.errors.button_url" />
                                </div>
                                <div>
                                    <InputLabel value="Imagen" />
                                    <input
                                        type="file"
                                        accept="image/*"
                                        required
                                        class="mt-1 block w-full text-sm text-arka-text-muted file:mr-3 file:py-1.5 file:px-3 file:rounded-arka file:border-0 file:bg-arka-primary file:text-arka-base"
                                        @input="form.image = $event.target.files[0]"
                                    />
                                    <InputError class="mt-1" :message="form.errors.image" />
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <InputLabel value="Fecha de vigencia (opcional)" />
                                        <TextInput type="date" class="mt-1 block w-full" v-model="form.expires_at" />
                                    </div>
                                    <div>
                                        <InputLabel value="Orden de aparición" />
                                        <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.sort_order" />
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <PrimaryButton :disabled="form.processing">Crear cupón</PrimaryButton>
                                    <SecondaryButton type="button" @click="cancel">Cancelar</SecondaryButton>
                                </div>
                            </form>

                            <p v-if="!list.length && creatingFor !== audience" class="p-4 sm:p-6 text-sm text-arka-text-muted">
                                Todavía no hay cupones de {{ AUDIENCE_LABEL[audience] }}.
                            </p>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </AdminLayout>
</template>
