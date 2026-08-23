<script setup>
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    settings: { type: Object, required: true },
});

// Pedido explícito del usuario ("por lo menos haz que la pueda colocar
// desde la parte de configuración del admin"): la imagen de fondo del hero
// de Welcome.vue, subida acá en vez de depender de copiarla a mano a
// public/img/ — mismo patrón de subida que Driver/Profile.vue
// (forceFormData + input file, ver DriverProfileController).
const form = useForm({
    hero_background: null,
    remove_hero_background: false,
});

// Vista previa del archivo recién elegido, antes de guardar — sin esto no
// hay forma de confirmar que se seleccionó la imagen correcta hasta después
// de guardar y recargar.
const previewUrl = ref(null);

function onFileChange(event) {
    const file = event.target.files[0] ?? null;
    form.hero_background = file;
    form.remove_hero_background = false;
    previewUrl.value = file ? URL.createObjectURL(file) : null;
}

function submit() {
    form.post(route('admin.site.update'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            previewUrl.value = null;
        },
    });
}

function removeBackground() {
    form.hero_background = null;
    form.remove_hero_background = true;
    previewUrl.value = null;
    form.post(route('admin.site.update'), { forceFormData: true, preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · Sitio" />

    <AdminLayout title="Sitio">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <form @submit.prevent="submit" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-4">
                    <div>
                        <h3 class="text-lg font-medium text-arka-text">Imagen de fondo del hero (Inicio público)</h3>
                        <p class="mt-1 text-sm text-arka-text-muted">
                            Se ve detrás del título y la tarjeta de "¿A dónde vamos?" en la portada de Arka01
                            (arka01.com), antes de iniciar sesión. Recomendado: imagen oscura y ancha (horizontal),
                            para que el texto se siga leyendo bien encima.
                        </p>
                    </div>

                    <div v-if="previewUrl || settings.hero_background_url" class="overflow-hidden rounded-arka border border-arka-text-muted/20">
                        <img :src="previewUrl || settings.hero_background_url" alt="Fondo del hero" class="h-40 w-full object-cover" />
                    </div>
                    <p v-else class="rounded-arka border border-arka-text-muted/15 bg-arka-base p-3 text-sm text-arka-text-muted">
                        Todavía no hay ninguna imagen — el hero se ve con el fondo oscuro liso de siempre.
                    </p>

                    <div>
                        <InputLabel for="hero_background" value="Elegir imagen nueva" />
                        <input
                            id="hero_background"
                            type="file"
                            accept="image/*"
                            class="mt-1 block w-full text-sm text-arka-text-muted file:mr-3 file:rounded-arka file:border-0 file:bg-arka-primary file:px-3 file:py-1.5 file:text-arka-base"
                            @change="onFileChange"
                        />
                        <p class="mt-1 text-xs text-arka-text-muted">JPG o PNG, hasta 8 MB.</p>
                        <InputError class="mt-2" :message="form.errors.hero_background" />
                    </div>

                    <div class="flex gap-2">
                        <PrimaryButton :disabled="form.processing || !form.hero_background">Guardar</PrimaryButton>
                        <SecondaryButton
                            v-if="settings.hero_background_url"
                            type="button"
                            :disabled="form.processing"
                            @click="removeBackground"
                        >
                            Quitar imagen actual
                        </SecondaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
