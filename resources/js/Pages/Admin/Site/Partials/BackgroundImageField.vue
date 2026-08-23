<script setup>
import { ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { useForm } from '@inertiajs/vue3';

// Un campo de imagen de fondo (hero del Inicio, panel de login...) —
// extraído porque Admin/Site/Edit.vue ya tiene 2 de estos con la misma
// lógica de subir/reemplazar/quitar, y solo cambia el nombre del campo y
// los textos. Cada instancia manda su propio POST (campo independiente del
// otro, no hay un único "Guardar" para los dos).
const props = defineProps({
    fieldKey: { type: String, required: true }, // ej. 'hero_background' → 'hero_background'/'remove_hero_background'
    title: { type: String, required: true },
    help: { type: String, required: true },
    currentUrl: { type: String, default: null },
    emptyMessage: { type: String, required: true },
});

const form = useForm({
    [props.fieldKey]: null,
    [`remove_${props.fieldKey}`]: false,
});

// Vista previa del archivo recién elegido, antes de guardar — sin esto no
// hay forma de confirmar que se seleccionó la imagen correcta hasta después
// de guardar y recargar.
const previewUrl = ref(null);

function onFileChange(event) {
    const file = event.target.files[0] ?? null;
    form[props.fieldKey] = file;
    form[`remove_${props.fieldKey}`] = false;
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
    form[props.fieldKey] = null;
    form[`remove_${props.fieldKey}`] = true;
    previewUrl.value = null;
    form.post(route('admin.site.update'), { forceFormData: true, preserveScroll: true });
}
</script>

<template>
    <form @submit.prevent="submit" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-4">
        <div>
            <h3 class="text-lg font-medium text-arka-text">{{ title }}</h3>
            <p class="mt-1 text-sm text-arka-text-muted">{{ help }}</p>
        </div>

        <div v-if="previewUrl || currentUrl" class="overflow-hidden rounded-arka border border-arka-text-muted/20">
            <img :src="previewUrl || currentUrl" :alt="title" class="h-40 w-full object-cover" />
        </div>
        <p v-else class="rounded-arka border border-arka-text-muted/15 bg-arka-base p-3 text-sm text-arka-text-muted">
            {{ emptyMessage }}
        </p>

        <div>
            <InputLabel :for="fieldKey" value="Elegir imagen nueva" />
            <input
                :id="fieldKey"
                type="file"
                accept="image/*"
                class="mt-1 block w-full text-sm text-arka-text-muted file:mr-3 file:rounded-arka file:border-0 file:bg-arka-primary file:px-3 file:py-1.5 file:text-arka-base"
                @change="onFileChange"
            />
            <p class="mt-1 text-xs text-arka-text-muted">JPG o PNG, hasta 8 MB.</p>
            <InputError class="mt-2" :message="form.errors[fieldKey]" />
        </div>

        <div class="flex gap-2">
            <PrimaryButton :disabled="form.processing || !form[fieldKey]">Guardar</PrimaryButton>
            <SecondaryButton v-if="currentUrl" type="button" :disabled="form.processing" @click="removeBackground">
                Quitar imagen actual
            </SecondaryButton>
        </div>
    </form>
</template>
