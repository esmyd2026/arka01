<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    phone: { type: String, required: true },
    status: { type: String, default: null },
});

const form = useForm({ code: '' });

const submit = () => {
    form.post(route('phone.verify.store'), {
        onFinish: () => form.reset('code'),
    });
};

function resend() {
    form.post(route('phone.verify.resend'), { preserveScroll: true });
}
</script>

<template>
    <GuestLayout>
        <Head title="Verificar teléfono" />

        <div class="mb-4 text-sm text-arka-text-muted">
            Le mandamos un código de 6 dígitos por WhatsApp al número <strong class="text-arka-text">{{ phone }}</strong>.
            Ingréselo para confirmar que este teléfono es suyo — así nadie más puede usar su cuenta para manejar su flota.
        </div>

        <div v-if="status" class="mb-4 text-sm font-medium text-arka-primary-bright">
            {{ status }}
        </div>

        <form @submit.prevent="submit">
            <div>
                <InputLabel for="code" value="Código de verificación" />

                <TextInput
                    id="code"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    maxlength="6"
                    class="mt-1 block w-full tracking-[0.3em] text-center text-lg"
                    v-model="form.code"
                    required
                    autofocus
                    placeholder="000000"
                />

                <InputError class="mt-2" :message="form.errors.code" />
            </div>

            <div class="flex items-center justify-between mt-4">
                <SecondaryButton type="button" :disabled="form.processing" @click="resend">
                    Reenviar código
                </SecondaryButton>

                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Verificar
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
