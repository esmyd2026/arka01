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
    banners: { type: Array, required: true },
});

const editingId = ref(null);
const creating = ref(false);

const blankForm = () => ({
    title: '',
    description: '',
    button_label: '',
    button_url: '',
    is_active: true,
    sort_order: 0,
    starts_at: '',
    ends_at: '',
    image: null,
});

const form = useForm(blankForm());

function startEdit(banner) {
    editingId.value = banner.id;
    creating.value = false;
    form.clearErrors();
    form.title = banner.title;
    form.description = banner.description ?? '';
    form.button_label = banner.button_label ?? '';
    form.button_url = banner.button_url ?? '';
    form.is_active = banner.is_active;
    form.sort_order = banner.sort_order;
    form.starts_at = banner.starts_at ?? '';
    form.ends_at = banner.ends_at ?? '';
    form.image = null;
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
    // POST siempre (aunque sea "editar"): con archivos, Laravel necesita
    // multipart/form-data, que un método PATCH/PUT nativo no puede llevar
    // — mismo criterio que DriverProfileController::update().
    if (editingId.value) {
        form.post(route('admin.ad-banners.update', editingId.value), { forceFormData: true, onSuccess: cancel });
    } else {
        form.post(route('admin.ad-banners.store'), { forceFormData: true, onSuccess: cancel });
    }
}

async function destroyBanner(banner) {
    if (!(await confirmDialog(`¿Eliminar el banner "${banner.title}"?`, { danger: true }))) return;
    router.delete(route('admin.ad-banners.destroy', banner.id));
}

// Activar/desactivar de un clic (pedido explícito del usuario), sin abrir el
// formulario completo — reenvía los mismos datos con solo is_active cambiado.
function toggleActive(banner) {
    router.post(
        route('admin.ad-banners.update', banner.id),
        {
            title: banner.title,
            description: banner.description,
            button_label: banner.button_label,
            button_url: banner.button_url,
            sort_order: banner.sort_order,
            starts_at: banner.starts_at,
            ends_at: banner.ends_at,
            is_active: !banner.is_active,
        },
        { preserveScroll: true }
    );
}
</script>

