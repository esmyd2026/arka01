<script setup>
import { computed, ref } from 'vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
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
    // Teléfono editable (pedido explícito del usuario: "que tambien pueda
    // actualizar su numero de telefono") — mismo catálogo que usa el
    // registro y el resto de formularios de teléfono de la app.
    countryCodes: {
        type: Array,
        required: true,
    },
});

const user = usePage().props.auth.user;

// El input nativo necesita YYYY-MM-DD. Además, su fecha máxima muestra de
// inmediato un año válido para una persona adulta, sin obligar a guardar el
// dato ni colocar una fecha ficticia en el perfil.
function dateInputValue(value) {
    return value ? String(value).slice(0, 10) : '';
}

const adultBirthDateLimit = (() => {
    const date = new Date();
    date.setFullYear(date.getFullYear() - 18);
    const pad = (value) => String(value).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
})();

// El teléfono ya guardado viene completo (+593...); se separa el prefijo
// conocido para precargar el formulario — mejor esfuerzo nada más, si no
// matchea ninguno el campo local queda vacío y lo escribe de nuevo (mismo
// criterio que ya usa Admin/UserProfile.vue para esto mismo).
function splitPhone(phone) {
    if (!phone) return { country_code: '+593', phone_local: '' };
    const code = props.countryCodes.find((c) => phone.startsWith(c));
    return code ? { country_code: code, phone_local: phone.slice(code.length) } : { country_code: '+593', phone_local: '' };
}

