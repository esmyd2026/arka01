<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import PublicProfileContent from '@/Components/PublicProfileContent.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    profileUser: { type: Object, required: true },
    profileUrl: { type: String, required: true },
    averageRating: { type: Number, required: true },
    reviewCount: { type: Number, required: true },
    reviews: { type: Object, required: true },
    // Rol(es) de esta persona en la plataforma (sección 3.1) — para mostrar
    // una marca clara de "qué es" antes de decidir invitarla o aceptarla.
    isClient: { type: Boolean, required: true },
    isDriver: { type: Boolean, required: true },
    // Pedido explícito del usuario ("mejoremos la privacidad de los
    // conductores"): true cuando el conductor apagó "Habilitar mi perfil
    // individual al público" y quien mira no es él ni un admin — el
    // backend ya viene sin vehículo/tarifa/reseñas en ese caso.
    profilePrivate: { type: Boolean, default: false },
});

// Vista previa profesional al compartir el enlace (pedido explícito del
// usuario: "que el mensaje a compartir por WhatsApp vaya el logo o perfil
// del conductor") — WhatsApp arma la tarjeta de vista previa solo, leyendo
// estas etiquetas de la propia página, no hay forma de "adjuntar" una
// imagen al texto del mensaje en sí. Para el rastreador de WhatsApp en sí
// (que nunca tiene sesión) esto no alcanza igual — ver la vista aparte
// `profile-preview.blade.php` que sirve PublicProfileController::show().
const ogDescription = `${props.isDriver ? 'Conductor' : 'Cliente'} en Arka01${props.reviewCount > 0 ? ` · ★ ${props.averageRating.toFixed(1)}` : ''} — movilidad de confianza en Ecuador.`;
const ogImage = props.profileUser.avatar_url && !props.profileUser.avatar_url.startsWith('http')
    ? window.location.origin + props.profileUser.avatar_url
    : (props.profileUser.avatar_url ?? `${window.location.origin}/icons/icon.svg`);

// Pedido explícito del usuario: el perfil público ahora es visible sin
// sesión (para quien escanea el QR o abre el link sin cuenta todavía) — acá
// se decide qué armazón usar, sin tocar AuthenticatedLayout.vue (esa
// asume una sesión iniciada en todos lados, no es seguro reutilizarla para
// un visitante anónimo).
const authUser = computed(() => usePage().props.auth?.user ?? null);
const canRequestRide = computed(() => Boolean(usePage().props.auth?.isClient));
</script>

<template>
    <Head :title="profileUser.name">
        <meta property="og:type" content="profile" />
        <meta property="og:title" :content="`${profileUser.name} — Arka01`" />
        <meta property="og:description" :content="ogDescription" />
        <meta property="og:image" :content="ogImage" />
        <meta property="og:url" :content="profileUrl" />
    </Head>

    <AuthenticatedLayout v-if="authUser">
        <template #header>
            <div class="flex items-center gap-3">
                <UserAvatar :user="profileUser" size-class="h-12 w-12 text-base" />
                <div>
                    <h2 class="font-semibold text-xl text-arka-text leading-tight">{{ profileUser.name }}</h2>
                    <!-- Usuario y código de socio (consideración agregada al alcance):
                         lo que otro usuario necesita para buscarlo y agregarlo a su
                         flota, sin depender del teléfono ni del nombre exacto. -->
                    <p class="text-sm text-arka-text-muted">
                        <span v-if="profileUser.username">@{{ profileUser.username }}</span>
                        <span v-if="profileUser.username && profileUser.member_code"> · </span>
                        <span v-if="profileUser.member_code">Código #{{ profileUser.member_code }}</span>
                    </p>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
                <PublicProfileContent
                    :profile-user="profileUser"
                    :average-rating="averageRating"
                    :review-count="reviewCount"
                    :reviews="reviews"
                    :is-client="isClient"
                    :is-driver="isDriver"
                    :can-request-ride="canRequestRide"
                    :profile-private="profilePrivate"
                />
            </div>
        </div>
    </AuthenticatedLayout>

    <!-- Visitante sin sesión (pedido explícito del usuario: quien escanea el
         QR o abre el link puede no tener cuenta todavía) — presentación
         mínima propia, sin la barra de navegación de la app, con una
         invitación a crear cuenta. -->
    <div v-else class="arka-app-background min-h-screen">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12">
            <div class="flex items-center justify-between mb-8">
                <Link href="/">
                    <ApplicationLogo size="h-10" />
                </Link>
                <Link
                    :href="route('register')"
                    class="px-4 py-2 rounded-arka bg-arka-primary text-arka-base text-sm font-semibold hover:bg-arka-primary-bright transition"
                >
                    Crear cuenta
                </Link>
            </div>

            <div class="flex items-center gap-3 mb-6">
                <UserAvatar :user="profileUser" size-class="h-12 w-12 text-base" />
                <div>
                    <h2 class="font-semibold text-xl text-arka-text leading-tight">{{ profileUser.name }}</h2>
                    <p class="text-sm text-arka-text-muted">
                        <span v-if="profileUser.username">@{{ profileUser.username }}</span>
                        <span v-if="profileUser.username && profileUser.member_code"> · </span>
                        <span v-if="profileUser.member_code">Código #{{ profileUser.member_code }}</span>
                    </p>
                </div>
            </div>

            <PublicProfileContent
                :profile-user="profileUser"
                :average-rating="averageRating"
                :review-count="reviewCount"
                :reviews="reviews"
                :is-client="isClient"
                :is-driver="isDriver"
                :can-request-ride="false"
                :profile-private="profilePrivate"
            />

            <p class="mt-8 text-center text-sm text-arka-text-muted">
                <Link :href="route('login')" class="text-arka-primary hover:text-arka-primary-bright">Iniciá sesión</Link>
                o
                <Link :href="route('register')" class="text-arka-primary hover:text-arka-primary-bright">creá una cuenta</Link>
                para armar tu flota de confianza en Arka01.
            </p>
        </div>
    </div>
</template>
