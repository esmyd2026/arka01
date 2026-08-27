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
// del conductor", y luego "que llegue un card... con llamada a la accion")
// — WhatsApp arma la tarjeta de vista previa solo, leyendo estas etiquetas
// de la propia página, no hay forma de "adjuntar" una imagen al texto del
// mensaje en sí. Para el rastreador de WhatsApp en sí (que nunca tiene
// sesión) esto no alcanza igual — ver la vista aparte
// `profile-preview.blade.php` que sirve PublicProfileController::show(),
// con la misma copia.
const ogDescription = `${props.isDriver ? 'Conductor' : 'Cliente'} en Arka01${props.reviewCount > 0 ? ` · ★ ${props.averageRating.toFixed(1)}` : ''} — únase y hagamos que la movilidad sea más segura en Ecuador.`;
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
    <div v-else class="arka-app-background min-h-screen px-4 py-8 sm:py-12">
        <div class="mx-auto w-full max-w-lg">
            <Link href="/" class="mx-auto block w-fit" aria-label="Volver al inicio de Arka01">
                <ApplicationLogo size="h-10" />
            </Link>

            <main class="mt-6 overflow-hidden rounded-2xl border border-arka-text-muted/10 bg-arka-card shadow-2xl shadow-black/20">
                <div class="h-1.5 bg-gradient-to-r from-arka-primary to-arka-lime"></div>
                <div class="p-5 sm:p-7">
                    <div class="text-center">
                        <p class="text-xs font-medium uppercase tracking-[0.18em] text-arka-primary-bright">
                            {{ profileUser.name }} te recomienda Arka01
                        </p>

                        <UserAvatar
                            :user="profileUser"
                            size-class="mx-auto mt-4 h-24 w-24 text-2xl ring-4 ring-arka-primary/15"
                        />

                        <h1 class="mt-4 text-2xl font-bold leading-tight text-arka-text">{{ profileUser.name }}</h1>
                        <p class="mt-1 text-sm text-arka-text-muted">
                            <span v-if="profileUser.username">@{{ profileUser.username }}</span>
                            <span v-if="profileUser.username && profileUser.member_code"> · </span>
                            <span v-if="profileUser.member_code">Socio #{{ profileUser.member_code }}</span>
                        </p>

                        <div class="mt-3 flex flex-wrap items-center justify-center gap-2 text-sm">
                            <span v-if="isClient" class="rounded-full bg-arka-primary/15 px-2.5 py-1 text-xs font-semibold text-arka-primary-bright">
                                Cliente
                            </span>
                            <span v-if="isDriver" class="rounded-full bg-arka-primary/15 px-2.5 py-1 text-xs font-semibold text-arka-primary-bright">
                                Conductor
                            </span>
                            <span v-if="reviewCount > 0" class="inline-flex items-center gap-1.5 rounded-full bg-arka-lime/10 px-2.5 py-1 text-xs">
                                <span class="text-arka-lime" aria-hidden="true">★</span>
                                <span class="font-semibold text-arka-text">{{ averageRating.toFixed(1) }}</span>
                                <span class="text-arka-text-muted">
                                    ({{ reviewCount }} calificación{{ reviewCount === 1 ? '' : 'es' }})
                                </span>
                            </span>
                        </div>

                        <p class="mx-auto mt-4 max-w-sm text-sm leading-relaxed text-arka-text-muted">
                            Conoce su perfil y únete a una comunidad de movilidad basada en personas de confianza.
                        </p>
                    </div>

                    <div class="mt-5 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <Link
                            :href="route('register', { ref: profileUser.id })"
                            class="inline-flex min-h-11 items-center justify-center rounded-xl bg-arka-primary px-4 py-2.5 text-sm font-bold text-arka-base transition hover:bg-arka-primary-bright"
                        >
                            Unirme a Arka01
                        </Link>
                        <Link
                            :href="route('login', { ref: profileUser.id })"
                            class="inline-flex min-h-11 items-center justify-center rounded-xl border border-arka-primary/40 bg-arka-primary/10 px-4 py-2.5 text-sm font-semibold text-arka-primary-bright transition hover:bg-arka-primary/20"
                        >
                            Iniciar sesión
                        </Link>
                    </div>

                    <p class="mt-3 text-center text-xs leading-relaxed text-arka-text-muted">
                        Al continuar desde este enlace, <span class="font-medium text-arka-text">{{ profileUser.name }}</span>
                        quedará registrado como quien te recomendó.
                    </p>

                    <div class="mt-6">
                        <PublicProfileContent
                            :profile-user="profileUser"
                            :average-rating="averageRating"
                            :review-count="reviewCount"
                            :reviews="reviews"
                            :is-client="isClient"
                            :is-driver="isDriver"
                            :can-request-ride="false"
                            :profile-private="profilePrivate"
                            :show-summary-badges="false"
                            :show-summary-rating="false"
                            embedded
                        />
                    </div>
                </div>
            </main>

            <Link href="/" class="mx-auto mt-5 flex w-fit items-center gap-1.5 text-xs text-arka-text-muted transition hover:text-arka-primary-bright">
                <span aria-hidden="true">⌂</span>
                Volver al inicio
            </Link>
        </div>
    </div>
</template>
