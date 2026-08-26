<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import BackgroundImageField from './Partials/BackgroundImageField.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    settings: { type: Object, required: true },
});

// Pedido explícito del usuario ("le invite a seguir las redes") — usados al
// agradecer una calificación de 5 estrellas por WhatsApp (ver
// WhatsAppRatingHandler). Ninguno obligatorio.
const socialForm = useForm({
    facebook_url: props.settings.facebook_url ?? '',
    instagram_url: props.settings.instagram_url ?? '',
    tiktok_url: props.settings.tiktok_url ?? '',
});

function saveSocialLinks() {
    socialForm.post(route('admin.site.update'), { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · Sitio" />

    <AdminLayout title="Sitio">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Pedido explícito del usuario ("por lo menos haz que la
                     pueda colocar desde la parte de configuración del
                     admin"): la imagen de fondo del hero de Welcome.vue,
                     subida acá en vez de depender de copiarla a mano a
                     public/img/ — mismo patrón de subida que
                     Driver/Profile.vue (forceFormData + input file). -->
                <BackgroundImageField
                    field-key="hero_background"
                    title="Imagen de fondo del hero (Inicio público)"
                    help="Se ve detrás del título y la tarjeta de &quot;¿A dónde vamos?&quot; en la portada de Arka01 (arka01.com), antes de iniciar sesión. Recomendado: imagen oscura y ancha (horizontal), para que el texto se siga leyendo bien encima."
                    :current-url="settings.hero_background_url"
                    empty-message="Todavía no hay ninguna imagen — el hero se ve con el fondo oscuro liso de siempre."
                />

                <!-- Pedido explícito del usuario ("podemos mejorar el diseño
                     del login también poder colocar la imagen de fondo
                     también") — panel de marca de AuthBrandingPanel.vue,
                     visible en login/registro/recuperar contraseña. -->
                <BackgroundImageField
                    field-key="auth_background"
                    title="Imagen de fondo del panel de inicio de sesión"
                    help="Se ve detrás del texto de marca a la izquierda del formulario en Iniciar sesión, Crear cuenta y Recuperar contraseña (solo en pantallas grandes, en el celular no se muestra ese panel). Recomendado: imagen oscura y vertical."
                    :current-url="settings.auth_background_url"
                    empty-message="Todavía no hay ninguna imagen — el panel se ve con el degradado verde oscuro de siempre."
                />

                <!-- Pedido explícito del usuario ("le invite a seguir las
                     redes") — al agradecer una calificación de 5 estrellas
                     por WhatsApp, se ofrecen las redes que estén completas
                     acá. Ninguna es obligatoria. -->
                <form @submit.prevent="saveSocialLinks" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-4">
                    <div>
                        <h3 class="text-lg font-medium text-arka-text">Redes sociales</h3>
                        <p class="mt-1 text-sm text-arka-text-muted">
                            Se invita a seguirlas por WhatsApp cuando un cliente califica con 5 estrellas. Dejar en
                            blanco la que no aplique.
                        </p>
                    </div>

                    <div>
                        <InputLabel for="facebook_url" value="Facebook" />
                        <TextInput id="facebook_url" v-model="socialForm.facebook_url" type="url" class="mt-1 w-full" placeholder="https://facebook.com/arka01" />
                        <InputError class="mt-1" :message="socialForm.errors.facebook_url" />
                    </div>
                    <div>
                        <InputLabel for="instagram_url" value="Instagram" />
                        <TextInput id="instagram_url" v-model="socialForm.instagram_url" type="url" class="mt-1 w-full" placeholder="https://instagram.com/arka01" />
                        <InputError class="mt-1" :message="socialForm.errors.instagram_url" />
                    </div>
                    <div>
                        <InputLabel for="tiktok_url" value="TikTok" />
                        <TextInput id="tiktok_url" v-model="socialForm.tiktok_url" type="url" class="mt-1 w-full" placeholder="https://tiktok.com/@arka01" />
                        <InputError class="mt-1" :message="socialForm.errors.tiktok_url" />
                    </div>

                    <PrimaryButton :disabled="socialForm.processing">Guardar</PrimaryButton>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
