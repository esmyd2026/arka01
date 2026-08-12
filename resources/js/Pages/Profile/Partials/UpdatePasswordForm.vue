<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const passwordInput = ref(null);
const currentPasswordInput = ref(null);

// Bug real reportado por el usuario: quien entra con Google tiene una
// contraseña al azar que nunca escribió — pedirle "la actual" acá lo dejaba
// bloqueado para siempre, sin forma de crear una propia. `password_set_at`
// (null = todavía no tiene una propia) distingue "crear" de "cambiar".
const hasOwnPassword = computed(() => usePage().props.auth.user.password_set_at !== null);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value.focus();
            }
            if (form.errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value.focus();
            }
        },
    });
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-arka-text">
                {{ hasOwnPassword ? 'Cambiar contraseña' : 'Crear contraseña' }}
            </h2>

            <p class="mt-1 text-sm text-arka-text-muted">
                <template v-if="hasOwnPassword">
                    Use una contraseña larga y aleatoria para mantener su cuenta segura.
                </template>
                <template v-else>
                    Inició sesión con Google — cree una contraseña propia para poder entrar también con su correo y
                    contraseña, sin depender de Google.
                </template>
            </p>
        </header>

        <form @submit.prevent="updatePassword" class="mt-6 space-y-6">
            <div v-if="hasOwnPassword">
                <InputLabel for="current_password" value="Contraseña actual" />

                <TextInput
                    id="current_password"
                    ref="currentPasswordInput"
                    v-model="form.current_password"
                    type="password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                />

                <InputError :message="form.errors.current_password" class="mt-2" />
            </div>

            <div>
                <InputLabel for="password" :value="hasOwnPassword ? 'Contraseña nueva' : 'Contraseña'" />

                <TextInput
                    id="password"
                    ref="passwordInput"
                    v-model="form.password"
                    type="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                />

                <InputError :message="form.errors.password" class="mt-2" />
            </div>

            <div>
                <InputLabel for="password_confirmation" value="Confirmar contraseña" />

                <TextInput
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                />

                <InputError :message="form.errors.password_confirmation" class="mt-2" />
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-sm text-arka-text-muted">Guardado.</p>
                </Transition>
            </div>
        </form>
    </section>
</template>
