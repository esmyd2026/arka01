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
const props = defineProps({
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
    // El registro usa una composición editorial propia en escritorio: escena
    // completa a la izquierda y asistente de alta a la derecha. Las demás
    // pantallas de sesión conservan el layout existente.
    registrationShowcase: { type: Boolean, default: false },
    // Permite reutilizar la misma dirección visual en el acceso sin mostrar
    // textos de creación de cuenta a quien ya forma parte de Arka01.
    showcaseVariant: { type: String, default: 'register' },
});

const effectiveBackgroundUrl = computed(() => (
    props.registrationShowcase
        ? '/img/home/imagen%20de%20fondo%20debes%20difuminar.png'
        : backgroundUrl.value
));

// Pedido explícito del usuario ("que no se note que se está cargando la
// imagen si pesa mucho"): en vez de dejar que el navegador la pinte de
// golpe en cuanto termina de bajar (un "pop" notorio con un archivo
// pesado), se precarga en JS y recién se hace visible con un fade suave una
// vez que ya está lista — mientras tanto se ve el degradado liso de
// siempre (arka-app-background), nunca un hueco vacío.
const backgroundLoaded = ref(false);
onMounted(() => {
    if (!effectiveBackgroundUrl.value) return;
    const image = new Image();
    image.onload = () => { backgroundLoaded.value = true; };
    image.src = effectiveBackgroundUrl.value;
});
</script>

<template>
    <Head>
        <link v-if="effectiveBackgroundUrl" rel="preload" as="image" :href="effectiveBackgroundUrl" fetchpriority="high" />
    </Head>

    <!-- Layout para las pantallas sin sesión (login, registro, recuperar
         contraseña): panel de marca a la izquierda en escritorio (oculto en
         móvil, donde alcanza con el logo chico arriba de la tarjeta) y el
         formulario a la derecha. -->
    <div
        class="relative isolate arka-app-background min-h-screen flex overflow-hidden"
        :class="{ 'auth-layout--register': registrationShowcase }"
    >
        <!-- La fotografía conserva sus verdes y detalles. Una sola capa
             adaptable protege la lectura sin convertirla en una mancha gris. -->
        <div
            v-if="effectiveBackgroundUrl"
            class="pointer-events-none absolute inset-0 -z-10 isolate overflow-hidden transition-opacity duration-700 ease-out"
            :class="backgroundLoaded ? 'opacity-100' : 'opacity-0'"
        >
            <div
                class="auth-background-photo absolute inset-0 bg-cover bg-center"
                :class="{ 'auth-registration-photo': registrationShowcase }"
                :style="{ backgroundImage: `url('${effectiveBackgroundUrl}')` }"
            />
            <div class="auth-background-veil absolute inset-0" :class="{ 'auth-registration-veil': registrationShowcase }" />
        </div>

        <AuthBrandingPanel v-if="showBrandingPanel && !registrationShowcase" />

        <aside v-if="registrationShowcase" class="auth-registration-story hidden lg:flex" aria-label="Presentación de Arka01">
            <Link href="/" class="auth-registration-logo">
                <ApplicationLogo size="h-14 xl:h-16" />
            </Link>

            <div class="auth-registration-copy">
                <span aria-hidden="true"></span>
                <template v-if="showcaseVariant === 'login'">
                    <h1>Vuelve a moverte<br><strong>con confianza.</strong></h1>
                    <p>Tu círculo, tus conductores y tus viajes<br>siguen cerca cuando los necesitas.</p>
                </template>
                <template v-else>
                    <h1>Empieza a construir<br><strong>tu círculo.</strong></h1>
                    <p>Encuentra a tus conductores, agrégalos a tu flota<br>y tenlos cerca cuando los necesites.</p>
                </template>

                <ul>
                    <li>
                        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="8" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path stroke-linecap="round" d="M2.5 19a5.5 5.5 0 0 1 11 0M14 14.5A4.5 4.5 0 0 1 21.5 19"/></svg></span>
                        <p><strong>Personas reales</strong><br>en las que confías</p>
                    </li>
                    <li>
                        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="m12 3 7 3v5c0 4.5-2.8 7.8-7 10-4.2-2.2-7-5.5-7-10V6l7-3Z"/><path stroke-linecap="round" d="m9 12 2 2 4-4"/></svg></span>
                        <p><strong>Viajes más tranquilos</strong><br>y organizados</p>
                    </li>
                    <li>
                        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="M8.5 10.5C6 8 6.4 4 10 3.2c2.9-.6 5.2 2 4.6 4.7-.6 2.4-4.1 5.6-4.1 5.6s-.8-.7-2-2Z"/><circle cx="10.5" cy="7.4" r="1.2"/><path stroke-linecap="round" d="m4 20 5-4 4 3 7-6"/></svg></span>
                        <p><strong>Tu ciudad,</strong><br>más conectada</p>
                    </li>
                </ul>

                <p v-if="showcaseVariant === 'login'" class="auth-registration-script">Qué bueno<br><span>tenerte de vuelta</span></p>
                <p v-else class="auth-registration-script">Juntos<br><span>llegamos más lejos</span></p>
            </div>

            <div class="auth-registration-badge auth-registration-badge--driver">
                <span>✓</span><p>Conductor<br>de confianza</p>
            </div>
            <div class="auth-registration-badge auth-registration-badge--ride">
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l2.5-6.5A2 2 0 0 1 8.35 8.2h7.3a2 2 0 0 1 1.85 1.3L20 16M5 16h14"/></svg></span><p>Tu próximo viaje<br>más cerca</p>
            </div>

            <p class="auth-registration-copyright">© 2026 Arka01. Movilidad que une.</p>
        </aside>

        <div
            class="flex-1 flex flex-col justify-center items-center px-6 py-12"
            :class="{ 'auth-register-form-pane': registrationShowcase }"
        >
            <div class="w-full" :class="[maxWidthClass, { 'auth-register-content': registrationShowcase }]">
                <Link href="/" class="lg:hidden flex justify-center mb-6">
                    <img src="/img/logo-arka01.png" alt="Arka01" class="h-11 w-auto object-contain drop-shadow-[0_4px_12px_rgba(0,0,0,0.28)]" />
                </Link>

                <div
                    v-if="wrapContent"
                    class="w-full overflow-hidden rounded-2xl bg-arka-card px-6 py-5 shadow-none"
                    :class="{ 'auth-register-card': registrationShowcase }"
                >
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

