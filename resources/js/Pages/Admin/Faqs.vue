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
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { confirmDialog } from '@/Utils/confirmDialog';

// Bug real reportado por el usuario (con captura): el <select> nativo se
// pinta con el tema del sistema operativo, no con el oscuro de la app.
const AUDIENCE_OPTIONS = [
    { value: 'ambos', label: 'Ambos' },
    { value: 'cliente', label: 'Cliente' },
    { value: 'conductor', label: 'Conductor' },
];

defineProps({
    faqs: { type: Array, required: true },
});

const AUDIENCE_LABEL = { cliente: 'Cliente', conductor: 'Conductor', ambos: 'Ambos' };

const editingId = ref(null);
const creating = ref(false);

const blankForm = () => ({ audience: 'ambos', category: '', question: '', answer: '', is_active: true, sort_order: 0 });
const form = useForm(blankForm());

function startEdit(faq) {
    editingId.value = faq.id;
    creating.value = false;
    form.clearErrors();
    form.audience = faq.audience;
    form.category = faq.category;
    form.question = faq.question;
    form.answer = faq.answer;
    form.is_active = faq.is_active;
    form.sort_order = faq.sort_order;
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
        form.patch(route('admin.faqs.update', editingId.value), { onSuccess: cancel, preserveScroll: true });
    } else {
        form.post(route('admin.faqs.store'), { onSuccess: cancel, preserveScroll: true });
    }
}

function toggleActive(faq) {
    router.patch(
        route('admin.faqs.update', faq.id),
        { audience: faq.audience, category: faq.category, question: faq.question, answer: faq.answer, sort_order: faq.sort_order, is_active: !faq.is_active },
        { preserveScroll: true }
    );
}

async function destroy(faq) {
    if (!(await confirmDialog(`¿Eliminar "${faq.question}"?`, { danger: true }))) return;
    router.delete(route('admin.faqs.destroy', faq.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · Preguntas frecuentes" />

    <AdminLayout title="Preguntas frecuentes">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Contenido del Centro de Ayuda — "Ambos" se ve para cliente y conductor; "Cliente"/"Conductor" solo
                    para ese rol.
                </p>

                <div class="bg-arka-card shadow rounded-arka">
                    <div class="p-4 sm:p-6 flex items-center justify-between border-b border-arka-text-muted/10">
                        <h3 class="text-lg font-medium text-arka-text">Preguntas</h3>
                        <PrimaryButton @click="startCreate">Nueva pregunta</PrimaryButton>
                    </div>

                    <div class="divide-y divide-arka-text-muted/10">
                        <div v-for="faq in faqs" :key="faq.id" class="p-4 sm:p-6">
                            <div v-if="editingId !== faq.id" class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <p class="text-xs text-arka-text-muted">
                                        {{ AUDIENCE_LABEL[faq.audience] }} · {{ faq.category }}
                                        <span v-if="!faq.is_active" class="text-arka-warning">· inactiva</span>
                                    </p>
                                    <p class="text-arka-text font-medium">{{ faq.question }}</p>
                                    <p class="text-sm text-arka-text-muted">{{ faq.answer }}</p>
                                </div>
                                <div class="flex gap-2 shrink-0">
                                    <SecondaryButton @click="toggleActive(faq)">{{ faq.is_active ? 'Desactivar' : 'Activar' }}</SecondaryButton>
                                    <SecondaryButton @click="startEdit(faq)">Editar</SecondaryButton>
                                    <DangerButton @click="destroy(faq)">Eliminar</DangerButton>
                                </div>
                            </div>

                            <form v-else @submit.prevent="submit" class="space-y-3">
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <InputLabel value="Para quién" />
                                        <SearchableSelect v-model="form.audience" :options="AUDIENCE_OPTIONS" class="mt-1" />
                                    </div>
                                    <div>
                                        <InputLabel value="Categoría" />
                                        <TextInput class="mt-1 block w-full" v-model="form.category" />
                                        <InputError class="mt-1" :message="form.errors.category" />
                                    </div>
                                </div>
                                <div>
                                    <InputLabel value="Pregunta" />
                                    <TextInput class="mt-1 block w-full" v-model="form.question" />
                                    <InputError class="mt-1" :message="form.errors.question" />
                                </div>
                                <div>
                                    <InputLabel value="Respuesta" />
                                    <textarea v-model="form.answer" rows="3" class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text"></textarea>
                                    <InputError class="mt-1" :message="form.errors.answer" />
                                </div>
                                <label class="flex items-center gap-2 text-sm text-arka-text">
                                    <Checkbox v-model:checked="form.is_active" /> Activa (se ve en el Centro de Ayuda)
                                </label>
                                <div class="flex gap-2">
                                    <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>
                                    <SecondaryButton type="button" @click="cancel">Cancelar</SecondaryButton>
                                </div>
                            </form>
                        </div>

                        <form v-if="creating" @submit.prevent="submit" class="p-4 sm:p-6 space-y-3">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <InputLabel value="Para quién" />
                                    <SearchableSelect v-model="form.audience" :options="AUDIENCE_OPTIONS" class="mt-1" />
                                </div>
                                <div>
                                    <InputLabel value="Categoría" />
                                    <TextInput class="mt-1 block w-full" v-model="form.category" required />
                                    <InputError class="mt-1" :message="form.errors.category" />
                                </div>
                            </div>
                            <div>
                                <InputLabel value="Pregunta" />
                                <TextInput class="mt-1 block w-full" v-model="form.question" required />
                                <InputError class="mt-1" :message="form.errors.question" />
                            </div>
                            <div>
                                <InputLabel value="Respuesta" />
                                <textarea v-model="form.answer" rows="3" required class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text"></textarea>
                                <InputError class="mt-1" :message="form.errors.answer" />
                            </div>
                            <div class="flex gap-2">
                                <PrimaryButton :disabled="form.processing">Crear pregunta</PrimaryButton>
                                <SecondaryButton type="button" @click="cancel">Cancelar</SecondaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