const form = useForm({
    name: user.name,
    last_name: user.last_name ?? '',
    birth_date: dateInputValue(user.birth_date),
    email: user.email,
    city_id: user.city_id,
    avatar: null,
    ...splitPhone(user.phone),
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

// Pedido explícito del usuario: una vez guardados los datos personales, no
// mostrarlos más como inputs vacíos esperando que los llenen — se ven como
// texto ya confirmado, con un botón "Editar" que recién ahí abre el
// formulario de siempre. Arranca en modo vista solo si YA hay algo cargado
// (nombre y correo siempre existen desde el registro); una cuenta a medio
// completar (ej. recién creada por Google) sigue arrancando directo en el
// formulario, como antes.
const editing = ref(!(user.name && user.email));

const cityName = computed(() => props.cities.find((city) => city.id === user.city_id)?.name ?? null);

function formattedBirthDate(value) {
    if (!value) return null;
    // Se arma a mano en vez de `new Date(value)` para no arrastrar un
    // corrimiento de zona horaria (un date-only "YYYY-MM-DD" se interpreta
    // como UTC medianoche, que en Ecuador cae al día anterior).
    const [year, month, day] = value.split('-');

    return `${day}/${month}/${year}`;
}

function startEditing() {
    editing.value = true;
}

function cancelEditing() {
    form.reset();
    form.clearErrors();
    avatarPreview.value = null;
    avatarFileName.value = '';
    avatarSizeError.value = null;
    editing.value = false;
}

function submit() {
    form.patch(route('profile.update'), {
        onSuccess: () => { editing.value = false; },
    });
}
</script>

<template>
    <section>
        <header class="flex items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-medium text-arka-text">Información del perfil</h2>
                <p class="mt-1 text-sm text-arka-text-muted">
                    {{ editing ? 'Actualice sus datos personales y de contacto.' : 'Sus datos personales y de contacto.' }}
                </p>
            </div>
            <SecondaryButton v-if="!editing" type="button" @click="startEditing">Editar</SecondaryButton>
        </header>

        <!-- Pedido explícito del usuario: una vez guardados los datos, se
             ven como texto (no como inputs vacíos esperando que los
             llenen) hasta que se toque "Editar". -->
        <dl v-if="!editing" class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2 flex items-center gap-3">
                <UserAvatar :user="user" size-class="h-16 w-16 shrink-0 text-lg" />
            </div>
            <div>
                <dt class="text-xs font-medium text-arka-text-muted">Nombre</dt>
                <dd class="mt-1 text-sm text-arka-text">{{ user.name }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-arka-text-muted">Apellido</dt>
                <dd class="mt-1 text-sm" :class="user.last_name ? 'text-arka-text' : 'text-arka-text-muted italic'">{{ user.last_name || 'Sin especificar' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-arka-text-muted">Correo electrónico</dt>
                <dd class="mt-1 truncate text-sm text-arka-text">{{ user.email }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-arka-text-muted">Teléfono</dt>
                <dd class="mt-1 text-sm text-arka-text">{{ user.phone }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-arka-text-muted">Fecha de nacimiento</dt>
                <dd class="mt-1 text-sm" :class="user.birth_date ? 'text-arka-text' : 'text-arka-text-muted italic'">{{ formattedBirthDate(user.birth_date) || 'Sin especificar' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-arka-text-muted">Ciudad donde vive</dt>
                <dd class="mt-1 text-sm" :class="cityName ? 'text-arka-text' : 'text-arka-text-muted italic'">{{ cityName || 'Sin especificar' }}</dd>
            </div>
        </dl>

        <form v-else @submit.prevent="submit" class="mt-6 space-y-6">
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

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <InputLabel for="name" value="Nombre" />

                    <TextInput
                        id="name"
                        type="text"
                        class="mt-1 block w-full"
                        v-model="form.name"
                        required
                        autofocus
                        autocomplete="given-name"
                    />

                    <InputError class="mt-2" :message="form.errors.name" />
                </div>

                <div>
                    <!-- Pedido explícito del usuario ("nombres, apellidos...")
                         — opcional: se agrega recién acá, las cuentas viejas
                         no lo tenían pedido en el registro. -->
                    <InputLabel for="last_name" value="Apellido" />

                    <TextInput
                        id="last_name"
                        type="text"
                        class="mt-1 block w-full"
                        v-model="form.last_name"
                        autocomplete="family-name"
                    />

                    <InputError class="mt-2" :message="form.errors.last_name" />
                </div>
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
                <!-- Teléfono (pedido explícito del usuario: "que tambien
                     pueda actualizar su numero de telefono... y que cuando
                     ingrese el numero le invite a escribirle al asistente
                     de whatsapp para confirmar su numero") — al guardar un
                     número distinto, Arka01 manda un código de 6 dígitos
                     por WhatsApp; la próxima pantalla que visite se lo va a
                     pedir sola (mismo mecanismo que ya usa el registro y el
                     conductor). -->
                <InputLabel for="phone_local" value="Teléfono" />
                <div class="mt-1 flex gap-2">
                    <select
                        v-model="form.country_code"
                        class="w-24 rounded-arka border-arka-text-muted/30 bg-arka-card text-arka-text text-sm"
                    >
                        <option v-for="code in countryCodes" :key="code" :value="code">{{ code }}</option>
                    </select>
                    <TextInput id="phone_local" type="text" class="flex-1" v-model="form.phone_local" placeholder="991234567" autocomplete="tel-national" />
                </div>
                <InputError class="mt-2" :message="form.errors.phone_local" />
                <p class="mt-1.5 text-xs text-arka-text-muted">
                    Si lo cambia, le mandamos un código por WhatsApp al número nuevo para confirmarlo — ábralo desde
                    ahí mismo.
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <!-- Fecha de nacimiento (pedido explícito del usuario) —
                         sin librería de calendario en el proyecto, mismo
                         patrón que ya usa Ride/Request.vue para programar
                         una carrera: input nativo type="date". -->
                    <InputLabel for="birth_date" value="Fecha de nacimiento (opcional)" />
                    <TextInput
                        id="birth_date"
                        v-model="form.birth_date"
                        type="date"
                        class="mt-1 block w-full"
                        :max="adultBirthDateLimit"
                    />
                    <p class="mt-1 text-xs text-arka-text-muted">
                        Si la registra, debe corresponder a una persona de 18 años o más.
                    </p>
                    <InputError class="mt-2" :message="form.errors.birth_date" />
                </div>

                <div>
                    <!-- País: no es un campo del formulario — la plataforma
                         opera solo en Ecuador (Arka01_Alcance_Proyectov2.md),
                         así que no tiene sentido pedirlo. Se muestra fijo
                         para que el resumen de "datos completos" tenga
                         sentido de un vistazo. -->
                    <InputLabel value="País" />
                    <p class="mt-1 flex h-[42px] items-center rounded-arka border border-arka-text-muted/20 bg-arka-base px-3 text-sm text-arka-text-muted">
                        🇪🇨 Ecuador
                    </p>
                </div>
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

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>
                <SecondaryButton type="button" @click="cancelEditing">Cancelar</SecondaryButton>

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

        <div v-if="mustVerifyEmail && user.email_verified_at === null" class="mt-6">
            <p class="text-sm text-arka-text">
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
    </section>
</template>
