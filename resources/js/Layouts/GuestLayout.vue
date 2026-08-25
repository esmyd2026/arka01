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

        <AuthBrandingPanel />

        <div class="flex-1 flex flex-col justify-center items-center px-6 py-12">
            <div class="w-full sm:max-w-md">
                <Link href="/" class="lg:hidden flex justify-center mb-6">
                    <ApplicationLogo size="h-10" />
                </Link>

                <div class="w-full px-6 py-4 bg-arka-card shadow-md overflow-hidden rounded-arka">
                    <slot />
                </div>

                <!-- Pedido explícito del usuario (gap identificado antes del
                     despliegue): enlaces a Términos y Privacidad, visibles
                     antes de registrarse. Redes sociales agregadas después,
                     mismo pedido explícito que en Welcome.vue y Survey/Show.vue. -->
                <div class="mt-6 flex flex-col items-center gap-3">
                    <SocialLinks size="sm" />
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
