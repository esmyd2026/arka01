<script setup>
import { computed, onMounted, ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import AuthBrandingPanel from '@/Components/AuthBrandingPanel.vue';
import SocialLinks from '@/Components/SocialLinks.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

// Fondo opcional configurable desde /admin/sitio (pedido explícito del
// usuario: "poder colocar la imagen de fondo también" + "quiero que en la
// móvil se vea el mismo fondo"). Vive acá (no en AuthBrandingPanel, que solo
// se muestra en escritorio) para que se vea igual en las dos pantallas —
// el panel de marca queda transparente y deja ver esta misma capa detrás.
const backgroundUrl = computed(() => usePage().props.authBackgroundUrl);

// Redes sociales en el pie (pedido explícito del usuario) — apagable para
// pantallas que ya traen su propio apartado de redes más arriba (ej.
// Survey/Show.vue: "Síguenos" después de compartir/ir al inicio), para que
// no se repitan dos veces en la misma pantalla.
defineProps({
    showSocialLinks: { type: Boolean, default: true },
    // Bug real reportado por el usuario (con captura: "tienes full espacio y
    // lo tienes todo alli agrupado"): este layout se diseñó para formularios
    // angostos (login/registro), con la tarjeta capada a `sm:max-w-md`
    // (448px) sin importar qué tan ancha fuera la pantalla. Cooperative/Show.vue
    // lo reusa para una página de contenido rico (grilla de estadísticas,
    // conductores, reseñas) que necesita mucho más ancho — antes quedaba
    // todo apretado en esos 448px en cualquier pantalla ≥640px.
    maxWidthClass: { type: String, default: 'sm:max-w-md' },
    // El panel de marca decorativo (mitad de la pantalla en escritorio) tiene
    // sentido en login/registro, no en una página de contenido que ya
    // necesita todo el ancho disponible.
    showBrandingPanel: { type: Boolean, default: true },
    // La tarjeta blanca que envuelve el slot tiene sentido para un
    // formulario chico — una página con sus propias tarjetas internas
    // (Cooperative/Show.vue) no necesita quedar OTRA vez envuelta en una
    // tarjeta más, se vería como una tarjeta dentro de otra tarjeta.
    wrapContent: { type: Boolean, default: true },
});

// Pedido explícito del usuario ("que no se note que se está cargando la
// imagen si pesa mucho"): en vez de dejar que el navegador la pinte de
// golpe en cuanto termina de bajar (un "pop" notorio con un archivo
// pesado), se precarga en JS y recién se hace visible con un fade suave una
// vez que ya está lista — mientras tanto se ve el degradado liso de
// siempre (arka-app-background), nunca un hueco vacío.
const backgroundLoaded = ref(false);
onMounted(() => {
    if (!backgroundUrl.value) return;
    const image = new Image();
    image.onload = () => { backgroundLoaded.value = true; };
    image.src = backgroundUrl.value;
});
</script>

<template>
    <Head>
        <link v-if="backgroundUrl" rel="preload" as="image" :href="backgroundUrl" fetchpriority="high" />
    </Head>

    <!-- Layout para las pantallas sin sesión (login, registro, recuperar
         contraseña): panel de marca a la izquierda en escritorio (oculto en
         móvil, donde alcanza con el logo chico arriba de la tarjeta) y el
         formulario a la derecha. -->
    <div class="relative isolate arka-app-background min-h-screen flex overflow-hidden">
        <!-- Efecto "duotono" pedido por el usuario en vez de una foto a color
             con un velo oscuro encima: la imagen va en escala de grises y el
             verde de marca se mezcla arriba con mix-blend-mode — se ve
             integrada, casi con relieve, funciona automáticamente con
             cualquier foto que suba el admin (no depende de editarla antes). -->
        <div
            v-if="backgroundUrl"
            class="pointer-events-none absolute inset-0 -z-10 isolate overflow-hidden transition-opacity duration-700 ease-out"
            :class="backgroundLoaded ? 'opacity-100' : 'opacity-0'"
        >
            <div
                class="absolute inset-0 bg-cover bg-center"
                :style="{ backgroundImage: `url('${backgroundUrl}')`, filter: 'grayscale(1) contrast(1.05) brightness(0.85)' }"
            />
            <div class="absolute inset-0 mix-blend-color" style="background: linear-gradient(160deg, #123d2c 0%, #0a1f16 55%, #071310 100%)" />
            <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(7,17,13,0.25) 0%, rgba(7,17,13,0.45) 55%, rgba(7,17,13,0.78) 100%)" />
        </div>

        <AuthBrandingPanel v-if="showBrandingPanel" />

        <div class="flex-1 flex flex-col justify-center items-center px-6 py-12">
            <div class="w-full" :class="maxWidthClass">
                <Link href="/" class="lg:hidden flex justify-center mb-6">
                    <ApplicationLogo size="h-10" />
                </Link>

                <div v-if="wrapContent" class="w-full px-6 py-4 bg-arka-card shadow-md overflow-hidden rounded-arka">
                    <slot />
                </div>
                <slot v-else />

                <!-- Pedido explícito del usuario (gap identificado antes del
                     despliegue): enlaces a Términos y Privacidad, visibles
                     antes de registrarse. Redes sociales agregadas después,
                     mismo pedido explícito que en Welcome.vue y Survey/Show.vue. -->
                <div class="mt-6 flex flex-col items-center gap-3">
                    <SocialLinks v-if="showSocialLinks" size="sm" />
                    <p class="text-center text-xs text-arka-text-muted">
                        <Link :href="route('legal.terms')" class="hover:text-arka-primary-bright">Términos</Link>
                        <span class="mx-2">·</span>
                        <Link :href="route('legal.privacy')" class="hover:text-arka-primary-bright">Privacidad</Link>
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
