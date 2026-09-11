<script setup>
import { computed, onMounted, ref } from 'vue';
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
        <!-- La fotografía conserva sus verdes y detalles. Una sola capa
             adaptable protege la lectura sin convertirla en una mancha gris. -->
        <div
            v-if="backgroundUrl"
            class="pointer-events-none absolute inset-0 -z-10 isolate overflow-hidden transition-opacity duration-700 ease-out"
            :class="backgroundLoaded ? 'opacity-100' : 'opacity-0'"
        >
            <div
                class="auth-background-photo absolute inset-0 bg-cover bg-center"
                :style="{ backgroundImage: `url('${backgroundUrl}')` }"
            />
            <div class="auth-background-veil absolute inset-0" />
        </div>

        <AuthBrandingPanel v-if="showBrandingPanel" />

        <div class="flex-1 flex flex-col justify-center items-center px-6 py-12">
            <div class="w-full" :class="maxWidthClass">
                <Link href="/" class="lg:hidden flex justify-center mb-6">
                    <img src="/img/logo-arka01.png" alt="Arka01" class="h-11 w-auto object-contain drop-shadow-[0_4px_12px_rgba(0,0,0,0.28)]" />
                </Link>

                <div v-if="wrapContent" class="w-full overflow-hidden rounded-2xl bg-arka-card px-6 py-5 shadow-none">
                    <slot />
                </div>
                <slot v-else />

                <!-- Pedido explícito del usuario (gap identificado antes del
                     despliegue): enlaces a Términos y Privacidad, visibles
                     antes de registrarse. Redes sociales agregadas después,
                     mismo pedido explícito que en Welcome.vue y Survey/Show.vue. -->
                <div class="auth-footer mt-6 flex flex-col items-center gap-3 px-4 py-3">
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

<style scoped>
.auth-background-photo {
    filter: saturate(1.08) contrast(1.06) brightness(.88);
    transform: scale(1.015);
}

.auth-background-veil {
    background:
        linear-gradient(145deg, rgba(3, 14, 9, .15) 0%, rgba(3, 14, 9, .08) 48%, rgba(3, 14, 9, .18) 100%);
}

.auth-footer {
    color: rgba(255, 255, 255, .82);
    text-shadow: 0 1px 4px rgba(0, 0, 0, .72);
}

.auth-footer :deep(.text-arka-text-muted) {
    color: rgba(255, 255, 255, .82);
}

:global(html.dark) .auth-background-photo {
    filter: saturate(1.12) contrast(1.08) brightness(.80);
}

:global(html.dark) .auth-background-veil {
    background: linear-gradient(145deg, rgba(3, 10, 7, .18) 0%, rgba(3, 10, 7, .10) 48%, rgba(3, 10, 7, .24) 100%);
}
</style>