@media (min-width: 1024px) {
    .auth-layout--register {
        height: 100svh;
        min-height: 100svh;
        background: #eff9f5;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        -webkit-font-smoothing: antialiased;
        text-rendering: optimizeLegibility;
    }

    .auth-registration-photo {
        background-color: #eef9f5;
        /* El encuadre deja respirar la columna editorial y lleva a la
           protagonista hacia la derecha, como en el prototipo aprobado. */
        background-position: clamp(7rem, 11vw, 13rem) center;
        background-size: auto 100%;
        background-repeat: no-repeat;
        filter: saturate(.98) contrast(1.01) brightness(1.035);
        transform: none;
    }

    .auth-registration-veil {
        background:
            linear-gradient(90deg, rgba(255,255,255,.96) 0%, rgba(255,255,255,.88) 11%, rgba(255,255,255,.52) 18%, rgba(255,255,255,.12) 23%, transparent 28%);
    }

    .auth-registration-story {
        position: relative;
        width: 64%;
        height: 100svh;
        min-height: 0;
        flex: 0 0 64%;
        flex-direction: column;
        padding: clamp(1.25rem, 3.2vh, 2.25rem) clamp(2rem, 4.6vw, 4.5rem);
        color: #0b2b30;
    }

    .auth-registration-logo {
        display: inline-flex;
        width: fit-content;
        filter: drop-shadow(0 2px 7px rgba(255, 255, 255, 0.82));
    }

    .auth-registration-logo :deep(.text-arka-text) {
        color: #09231f !important;
    }

    .auth-registration-copy {
        width: min(31rem, 46%);
        margin-top: clamp(1rem, 2.2vh, 1.8rem);
    }

    .auth-registration-copy > span {
        display: block;
        width: 3.25rem;
        height: 2px;
        margin-bottom: 1rem;
        border-radius: 999px;
        background: linear-gradient(90deg, #10a974 60%, rgba(16,169,116,.15) 60%);
    }

    .auth-registration-copy h1 {
        color: #081812;
        font-size: clamp(2.15rem, 2.65vw, 3.15rem);
        font-weight: 750;
        line-height: .99;
        letter-spacing: -.035em;
        text-shadow: 0 1px 8px rgba(255,255,255,.34);
    }

    .auth-registration-copy h1 strong {
        color: #0b9568;
        font-weight: inherit;
    }

    .auth-registration-copy > p:not(.auth-registration-script) {
        margin-top: .85rem;
        color: #40554e;
        font-size: clamp(.85rem, 1vw, 1.02rem);
        font-weight: 500;
        line-height: 1.55;
    }

    .auth-registration-copy ul {
        display: grid;
        gap: .7rem;
        margin-top: clamp(1rem, 2.2vh, 1.7rem);
    }

    .auth-registration-copy li {
        display: flex;
        align-items: center;
        gap: .85rem;
        color: #214247;
        font-size: .83rem;
        font-weight: 500;
        line-height: 1.12rem;
    }

    .auth-registration-copy li strong {
        color: #102d25;
        font-weight: 700;
    }

    .auth-registration-copy li > span {
        display: flex;
        width: 2.8rem;
        height: 2.8rem;
        flex: 0 0 auto;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: rgba(218,248,236,.88);
        color: #078e63;
    }

    .auth-registration-copy li svg {
        width: 1.5rem;
        height: 1.5rem;
    }

    .auth-registration-script {
        width: 11rem;
        margin-top: clamp(1rem, 2vh, 1.5rem);
        color: #0b8e64;
        font-family: "Segoe Print", "Bradley Hand", "Snell Roundhand", cursive;
        font-size: 1.25rem;
        font-style: italic;
        font-weight: 600;
        line-height: 1.35;
        text-align: center;
        transform: rotate(-5deg);
    }

    .auth-registration-script span {
        position: relative;
    }

    .auth-registration-script span::after {
        position: absolute;
        right: -.4rem;
        bottom: -.55rem;
        left: -.7rem;
        height: 2px;
        border-radius: 999px;
        background: #0b9568;
        content: '';
        transform: rotate(-4deg);
    }

    .auth-registration-copyright {
        position: absolute;
        bottom: 1rem;
        left: clamp(2rem, 4.6vw, 4.5rem);
        color: #6b8586;
        font-size: .68rem;
    }

    .auth-registration-badge {
        position: absolute;
        display: flex;
        align-items: center;
        gap: .5rem;
        padding: .45rem .75rem;
        border: 1px solid rgba(172,199,189,.44);
        border-radius: 999px;
        background: rgba(255,255,255,.91);
        color: #294943;
        font-size: .58rem;
        font-weight: 650;
        line-height: .72rem;
        box-shadow: 0 8px 22px rgba(24,83,62,.12);
        -webkit-backdrop-filter: blur(8px);
        backdrop-filter: blur(8px);
    }

    .auth-registration-badge > span {
        display: flex;
        width: 1.25rem;
        height: 1.25rem;
        flex: 0 0 auto;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: #0a9568;
        color: white;
        font-size: .72rem;
    }

    .auth-registration-badge svg {
        width: .78rem;
        height: .78rem;
    }

    .auth-registration-badge--driver {
        top: 22%;
        right: 7.5%;
    }

    .auth-registration-badge--ride {
        right: 10%;
        bottom: 18%;
    }

    .auth-register-form-pane {
        position: relative;
        z-index: 2;
        width: 36%;
        height: 100svh;
        min-height: 0;
        flex: 0 0 36%;
        padding: 1.4rem clamp(1.4rem, 3vw, 3.6rem);
        background: linear-gradient(90deg, rgba(239,249,245,.48), rgba(239,249,245,.92) 20%, #eff9f5 100%);
    }

    .auth-register-content {
        max-width: 29.5rem !important;
    }

    .auth-register-card {
        max-height: none;
        overflow-x: hidden;
        overflow-y: visible;
        padding: 1.55rem 1.8rem 1.65rem;
        border: 1px solid rgba(175,199,190,.52);
        border-radius: 1rem;
        background: rgba(255,255,255,.965);
        box-shadow: 0 18px 55px rgba(20,88,65,.09) !important;
        scrollbar-width: thin;
    }

    .auth-register-form-pane .auth-footer {
        margin-top: .65rem;
        padding-block: .35rem;
        color: #6b7d78;
        text-shadow: none;
    }

    .auth-register-form-pane .auth-footer :deep(.text-arka-text-muted) {
        color: #6b7d78;
    }
}

/* Portátiles y ventanas bajas: conserva toda la experiencia en un solo
   lienzo, sin obligar a desplazar la página ni comprimir la tipografía. */
@media (min-width: 1024px) and (max-height: 720px) {
    .auth-registration-story {
        padding-top: .8rem;
        padding-bottom: .8rem;
    }

    .auth-registration-logo :deep(.h-14) {
        height: 2.8rem;
    }

    .auth-registration-copy {
        margin-top: 1.1rem;
    }

    .auth-registration-copy > span {
        margin-bottom: .55rem;
    }

    .auth-register-content {
        width: 116.28%;
        max-width: 34.3rem !important;
        transform: scale(.86);
        transform-origin: center center;
    }

    .auth-registration-copy h1 {
        font-size: clamp(1.75rem, 2.25vw, 2.35rem);
    }

    .auth-registration-copy > p:not(.auth-registration-script) {
        margin-top: .55rem;
        font-size: .8rem;
        line-height: 1.4;
    }

    .auth-registration-copy ul {
        gap: .4rem;
        margin-top: .75rem;
    }

    .auth-registration-copy li > span {
        width: 2.35rem;
        height: 2.35rem;
    }

    .auth-registration-copy li svg {
        width: 1.25rem;
        height: 1.25rem;
    }

    .auth-registration-script {
        margin-top: .65rem;
        font-size: 1.05rem;
    }

    .auth-register-form-pane {
        padding-top: .8rem;
        padding-bottom: .8rem;
    }

    .auth-register-card {
        max-height: calc(100svh - 4.2rem);
        padding: 1rem 1.45rem 1.05rem;
    }

    .auth-register-form-pane .auth-footer {
        margin-top: .25rem;
        padding-block: .15rem;
    }
}

@media (min-width: 1024px) and (max-height: 620px) {
    .auth-register-content {
        width: 123.46%;
        max-width: 36.42rem !important;
        transform: scale(.81);
    }
}

@media (max-width: 1023px) {
    .auth-layout--register .auth-registration-photo {
        background-position: 29% center;
        filter: saturate(.88) brightness(.62);
    }

    .auth-layout--register .auth-registration-veil {
        background: linear-gradient(rgba(3,25,18,.48), rgba(3,25,18,.64));
    }
}
</style>
