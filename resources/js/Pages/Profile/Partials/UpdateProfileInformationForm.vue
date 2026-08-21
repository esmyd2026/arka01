<script setup>
import { computed, ref } from 'vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
    cities: {
        type: Array,
        required: true,
    },
});

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
    city_id: user.city_id,
    avatar: null,
});

// Vista previa de la foto elegida, antes de guardar (mismo criterio que la
// licencia/vehículo en Driver/Profile.vue) — si no eligió una nueva, se
// sigue mostrando el avatar actual vía UserAvatar más abajo.
const avatarPreview = ref(null);

// Pedido explícito del usuario: el mensaje de "la foto pesa demasiado" no
// era claro. Mismo límite que el backend (ProfileUpdateRequest, 4 MB), pero
// avisando ACÁ, antes de subir nada — no tiene sentido mandar un archivo
// pesado entero para recién enterarse del error al volver la respuesta.
const MAX_AVATAR_SIZE_MB = 4;
const avatarSizeError = ref(null);
const avatarFileName = ref('');

function onAvatarChange(event) {
    const file = event.target.files[0];
    avatarSizeError.value = null;

    if (file && file.size > MAX_AVATAR_SIZE_MB * 1024 * 1024) {
        avatarSizeError.value = `La foto pesa ${(file.size / 1024 / 1024).toFixed(1)} MB — el máximo es ${MAX_AVATAR_SIZE_MB} MB. Elegí una más liviana o comprimida.`;
        event.target.value = '';
        form.avatar = null;
        avatarPreview.value = null;
        avatarFileName.value = '';
        return;
    }

    form.avatar = file ?? null;
    avatarPreview.value = file ? URL.createObjectURL(file) : null;
    avatarFileName.value = file?.name ?? '';
}

// Bug real reportado: el <select> nativo se veía con texto ilegible (blanco
// sobre blanco) — mismo problema ya resuelto en "Solicitar carrera" con este
// mismo componente (el navegador pinta el panel desplegable de un <select>
// con su propio tema del sistema operativo, no con las clases de Tailwind).
const cityOptions = computed(() => props.cities.map((city) => ({ value: city.id, label: city.name })));
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-arka-text">Información del perfil</h2>

            <p class="mt-1 text-sm text-arka-text-muted">
                Actualice su nombre y su correo electrónico.
            </p>
        </header>

        <form @submit.prevent="form.patch(route('profile.update'))" class="mt-6 space-y-6">
            <div>
                <InputLabel for="avatar" value="Foto de perfil" />
                <div class="mt-2 flex min-w-0 items-center gap-3">
                    <img
                        v-if="avatarPreview"
                        :src="avatarPreview"
                        alt="Vista previa"
                        class="h-16 w-16 shrink-0 rounded-full object-cover"
                    />
                    <UserAvatar v-else :user="user" size-class="h-16 w-16 shrink-0 text-lg" />
                    <input
                        id="avatar"
                        type="file"
                        accept="image/*"
                        class="sr-only"
                        @change="onAvatarChange"
                    />
                    <div class="min-w-0 flex-1">
                        <label
                            for="avatar"
                            class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-arka bg-arka-primary px-3 py-2 text-sm font-semibold text-arka-base transition hover:bg-arka-primary-bright focus-within:ring-2 focus-within:ring-arka-primary"
                        >
                            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M8.25 3A2.25 2.25 0 0 0 6 5.25V6H4.25A2.25 2.25 0 0 0 2 8.25v9.5A2.25 2.25 0 0 0 4.25 20h15.5A2.25 2.25 0 0 0 22 17.75v-9.5A2.25 2.25 0 0 0 19.75 6H18v-.75A2.25 2.25 0 0 0 15.75 3h-7.5ZM12 8a4.25 4.25 0 1 1 0 8.5A4.25 4.25 0 0 1 12 8Z" />
                            </svg>
                            {{ avatarFileName ? 'Cambiar foto' : 'Seleccionar foto' }}
                        </label>
                        <p class="mt-1.5 truncate text-xs text-arka-text-muted" :title="avatarFileName || 'Ningún archivo seleccionado'">
                            {{ avatarFileName || 'Ningún archivo seleccionado' }}
                        </p>
                    </div>
                </div>
                <p class="mt-1 text-xs text-arka-text-muted">JPG o PNG, máximo {{ MAX_AVATAR_SIZE_MB }} MB.</p>
                <InputError class="mt-2" :message="avatarSizeError ?? form.errors.avatar" />
            </div>

            <div>
                <InputLabel for="name" value="Nombre" />

                <TextInput
                    id="name"
                    type="text"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                />

                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" value="Correo electrónico" />

                <TextInput
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    required
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="city_id" value="Ciudad donde vive" />

                <SearchableSelect
                    id="city_id"
                    class="mt-1"
                    v-model="form.city_id"
                    :options="cityOptions"
                    empty-label="Sin especificar"
                />
                <p class="mt-1 text-xs text-arka-text-muted">
                    Es la ciudad con la que arranca por defecto al pedir una carrera.
                </p>
                <InputError class="mt-2" :message="form.errors.city_id" />
            </div>

            <div v-if="mustVerifyEmail && user.email_verified_at === null">
                <p class="text-sm mt-2 text-arka-text">
                    Su correo todavía no está verificado.
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="underline text-sm text-arka-text-muted hover:text-arka-text rounded-arka focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-arka-card focus:ring-arka-primary"
                    >
                        Haga clic acá para reenviar el correo de verificación.
                    </Link>
                </p>

                <div
                    v-show="status === 'verification-link-sent'"
                    class="mt-2 font-medium text-sm text-arka-primary-bright"
                >
                    Le enviamos un nuevo enlace de verificación a su correo.
                </div>
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