<template>
    <Head title="Admin · Banners" />

    <AdminLayout title="Banners publicitarios">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Espacio publicitario del inicio (módulo de monetización): imagen, título, descripción y botón de
                    acción hacia un sitio externo. Vendible a talleres, aseguradoras, lavadoras, restaurantes y otros
                    negocios aliados.
                </p>

                <div class="bg-arka-card shadow rounded-arka">
                    <div class="p-4 sm:p-6 flex items-center justify-between border-b border-arka-text-muted/10">
                        <h3 class="text-lg font-medium text-arka-text">Banners</h3>
                        <PrimaryButton @click="startCreate">Nuevo banner</PrimaryButton>
                    </div>

                    <div class="divide-y divide-arka-text-muted/10">
                        <div v-for="banner in banners" :key="banner.id" class="p-4 sm:p-6">
                            <div v-if="editingId !== banner.id" class="flex items-start gap-4">
                                <img :src="banner.image_url" :alt="banner.title" class="h-16 w-28 object-cover rounded-arka shrink-0" />
                                <div class="flex-1 min-w-0">
                                    <p class="text-arka-text font-medium">
                                        {{ banner.title }}
                                        <span v-if="!banner.is_active" class="text-xs text-arka-warning">· inactivo</span>
                                    </p>
                                    <p v-if="banner.description" class="text-sm text-arka-text-muted line-clamp-1">{{ banner.description }}</p>
                                    <p class="text-xs text-arka-text-muted">
                                        Orden {{ banner.sort_order }}
                                        <span v-if="banner.starts_at || banner.ends_at">
                                            · vigente {{ banner.starts_at ?? 'ya' }} → {{ banner.ends_at ?? 'sin fin' }}
                                        </span>
                                    </p>
                                </div>
                                <div class="flex gap-2 shrink-0">
                                    <SecondaryButton @click="toggleActive(banner)">
                                        {{ banner.is_active ? 'Desactivar' : 'Activar' }}
                                    </SecondaryButton>
                                    <SecondaryButton @click="startEdit(banner)">Editar</SecondaryButton>
                                    <DangerButton @click="destroyBanner(banner)">Eliminar</DangerButton>
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
                                        <TextInput class="mt-1 block w-full" v-model="form.button_label" placeholder="Ej: Ver oferta" />
                                    </div>
                                </div>

                                <div>
                                    <InputLabel value="Descripción corta (opcional)" />
                                    <TextInput class="mt-1 block w-full" v-model="form.description" />
                                    <InputError class="mt-1" :message="form.errors.description" />
                                </div>

                                <div>
                                    <InputLabel value="URL de destino (opcional)" />
                                    <TextInput type="url" class="mt-1 block w-full" v-model="form.button_url" placeholder="https://..." />
                                    <InputError class="mt-1" :message="form.errors.button_url" />
                                </div>

                                <div>
                                    <InputLabel value="Imagen" />
                                    <img v-if="banner?.image_url" :src="banner.image_url" class="mt-1 h-20 rounded-arka" />
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
                                        <InputLabel value="Vigencia desde (opcional)" />
                                        <TextInput type="date" class="mt-1 block w-full" v-model="form.starts_at" />
                                    </div>
                                    <div>
                                        <InputLabel value="Vigencia hasta (opcional)" />
                                        <TextInput type="date" class="mt-1 block w-full" v-model="form.ends_at" />
                                        <InputError class="mt-1" :message="form.errors.ends_at" />
                                    </div>
                                </div>

                                <div>
                                    <InputLabel value="Orden de aparición" />
                                    <TextInput type="number" min="0" class="mt-1 block w-32" v-model="form.sort_order" />
                                </div>

                                <label class="flex items-center gap-2 text-sm text-arka-text">
                                    <Checkbox v-model:checked="form.is_active" /> Activo (visible en el inicio)
                                </label>

                                <div class="flex gap-2">
                                    <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>
                                    <SecondaryButton type="button" @click="cancel">Cancelar</SecondaryButton>
                                </div>
                            </form>
                        </div>

                        <form v-if="creating" @submit.prevent="submit" class="p-4 sm:p-6 space-y-3">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <InputLabel value="Título" />
                                    <TextInput class="mt-1 block w-full" v-model="form.title" required />
                                    <InputError class="mt-1" :message="form.errors.title" />
                                </div>
                                <div>
                                    <InputLabel value="Botón (texto, opcional)" />
                                    <TextInput class="mt-1 block w-full" v-model="form.button_label" placeholder="Ej: Ver oferta" />
                                </div>
                            </div>

                            <div>
                                <InputLabel value="Descripción corta (opcional)" />
                                <TextInput class="mt-1 block w-full" v-model="form.description" />
                                <InputError class="mt-1" :message="form.errors.description" />
                            </div>

                            <div>
                                <InputLabel value="URL de destino (opcional)" />
                                <TextInput type="url" class="mt-1 block w-full" v-model="form.button_url" placeholder="https://..." />
                                <InputError class="mt-1" :message="form.errors.button_url" />
                            </div>

                            <div>
                                <InputLabel value="Imagen" />
                                <input
                                    type="file"
                                    accept="image/*"
                                    class="mt-1 block w-full text-sm text-arka-text-muted file:mr-3 file:py-1.5 file:px-3 file:rounded-arka file:border-0 file:bg-arka-primary file:text-arka-base"
                                    required
                                    @input="form.image = $event.target.files[0]"
                                />
                                <InputError class="mt-1" :message="form.errors.image" />
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

                            <div>
                                <InputLabel value="Orden de aparición" />
                                <TextInput type="number" min="0" class="mt-1 block w-32" v-model="form.sort_order" />
                            </div>

                            <div class="flex gap-2">
                                <PrimaryButton :disabled="form.processing">Crear banner</PrimaryButton>
                                <SecondaryButton type="button" @click="cancel">Cancelar</SecondaryButton>
                            </div>
                        </form>

                        <p v-if="!banners.length && !creating" class="p-4 sm:p-6 text-sm text-arka-text-muted">
                            Todavía no hay ningún banner.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
