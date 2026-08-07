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
import { tierColorClass, tierLabel } from '@/Utils/tierBadge';

const props = defineProps({
    tiers: { type: Array, required: true },
    colorKeys: { type: Array, required: true },
});

const editingId = ref(null);
const creating = ref(false);

const blankForm = () => ({
    name: '',
    min_points: 0,
    badge_emoji: '',
    color_key: 'slate',
    is_public_eligible: false,
});

const form = useForm(blankForm());

function startEdit(tier) {
    editingId.value = tier.id;
    creating.value = false;
    form.clearErrors();
    form.name = tier.name;
    form.min_points = tier.min_points;
    form.badge_emoji = tier.badge_emoji ?? '';
    form.color_key = tier.color_key;
    form.is_public_eligible = tier.is_public_eligible;
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
        form.patch(route('admin.driver-tiers.update', editingId.value), { onSuccess: cancel });
    } else {
        form.post(route('admin.driver-tiers.store'), { onSuccess: cancel });
    }
}

async function destroyTier(tier) {
    if (!(await confirmDialog(`¿Eliminar la medalla "${tier.name}"?`, { danger: true }))) return;
    router.delete(route('admin.driver-tiers.destroy', tier.id));
}
</script>

<template>
    <Head title="Admin · Medallas" />

    <AdminLayout title="Medallas de conductor">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Cada carrera que un conductor completa desde la app le suma puntos (1 o 2 según la distancia).
                    Al llegar a los puntos de una medalla, esa pasa a ser la vigente — la que tenga
                    "aparece en el directorio público" marcado habilita que ese conductor sea visible ahí, además
                    de necesitar un plan que lo permita.
                </p>

                <div class="bg-arka-card shadow rounded-arka">
                    <div class="p-4 sm:p-6 flex items-center justify-between border-b border-arka-text-muted/10">
                        <h3 class="text-lg font-medium text-arka-text">Medallas</h3>
                        <PrimaryButton @click="startCreate">Nueva medalla</PrimaryButton>
                    </div>

                    <div class="divide-y divide-arka-text-muted/10">
                        <div v-for="tier in tiers" :key="tier.id" class="p-4 sm:p-6">
                            <div v-if="editingId !== tier.id" class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-arka-text font-medium flex items-center gap-2">
                                        <span class="px-1.5 py-0.5 rounded text-xs font-medium" :class="tierColorClass(tier.color_key)">
                                            {{ tierLabel(tier) }}
                                        </span>
                                        <span v-if="tier.is_public_eligible" class="text-xs text-arka-primary-bright">· directorio público</span>
                                    </p>
                                    <p class="text-sm text-arka-text-muted">Desde {{ tier.min_points }} puntos</p>
                                </div>
                                <div class="flex gap-2 shrink-0">
                                    <SecondaryButton @click="startEdit(tier)">Editar</SecondaryButton>
                                    <DangerButton @click="destroyTier(tier)">Eliminar</DangerButton>
                                </div>
                            </div>

                            <!-- Formulario inline de edición -->
                            <form v-else @submit.prevent="submit" class="space-y-3">
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <InputLabel value="Nombre" />
                                        <TextInput class="mt-1 block w-full" v-model="form.name" />
                                        <InputError class="mt-1" :message="form.errors.name" />
                                    </div>
                                    <div>
                                        <InputLabel value="Desde cuántos puntos" />
                                        <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.min_points" />
                                        <InputError class="mt-1" :message="form.errors.min_points" />
                                    </div>
                                    <div>
                                        <InputLabel value="Emoji (opcional)" />
                                        <TextInput class="mt-1 block w-full" v-model="form.badge_emoji" placeholder="🥇" />
                                    </div>
                                    <div>
                                        <InputLabel value="Color" />
                                        <select v-model="form.color_key" class="mt-1 block w-full bg-arka-card border-arka-text-muted/30 text-arka-text rounded-arka">
                                            <option v-for="color in colorKeys" :key="color" :value="color">{{ color }}</option>
                                        </select>
                                        <span class="inline-block mt-1 px-1.5 py-0.5 rounded text-xs" :class="tierColorClass(form.color_key)">vista previa</span>
                                    </div>
                                </div>

                                <label class="flex items-center gap-2 text-sm text-arka-text">
                                    <Checkbox v-model:checked="form.is_public_eligible" /> Aparece en el directorio público
                                </label>

                                <div class="flex gap-2">
                                    <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>
                                    <SecondaryButton type="button" @click="cancel">Cancelar</SecondaryButton>
                                </div>
                            </form>
                        </div>

                        <!-- Formulario de creación -->
                        <form v-if="creating" @submit.prevent="submit" class="p-4 sm:p-6 space-y-3">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <InputLabel value="Nombre" />
                                    <TextInput class="mt-1 block w-full" v-model="form.name" required />
                                    <InputError class="mt-1" :message="form.errors.name" />
                                </div>
                                <div>
                                    <InputLabel value="Desde cuántos puntos" />
                                    <TextInput type="number" min="0" class="mt-1 block w-full" v-model="form.min_points" required />
                                    <InputError class="mt-1" :message="form.errors.min_points" />
                                </div>
                                <div>
                                    <InputLabel value="Emoji (opcional)" />
                                    <TextInput class="mt-1 block w-full" v-model="form.badge_emoji" placeholder="🥇" />
                                </div>
                                <div>
                                    <InputLabel value="Color" />
                                    <select v-model="form.color_key" class="mt-1 block w-full bg-arka-card border-arka-text-muted/30 text-arka-text rounded-arka">
                                        <option v-for="color in colorKeys" :key="color" :value="color">{{ color }}</option>
                                    </select>
                                    <span class="inline-block mt-1 px-1.5 py-0.5 rounded text-xs" :class="tierColorClass(form.color_key)">vista previa</span>
                                </div>
                            </div>

                            <label class="flex items-center gap-2 text-sm text-arka-text">
                                <Checkbox v-model:checked="form.is_public_eligible" /> Aparece en el directorio público
                            </label>

                            <div class="flex gap-2">
                                <PrimaryButton :disabled="form.processing">Crear medalla</PrimaryButton>
                                <SecondaryButton type="button" @click="cancel">Cancelar</SecondaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
