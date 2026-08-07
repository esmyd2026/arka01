<script setup>
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    reasons: { type: Array, required: true },
});

const DIRECTION_LABEL = { client_to_driver: 'Cliente → Conductor', driver_to_client: 'Conductor → Cliente' };

const clientToDriver = props.reasons.filter((r) => r.direction === 'client_to_driver');
const driverToClient = props.reasons.filter((r) => r.direction === 'driver_to_client');

const editingId = ref(null);
const creatingFor = ref(null); // 'client_to_driver' | 'driver_to_client' | null

const blankForm = (direction) => ({ direction, text: '', is_active: true, sort_order: 0 });

const form = useForm(blankForm('client_to_driver'));

function startEdit(reason) {
    editingId.value = reason.id;
    creatingFor.value = null;
    form.clearErrors();
    form.direction = reason.direction;
    form.text = reason.text;
    form.is_active = reason.is_active;
    form.sort_order = reason.sort_order;
}

function startCreate(direction) {
    creatingFor.value = direction;
    editingId.value = null;
    form.clearErrors();
    Object.assign(form, blankForm(direction));
}

function cancel() {
    editingId.value = null;
    creatingFor.value = null;
}

function submit() {
    if (editingId.value) {
        form.patch(route('admin.rating-reasons.update', editingId.value), { onSuccess: cancel, preserveScroll: true });
    } else {
        form.post(route('admin.rating-reasons.store'), { onSuccess: cancel, preserveScroll: true });
    }
}

// Activar/desactivar de un clic, sin abrir el formulario completo (pedido
// explícito del usuario: "permitiendo... activar o desactivar los motivos").
function toggleActive(reason) {
    router.patch(
        route('admin.rating-reasons.update', reason.id),
        { direction: reason.direction, text: reason.text, sort_order: reason.sort_order, is_active: !reason.is_active },
        { preserveScroll: true }
    );
}
</script>

<template>
    <Head title="Admin · Motivos de calificación" />

    <AdminLayout title="Motivos de calificación">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
                <p class="text-sm text-arka-text-muted">
                    Cuando alguien baja la calificación de las 5 estrellas por defecto, tiene que elegir uno de estos
                    motivos — sirve para medir la calidad del servicio e identificar comportamientos recurrentes.
                </p>

                <template v-for="(list, direction) in { client_to_driver: clientToDriver, driver_to_client: driverToClient }" :key="direction">
                    <div class="bg-arka-card shadow rounded-arka">
                        <div class="p-4 sm:p-6 flex items-center justify-between border-b border-arka-text-muted/10">
                            <h3 class="text-lg font-medium text-arka-text">{{ DIRECTION_LABEL[direction] }}</h3>
                            <PrimaryButton @click="startCreate(direction)">Nuevo motivo</PrimaryButton>
                        </div>

                        <div class="divide-y divide-arka-text-muted/10">
                            <div v-for="reason in list" :key="reason.id" class="p-4 sm:p-6">
                                <div v-if="editingId !== reason.id" class="flex items-center justify-between gap-4">
                                    <p class="text-arka-text">
                                        {{ reason.text }}
                                        <span v-if="!reason.is_active" class="text-xs text-arka-warning">· inactivo</span>
                                    </p>
                                    <div class="flex gap-2 shrink-0">
                                        <SecondaryButton @click="toggleActive(reason)">
                                            {{ reason.is_active ? 'Desactivar' : 'Activar' }}
                                        </SecondaryButton>
                                        <SecondaryButton @click="startEdit(reason)">Editar</SecondaryButton>
                                    </div>
                                </div>

                                <form v-else @submit.prevent="submit" class="space-y-3">
                                    <div>
                                        <InputLabel value="Texto del motivo" />
                                        <TextInput class="mt-1 block w-full" v-model="form.text" />
                                        <InputError class="mt-1" :message="form.errors.text" />
                                    </div>
                                    <label class="flex items-center gap-2 text-sm text-arka-text">
                                        <Checkbox v-model:checked="form.is_active" /> Activo (aparece para elegir)
                                    </label>
                                    <div class="flex gap-2">
                                        <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>
                                        <SecondaryButton type="button" @click="cancel">Cancelar</SecondaryButton>
                                    </div>
                                </form>
                            </div>

                            <form v-if="creatingFor === direction" @submit.prevent="submit" class="p-4 sm:p-6 space-y-3">
                                <div>
                                    <InputLabel value="Texto del motivo" />
                                    <TextInput class="mt-1 block w-full" v-model="form.text" required />
                                    <InputError class="mt-1" :message="form.errors.text" />
                                </div>
                                <div class="flex gap-2">
                                    <PrimaryButton :disabled="form.processing">Crear motivo</PrimaryButton>
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
