<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

// Último paso del registro rápido por teléfono (ver
// QuickRegistrationController): ya está logueado, solo falta esto para que
// el resto de la app deje de mandarlo para acá (ver
// App\Http\Middleware\EnsureProfileNameIsComplete).
const form = useForm({
    first_name: '',
    last_name: '',
});

const submit = () => {
    form.post(route('complete-profile.store'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Complete su perfil" />

        <div class="mb-6">
            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-arka-primary">Ya casi</p>
            <h1 class="mt-0.5 text-xl font-bold text-arka-text">¿Cómo se llama?</h1>
            <p class="mt-0.5 text-xs text-arka-text-muted">Su teléfono ya quedó verificado — solo falta esto.</p>
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="first_name" value="Nombre" />
                <TextInput
                    id="first_name"
                    v-model="form.first_name"
                    type="text"
                    class="mt-1 block w-full"
                    required
                    autofocus
                    autocomplete="given-name"
                />
                <InputError class="mt-2" :message="form.errors.first_name" />
            </div>

            <div class="mt-4">
                <InputLabel for="last_name" value="Apellido" />
                <TextInput
                    id="last_name"
                    v-model="form.last_name"
                    type="text"
                    class="mt-1 block w-full"
                    autocomplete="family-name"
                    @keydown.enter.prevent="submit"
                />
                <InputError class="mt-2" :message="form.errors.last_name" />
            </div>

            <PrimaryButton
                class="mt-6 min-h-12 w-full justify-center text-sm"
                :class="{ 'opacity-50': form.processing }"
                :disabled="form.processing || !form.first_name.trim()"
            >
                {{ form.processing ? 'Guardando…' : 'Continuar' }}
            </PrimaryButton>
        </form>
    </GuestLayout>
</template>
