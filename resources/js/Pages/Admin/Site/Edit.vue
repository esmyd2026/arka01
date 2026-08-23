<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import BackgroundImageField from './Partials/BackgroundImageField.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    settings: { type: Object, required: true },
});
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
            </div>
        </div>
    </AdminLayout>
</template>
