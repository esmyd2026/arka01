<script setup>
import { computed, ref } from 'vue';
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
    trustIndex: { type: Object, default: null },
    mutualPeople: { type: Array, default: () => [] },
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
const ogDescription = `${props.isDriver ? 'Conductor' : 'Cliente'} en Arka01${props.reviewCount > 0 ? ` · ★ ${props.averageRating.toFixed(1)}` : ''}${props.trustIndex ? ` · Índice de confianza ${props.trustIndex.score}/100` : ''} — únase y hagamos que la movilidad sea más segura en Ecuador.`;
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
const shareStatus = ref('');

const shareProfile = async () => {
    const text = `${props.profileUser.name} en Arka01${props.trustIndex ? ` · Índice de confianza ${props.trustIndex.score}/100` : ''}`;

    try {
        if (navigator.share) {
            await navigator.share({ title: `${props.profileUser.name} — Arka01`, text, url: props.profileUrl });
            shareStatus.value = 'Perfil compartido';
        } else if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(props.profileUrl);
            shareStatus.value = 'Enlace copiado';
        } else {
            shareStatus.value = 'Copia el enlace desde la barra del navegador';
        }
    } catch (error) {
        if (error?.name !== 'AbortError') {
            shareStatus.value = 'No se pudo compartir. Inténtalo nuevamente.';
        }
    }

    if (shareStatus.value) {
        window.setTimeout(() => { shareStatus.value = ''; }, 3500);
    }
};
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
                <div class="mb-4 flex flex-wrap items-center justify-end gap-3">
                    <span v-if="shareStatus" class="text-xs text-arka-text-muted" role="status">{{ shareStatus }}</span>
                    <button
                        type="button"
                        class="inline-flex min-h-10 items-center gap-2 rounded-xl border border-arka-primary/35 bg-arka-primary/10 px-4 py-2 text-sm font-semibold text-arka-primary-bright transition hover:bg-arka-primary/20"
                        @click="shareProfile"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <circle cx="18" cy="5" r="3" />
                            <circle cx="6" cy="12" r="3" />
                            <circle cx="18" cy="19" r="3" />
                            <path d="m8.6 10.5 6.8-4M8.6 13.5l6.8 4" />
                        </svg>
                        Compartir perfil e índice
                    </button>
                </div>
                <PublicProfileContent
                    :profile-user="profileUser"
                    :average-rating="averageRating"
                    :review-count="reviewCount"
                    :reviews="reviews"
                    :is-client="isClient"
                    :is-driver="isDriver"
                    :can-request-ride="canRequestRide"
                    :profile-private="profilePrivate"
                    :trust-index="trustIndex"
                />

                <section
                    v-if="mutualPeople.length"
                    class="mt-4 rounded-2xl border border-arka-primary/15 bg-arka-card p-4 shadow sm:p-5"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-arka-text">Personas que tienen en común</h3>
                            <p class="mt-1 text-xs leading-relaxed text-arka-text-muted">
                                Conexiones aceptadas por ambos. No mostramos información de contacto.
                            </p>
                        </div>
                        <span class="rounded-full bg-arka-primary/15 px-2.5 py-1 text-xs font-bold text-arka-primary-bright">
                            {{ trustIndex?.mutual_people ?? mutualPeople.length }}
                        </span>
                    </div>

                    <div class="mt-4 grid gap-2 sm:grid-cols-2">
                        <Link
                            v-for="person in mutualPeople"
                            :key="person.public_id"
                            :href="route('profiles.show', person.public_id)"
                            class="flex min-w-0 items-center gap-3 rounded-xl border border-white/5 bg-arka-base/45 p-2.5 transition hover:border-arka-primary/30"
                        >
                            <UserAvatar :user="person" size-class="h-10 w-10 shrink-0 text-xs" />
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-semibold text-arka-text">{{ person.name }}</span>
                                <span v-if="person.username" class="block truncate text-xs text-arka-text-muted">@{{ person.username }}</span>
                            </span>
                        </Link>
                    </div>
                </section>
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
                            :href="route('register', { ref: profileUser.public_id })"
                            class="inline-flex min-h-11 items-center justify-center rounded-xl bg-arka-primary px-4 py-2.5 text-sm font-bold text-arka-base transition hover:bg-arka-primary-bright"
                        >
                            Unirme a Arka01
                        </Link>
                        <Link
                            :href="route('login', { ref: profileUser.public_id })"
                            class="inline-flex min-h-11 items-center justify-center rounded-xl border border-arka-primary/40 bg-arka-primary/10 px-4 py-2.5 text-sm font-semibold text-arka-primary-bright transition hover:bg-arka-primary/20"
                        >
                            Iniciar sesión
                        </Link>
                    </div>

                    <p class="mt-3 text-center text-xs leading-relaxed text-arka-text-muted">
                        Al continuar desde este enlace, <span class="font-medium text-arka-text">{{ profileUser.name }}</span>
                        quedará registrado como quien te recomendó.
                    </p>

                    <div class="mt-3 flex flex-col items-center gap-2">
                        <button
                            type="button"
                            class="inline-flex min-h-10 items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold text-arka-primary-bright transition hover:bg-arka-primary/10"
                            @click="shareProfile"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <circle cx="18" cy="5" r="3" />
                                <circle cx="6" cy="12" r="3" />
                                <circle cx="18" cy="19" r="3" />
                                <path d="m8.6 10.5 6.8-4M8.6 13.5l6.8 4" />
                            </svg>
                            Compartir perfil e índice
                        </button>
                        <span v-if="shareStatus" class="text-xs text-arka-text-muted" role="status">{{ shareStatus }}</span>
                    </div>

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
                            :trust-index="trustIndex"
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
