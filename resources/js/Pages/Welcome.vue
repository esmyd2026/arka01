<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Modal from '@/Components/Modal.vue';
import SocialLinks from '@/Components/SocialLinks.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import AddressAutocomplete from '@/Components/AddressAutocomplete.vue';

// Bug real reportado por el usuario (con captura): el <select> nativo del
// "Tipo" se veía blanco, con el tema del sistema operativo en vez del oscuro
// de la app — el navegador pinta el panel desplegable de un <select> por su
// cuenta, no hay CSS que lo alcance. Mismo componente que ya resuelve esto
// en el resto de la app (ej. ciudad/sector al pedir una carrera).
const FEEDBACK_TYPES = [
    { value: 'sugerencia', label: 'Sugerencia' },
    { value: 'problema', label: 'Problema' },
    { value: 'nueva_idea', label: 'Nueva idea' },
    { value: 'otro', label: 'Otro' },
];

const props = defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
    guestCooperatives: { type: Array, default: () => [] },
    // Imagen de fondo del hero (pedido explícito del usuario: configurable
    // desde /admin/sitio, ver Admin\SiteSettingController) — null hasta que
    // un admin suba una, el hero conserva el fondo claro de la aplicación.
    heroBackgroundUrl: { type: String, default: null },
    ctaInteractionToken: { type: String, default: '' },
});

const authUser = usePage().props.auth?.user ?? null;

// Pedido explícito del usuario ("que no se vea tan brusco por temas de
// carga de la imagen... lo hiciste en el login y me gustó"): mismo criterio
// que GuestLayout.vue — la foto se precarga en JS y recién se hace visible
// con un fundido suave una vez lista, en vez de aparecer de golpe cuando
// pesa mucho. Mientras tanto se ve el fondo claro de la aplicación.
const heroBackgroundLoaded = ref(false);
// Pedido explícito del usuario ("las imágenes... son un poco pesadas, demoran
// en cargar y se nota eso que va apareciendo poco a poco"): las fotos fijas
// de móvil/escritorio del hero se agregaron sin el mismo fundido que ya tiene
// login/registro (heroBackgroundLoaded de arriba quedó huérfano — era para la
// vieja imagen configurable desde el admin, no para estas dos). Mismo patrón:
// se precargan en JS y solo se muestran con opacidad 1 cuando terminan de
// bajar, evitando el efecto de "se va pintando de a poco" de una imagen
// pesada renderizándose directo desde el navegador.
const mobileHeroLoaded = ref(false);
const desktopHeroLoaded = ref(false);
const showingWelcomeCta = ref(false);
const ctaHoneypot = ref('');
let welcomeCtaTimer = null;

function visitorToken() {
    const storageKey = 'arka01_visitor_token';
    let token = window.localStorage.getItem(storageKey);
    if (!token) {
        token = window.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random()}`;
        window.localStorage.setItem(storageKey, token);
    }
    return token;
}

async function recordCtaEvent(event, target = 'general') {
    if (!props.ctaInteractionToken) return;

    try {
        await window.axios.post(route('landing-cta.store'), {
            event,
            target,
            visitor_token: visitorToken(),
            interaction_token: props.ctaInteractionToken,
            website: ctaHoneypot.value,
            automated: Boolean(navigator.webdriver),
            path: window.location.pathname,
            referrer: document.referrer || null,
        });
    } catch {
        // La analítica nunca debe impedir el registro ni molestar al visitante.
    }
}

function closeWelcomeCta() {
    recordCtaEvent('dismiss', 'general');
    showingWelcomeCta.value = false;
}

async function followWelcomeCta() {
    await recordCtaEvent('click', 'general');
    window.location.assign(route('register'));
}

async function goToLogin() {
    await recordCtaEvent('login', 'general');
    window.location.assign(route('login'));
}

onMounted(() => {
    if (props.heroBackgroundUrl) {
        const image = new Image();
        image.onload = () => { heroBackgroundLoaded.value = true; };
        image.src = props.heroBackgroundUrl;
    }

    const mobileHero = new Image();
    mobileHero.onload = () => { mobileHeroLoaded.value = true; };
    mobileHero.src = '/img/home/imagen%20para%20movil%20inicio.png';

    const desktopHero = new Image();
    desktopHero.onload = () => { desktopHeroLoaded.value = true; };
    desktopHero.src = '/img/home/imagen%20para%20escritorio%20inicio%20de%20arka01.png';

    if (!authUser && props.canRegister) {
        const lastShown = Number(window.localStorage.getItem('arka01_welcome_cta_shown_at') || 0);
        const sevenDays = 7 * 24 * 60 * 60 * 1000;
        if (Date.now() - lastShown > sevenDays) {
            welcomeCtaTimer = window.setTimeout(() => {
                showingWelcomeCta.value = true;
                window.localStorage.setItem('arka01_welcome_cta_shown_at', String(Date.now()));
                recordCtaEvent('impression');
            }, 4500);
        }
    }
});

onUnmounted(() => {
    if (welcomeCtaTimer) window.clearTimeout(welcomeCtaTimer);
});

// Encuesta corta (pedido explícito del usuario: "colocalo en la raíz
// también") — en la página pública, además del Home y el login. Mismo
// criterio de localStorage que las otras dos para no insistir a quien ya
// respondió.
const surveyDone = ref(typeof window !== 'undefined' && window.localStorage.getItem('arka01_survey_done') === '1');

const showingGuestIdentity = ref(false);
// El buscador público inicia resumido para que el primer contacto sea simple:
// una sola pregunta y las acciones principales de acceso a la vista.
const showingGuestRideForm = ref(false);
const guestLocationMessage = ref('');
const guestForm = useForm({
    origin_address: '', origin_lat: null, origin_lng: null,
    destination_address: '', destination_lat: null, destination_lng: null,
    cooperative_id: null,
    name: '', country_code: '+593', phone_local: '',
    website: '',
});

// Pedido explícito del usuario: si el número ya tiene cuenta (mensaje
// puntual de GuestRideController::store()), ofrecer el atajo a iniciar
// sesión en vez de dejarlo trabado acá — mismo criterio ya usado en
// Auth/Register.vue para el mismo caso.
const showsAccountExistsError = computed(() => (guestForm.errors.phone_local ?? '').includes('ya tiene una cuenta'));

const cooperativeOptions = computed(() => props.guestCooperatives.map((cooperative) => ({
    value: cooperative.id,
    label: `${cooperative.name} · ${cooperative.active_driver_memberships_count} unidades`,
})));
const assignedCooperative = computed(() => props.guestCooperatives.find((item) => item.id === guestForm.cooperative_id));

function nearestCooperative(lat, lng) {
    if (!props.guestCooperatives.length) return null;
    const radians = (value) => value * Math.PI / 180;
    const distance = (item) => {
        const dLat = radians(Number(item.stand_lat) - lat);
        const dLng = radians(Number(item.stand_lng) - lng);
        const a = Math.sin(dLat / 2) ** 2 + Math.cos(radians(lat)) * Math.cos(radians(Number(item.stand_lat))) * Math.sin(dLng / 2) ** 2;
        return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    };
    return [...props.guestCooperatives].sort((a, b) => distance(a) - distance(b))[0];
}

function chooseOrigin(place) {
    guestForm.origin_address = place.address || 'Mi ubicación actual';
    guestForm.origin_lat = place.lat;
    guestForm.origin_lng = place.lng;
    guestForm.cooperative_id = nearestCooperative(Number(place.lat), Number(place.lng))?.id ?? null;
    guestLocationMessage.value = '';
}

function chooseDestination(place) {
    guestForm.destination_address = place.address;
    guestForm.destination_lat = place.lat;
    guestForm.destination_lng = place.lng;
}

// El buscador se comporta como una reserva de viaje: invertir el trayecto
// intercambia también sus coordenadas, no solo el texto visible.
function swapGuestRoute() {
    const origin = {
        address: guestForm.origin_address,
        lat: guestForm.origin_lat,
        lng: guestForm.origin_lng,
    };

    guestForm.origin_address = guestForm.destination_address;
    guestForm.origin_lat = guestForm.destination_lat;
    guestForm.origin_lng = guestForm.destination_lng;
    guestForm.destination_address = origin.address;
    guestForm.destination_lat = origin.lat;
    guestForm.destination_lng = origin.lng;

    if (guestForm.origin_lat != null && guestForm.origin_lng != null) {
        guestForm.cooperative_id = nearestCooperative(
            Number(guestForm.origin_lat),
            Number(guestForm.origin_lng),
        )?.id ?? null;
    }
}

function toggleGuestRideForm() {
    if (typeof window !== 'undefined' && window.innerWidth >= 768) return;
    showingGuestRideForm.value = !showingGuestRideForm.value;
}

function useCurrentLocation() {
    guestLocationMessage.value = 'Ubicando…';
    if (!navigator.geolocation) {
        guestLocationMessage.value = 'Su navegador no permite obtener la ubicación.';
        return;
    }
    navigator.geolocation.getCurrentPosition(
        ({ coords }) => {
            chooseOrigin({ lat: coords.latitude, lng: coords.longitude, address: 'Mi ubicación actual' });
            guestLocationMessage.value = 'Ubicación lista';
        },
        () => { guestLocationMessage.value = 'No pudimos acceder a su ubicación. Puede buscarla manualmente.'; },
        { enableHighAccuracy: true, timeout: 10000 }
    );
}

function continueAsGuest() {
    guestForm.clearErrors();
    if (guestForm.origin_lat == null) guestForm.setError('origin_address', 'Seleccione el origen o use su ubicación actual.');
    if (guestForm.destination_lat == null) guestForm.setError('destination_address', 'Elija un destino de la lista para ubicarlo en el mapa.');
    if (!guestForm.cooperative_id) guestForm.setError('cooperative_id', 'No encontramos una cooperativa disponible para este origen.');
    if (guestForm.hasErrors) return;
    showingGuestIdentity.value = true;
}

function submitGuestRide() {
    guestForm.post(route('guest-rides.store'), { preserveScroll: true });
}

// "Para Clientes" / "Para Conductores" (pedido explícito del usuario, mockup
// provisto) — reemplaza el flujo de pasos anterior por dos fichas con lo que
// gana cada lado, más el diagrama del medio.
const CLIENT_FEATURES = [
    { title: 'Solicita tu viaje', text: 'Indica a dónde quieres ir.' },
    { title: 'Elige cómo viajar', text: 'Tu flota, una cooperativa o conductores públicos.' },
    { title: 'Conoce tus opciones', text: 'Revisa reputación e información antes de elegir.' },
    { title: 'Guarda a quien te dio confianza', text: 'Agrégalo a tu flota para volver a solicitarlo.' },
    { title: 'Construye tu red', text: 'Conecta, recibe recomendaciones y amplía tus opciones de confianza.' },
];
const DRIVER_FEATURES = [
    { title: 'Tú decides cómo trabajar', text: 'Define tu tarifa y cuándo estar disponible.' },
    { title: 'Conoce antes de aceptar', text: 'Revisa la reputación e índice de confianza del cliente.' },
    { title: 'Construye tu propia clientela', text: 'Tus clientes privados pueden agregarte y volver a solicitarte.' },
    { title: 'Haz crecer tu reputación', text: 'Cada carrera, calificación e historial fortalece tu perfil.' },
    { title: 'Independiente o con cooperativa', text: 'Desarrolla tu actividad privada y también trabaja dentro de una cooperativa.' },
];

const WHY_ARKA01 = [
    { title: 'Relaciones que continúan', text: 'Una buena carrera puede convertirse en una relación para futuros viajes.' },
    { title: 'Tú eliges con quién viajar', text: 'Tu flota, cooperativas o conductores públicos según tus necesidades.' },
    { title: 'Confianza visible', text: 'Reputación, calificaciones e índice de confianza para tomar mejores decisiones.' },
    { title: 'Una red que crece contigo', text: 'Clientes, conductores y cooperativas conectados en un mismo ecosistema.' },
    { title: 'Tecnología para todos', text: 'Solicita, conduce o gestiona tu cooperativa desde Arka01, con apoyo de WhatsApp.' },
];

// "Ayúdanos a mejorar ARKA01" (roadmap de mejoras, sección 14) — público, sin
// cuenta, nombre y correo opcionales a propósito. Mockup provisto: barra
// discreta que abre el formulario en un modal, en vez de ocupar toda la
// página de una siempre.
const showingFeedback = ref(false);
const feedbackForm = useForm({
    name: '',
    email: '',
    type: 'sugerencia',
    comment: '',
});

function submitFeedback() {
    feedbackForm.post(route('platform-feedback.store'), {
        preserveScroll: true,
        onSuccess: () => {
            feedbackForm.reset('name', 'email', 'comment');
        },
    });
}
</script>

<template>
    <Head title="Arka01 — Tu círculo. Tus viajes. Tu decisión.">
        <link rel="preload" as="image" href="/img/home/imagen%20para%20movil%20inicio.png" media="(max-width: 767px)" fetchpriority="high" />
        <link rel="preload" as="image" href="/img/home/imagen%20para%20escritorio%20inicio%20de%20arka01.png" media="(min-width: 768px)" fetchpriority="high" />
    </Head>

    <div class="arka-app-background min-h-screen">
        <div class="welcome-page-shell max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-5 sm:py-8">
            <!-- Navegación pública: completa en escritorio y esencial en móvil. -->
            <header v-if="!authUser" class="welcome-nav mb-4 flex items-center justify-center gap-4 rounded-2xl px-3 py-2.5 sm:justify-between sm:px-4">
                <Link href="/" aria-label="Ir al inicio">
                    <ApplicationLogo size="h-11 sm:h-10" />
                </Link>

                <nav class="hidden items-center gap-6 text-xs font-semibold text-arka-text-muted lg:flex" aria-label="Navegación principal">
                    <a href="#inicio" class="text-arka-primary">Inicio</a>
                    <a href="#como-funciona" class="transition hover:text-arka-primary">Cómo Funciona</a>
                    <a href="#para-quienes" class="transition hover:text-arka-primary">Para quién es</a>
                    <a href="#contacto" class="transition hover:text-arka-primary">Contacto</a>
                </nav>

                <div class="hidden items-center gap-2 sm:flex">
                    <Link v-if="canLogin" :href="route('login')" class="hidden min-h-9 items-center rounded-lg border border-arka-primary/35 px-3.5 text-xs font-semibold text-arka-primary transition hover:bg-arka-primary/10 sm:inline-flex">Iniciar sesión</Link>
                    <Link v-if="canRegister" :href="route('register')" class="hidden min-h-9 items-center rounded-lg bg-arka-primary px-3.5 text-xs font-semibold text-white transition hover:bg-arka-primary-bright sm:inline-flex">Crear cuenta</Link>
                </div>
            </header>

            <div v-else class="mb-4 flex items-center justify-end gap-3">
                <Link
                    :href="route('dashboard')"
                    class="flex items-center gap-2 text-sm text-arka-text-muted hover:text-arka-text"
                >
                    <UserAvatar :user="authUser" size-class="h-9 w-9 text-sm" />
                </Link>
            </div>

            <!-- Con sesión iniciada, el hero completo (mockup, CTAs de registro)
                 no aplica — ya tiene el acceso directo arriba. -->
            <div v-if="authUser" class="flex items-center justify-between gap-4 rounded-2xl border border-arka-border bg-arka-card p-4 shadow-sm sm:px-6">
                <ApplicationLogo size="h-10 sm:h-11" />
                <Link
                    :href="route('dashboard')"
                    class="inline-flex min-h-11 items-center rounded-xl bg-arka-primary px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-arka-primary-bright"
                >
                    Ir a mi cuenta
                </Link>
            </div>

            <!-- Encabezado (pedido explícito del usuario: hero con mockup de
                 teléfono, insignia y llamado a la acción "Crear mi círculo").
                 Pedido explícito del usuario ("por lo menos haz que la
                 pueda colocar desde la parte de configuración del admin"):
                 la foto de fondo (ciudad de noche + puente + estelas
                 verdes) ya no es una ruta fija — sale de
                 `heroBackgroundUrl` (Admin\SiteSettingController, subida
                 desde /admin/sitio). Sin ninguna subida todavía, queda sin
                 imagen — el degradado solo ya se ve bien contra el fondo
                 claro de toda la app. `bg-cover`/`bg-top` para
                 que la parte de arriba (donde vive el texto) sea la que más
                 se vea. -->
            <div
                v-else
                id="inicio"
                class="welcome-hero relative isolate grid grid-cols-1 items-center gap-6 overflow-hidden rounded-[2rem] border border-arka-primary/20 px-4 py-6 sm:px-8 sm:py-9 md:grid-cols-2 md:gap-8"
            >
                <!-- Foto de fondo con fundido suave (pedido explícito del usuario:
                     "que no se vea tan brusco... lo hiciste en el login y me
                     gustó") — capa aparte que recién se hace visible cuando la
                     imagen ya terminó de precargarse (ver heroBackgroundLoaded),
                     en vez de pintarse de golpe como antes. -->
                <div
                    class="welcome-hero__image welcome-hero__image--mobile pointer-events-none absolute inset-0 -z-20 bg-cover md:hidden transition-opacity duration-300 ease-out"
                    :class="mobileHeroLoaded ? 'opacity-100' : 'opacity-0'"
                    :style="{ backgroundImage: `url('/img/home/imagen%20para%20movil%20inicio.png')` }"
                />
                <div
                    class="welcome-hero__image welcome-hero__image--desktop pointer-events-none absolute inset-0 -z-20 hidden bg-cover bg-center md:block transition-opacity duration-300 ease-out"
                    :class="desktopHeroLoaded ? 'opacity-100' : 'opacity-0'"
                    :style="{ backgroundImage: `url('/img/home/imagen%20para%20escritorio%20inicio%20de%20arka01.png')` }"
                />
                <div class="welcome-hero__content p-5 text-center sm:p-6 md:text-start">
                    <p class="inline-flex items-center gap-1.5 rounded-full bg-arka-primary/10 px-3 py-1 text-xs font-semibold text-arka-primary-bright">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m12 3 8 3.5v5.2c0 4.4-3 7.6-8 9.3-5-1.7-8-4.9-8-9.3V6.5L12 3Z" />
                        </svg>
                        Tu círculo, tus viajes.
                    </p>

                    <!-- Pedido explícito del usuario ("quiero que el diseño
                         quede tal cual te pase"): acá se sigue el tuteo
                         informal de la imagen de referencia tal como la
                         mandó, aunque el resto de la app (y la propia
                         tarjeta de invitado de al lado) use "usted" —
                         avisado en la respuesta, por si preferís unificarlo
                         después. -->
                    <h1 class="welcome-hero__title mt-4 text-4xl font-extrabold leading-[1.03] tracking-[-0.035em] text-arka-text sm:text-5xl md:text-6xl">
                        Viaja con
                        <span class="text-arka-primary">quienes</span> confías
                    </h1>

                    <p class="mx-auto mt-3 max-w-md text-sm font-medium leading-5 text-arka-text-muted md:mx-0 md:text-base">
                        Crea tu propia red de conductores y solicita tus viajes con mayor tranquilidad.
                    </p>
                    <ul class="welcome-desktop-benefits mt-5 grid max-w-lg grid-cols-3 gap-5">

    <li>
                            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="9" cy="8" r="3"/><path stroke-linecap="round" d="M3 19a6 6 0 0 1 12 0M16 7.5a2.5 2.5 0 0 1 0 5M17 15c2 0 3.5 1.5 4 4"/></svg></span>
                            <p>Tu gente<br>de confianza</p>
                        </li>
                        <li>
                            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="m12 3 7 3v5c0 4-2.6 7-7 9-4.4-2-7-5-7-9V6l7-3Z"/><path stroke-linecap="round" stroke-linejoin="round" d="m9 11 2 2 4-4"/></svg></span>
                            <p>Más seguridad<br>en cada viaje</p>
                        </li>
                        <li>
                            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7-4.6-7-10.5A7 7 0 0 1 12 4a7 7 0 0 1 7 6.5C19 16.4 12 21 12 21Z"/><circle cx="12" cy="10.5" r="2.2"/></svg></span>
                            <p>Cobertura<br>en tu zona</p>
                        </li>
                    </ul>

                    <div class="welcome-desktop-actions mt-5 hidden max-w-md grid-cols-2 gap-3 md:grid">
                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="inline-flex min-h-12 items-center justify-center gap-3 rounded-xl bg-arka-primary px-5 text-sm font-bold text-white"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9.5" cy="8.5" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M3.5 20a6 6 0 0 1 12 0M18 8v6M15 11h6"/></svg>
                            <span>Crear mi cuenta</span>
                            <svg class="ms-auto h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-5-5 5 5-5 5"/></svg>
                        </Link>
                        <Link
                            v-if="canLogin"
                            :href="route('login')"
                            class="inline-flex min-h-12 items-center justify-center gap-3 rounded-xl border border-arka-primary/55 bg-white/80 px-5 text-sm font-bold text-arka-primary"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 4.5h4A1.5 1.5 0 0 1 19.5 6v12a1.5 1.5 0 0 1-1.5 1.5h-4M11 8.5 15 12l-4 3.5M15 12H4"/></svg>
                            Iniciar sesión
                        </Link>
                    </div>

          <!--           <a href="#solicitud-rapida" class="welcome-desktop-quick-link mt-3 hidden text-xs font-medium text-arka-text-muted md:inline-flex">
                        ¿Solo quieres solicitar un viaje? <span class="ms-1 font-bold text-arka-primary">Prueba la solicitud rápida →</span>
                    </a> -->



                    <!-- Encuesta corta (pedido explícito del usuario: "en la
                         raíz también") — mismo lado izquierdo que el resto
                         del bloque (hereda text-center lg:text-start). -->
                    <p v-if="!surveyDone" class="welcome-survey-link mt-4">
                        <Link :href="route('survey.show')" class="text-sm font-medium text-arka-primary hover:text-arka-primary-bright">
                            Cuentanos tu experiencia con el transporte hoy (2 min) →
                        </Link>
                    </p>
                </div>

                <!-- Separador entre el bloque de texto y la tarjeta de invitado
                     (pedido explícito del usuario): horizontal cuando las 2
                     columnas se apilan en móvil (fila propia del grid), y una
                     línea vertical centrada — con desvanecido en los extremos,
                     no un trazo duro — cuando van lado a lado en escritorio. -->
                <div class="hidden"></div>
                <div class="hidden"></div>

                <!-- Acceso urgente sin correo: conserva la identidad visual de
                     la app y asigna la cooperativa más cercana al origen. -->
                <div class="welcome-hero__journey mx-auto w-full max-w-lg">
                <!-- Acciones principales antes del buscador: el visitante decide
                     primero si entra, se registra o conoce el funcionamiento. -->
                <div class="welcome-hero__actions mb-4 grid grid-cols-1 gap-2.5 sm:grid-cols-2 md:hidden">
                    <Link
                        v-if="canLogin"
                        :href="route('login')"
                        class="welcome-login-button order-2 group inline-flex min-h-12 items-center justify-center gap-2 rounded-xl border border-white/55 bg-[#0a221b]/80 px-4 py-3 text-sm font-bold text-white backdrop-blur-sm transition hover:-translate-y-0.5 hover:bg-[#0a221b] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-arka-primary"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 4.5h4a1.5 1.5 0 0 1 1.5 1.5v12a1.5 1.5 0 0 1-1.5 1.5h-4M11 8.5 15 12l-4 3.5M15 12H4"/></svg>
                        Iniciar sesión
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="route('register')"
                        class="welcome-create-button order-1 group inline-flex min-h-12 items-center justify-between gap-2 rounded-xl bg-arka-primary px-5 py-3 text-sm font-bold text-white shadow-[0_8px_24px_rgba(20,125,88,0.24)] transition hover:-translate-y-0.5 hover:bg-arka-primary-bright focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-arka-primary"
                    >
                        <span class="inline-flex items-center gap-2">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9.5" cy="8.5" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M3.5 20a6 6 0 0 1 12 0M18 8v6M15 11h6"/></svg>
                            Crear mi cuenta
                        </span>
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>
                    </Link>
                    <a
                        href="#como-funciona"
                        class="welcome-how-button order-3 inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-white/45 bg-white/10 px-4 py-2.5 text-sm font-semibold text-white backdrop-blur-sm transition hover:bg-white/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-arka-primary sm:col-span-2"
                    >
                        <span class="flex h-6 w-6 items-center justify-center rounded-full border border-arka-primary/30 text-arka-primary">
                            <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72a1 1 0 0 0 1.53.85l11-6.86a1 1 0 0 0 0-1.7l-11-6.86A1 1 0 0 0 8 5.14Z"/></svg>
                        </span>
                        Cómo Funciona
                    </a>
                </div>

                <!-- Pedido explícito del usuario: la solicitud rápida como
                     invitado solo funciona con una cooperativa asignada
                     (ver GuestRideController::store(), exige
                     cooperative_id) — sin ninguna disponible, el formulario
                     era un callejón sin salida ("No hay cooperativas
                     disponibles"), así que directamente no se ofrece. -->
                <template v-if="guestCooperatives.length">
                <div class="welcome-or-divider" aria-hidden="true">
                    <span></span><p>o comienza ahora</p><span></span>
                </div>

                <button
                    v-if="showingGuestRideForm"
                    type="button"
                    class="welcome-quick-backdrop"
                    aria-label="Cerrar solicitud rápida"
                    @click="showingGuestRideForm = false"
                ></button>

                <div
                    id="solicitud-rapida"
                    class="welcome-quick-card relative overflow-visible rounded-[1.75rem] border border-arka-primary/30 bg-arka-card shadow-2xl"
                    :class="{ 'welcome-quick-card--expanded': showingGuestRideForm }"
                >
                    <!-- Cabecera inspirada en un buscador de reservas: primero
                         explica la acción y después presenta el trayecto. -->
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 rounded-[1.75rem] px-5 py-3.5 text-start transition hover:bg-arka-primary/[0.04] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-arka-primary focus-visible:ring-offset-2 focus-visible:ring-offset-arka-base sm:px-6"
                        :class="showingGuestRideForm ? 'rounded-b-none border-b border-arka-border' : ''"
                        :aria-expanded="showingGuestRideForm"
                        aria-controls="guest-ride-form"
                        @click="toggleGuestRideForm"
                    >
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-arka-primary">Solicitud rápida</p>
                            <h2 class="mt-0.5 text-xl font-bold text-arka-text">¿A dónde vamos?</h2>
                            <p class="mt-0.5 text-[11px] text-arka-text-muted">{{ showingGuestRideForm ? 'Defina su recorrido y consulte la tarifa.' : 'Indica tu origen y destino.' }}</p>
                        </div>
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-arka-primary/15 text-arka-primary">
                            <svg v-if="showingGuestRideForm" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m6 15 6-6 6 6"/></svg>
                            <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none"/><path stroke-linecap="round" d="M12 2v3m0 14v3M2 12h3m14 0h3"/></svg>
                        </span>
                    </button>

                    <div
                        v-if="!showingGuestRideForm"
                        class="welcome-desktop-field welcome-desktop-field--origin hidden md:block"
                    >
                        <AddressAutocomplete
                            v-model="guestForm.origin_address"
                            light
                            placeholder="Indica tu origen"
                            @place-selected="chooseOrigin"
                            @clear="guestForm.origin_lat = guestForm.origin_lng = null"
                        />
                        <p v-if="guestForm.errors.origin_address" class="welcome-desktop-field-error">{{ guestForm.errors.origin_address }}</p>
                    </div>

                    <button
                        v-if="!showingGuestRideForm"
                        type="button"
                        class="welcome-desktop-swap hidden md:inline-flex"
                        aria-label="Invertir origen y destino"
                        @click="swapGuestRoute"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h11m0 0-3-3m3 3-3 3M17 17H6m0 0 3 3m-3-3 3-3"/></svg>
                    </button>

                    <button
                        v-if="!showingGuestRideForm"
                        type="button"
                        class="welcome-quick-preview md:hidden"
                        aria-label="Seleccionar destino y abrir solicitud rápida"
                        aria-controls="guest-ride-form"
                        @click="showingGuestRideForm = true"
                    >
                        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="currentColor"><path d="M12 22s7-6.1 7-13A7 7 0 0 0 5 9c0 6.9 7 13 7 13Zm0-10a3 3 0 1 1 0-6 3 3 0 0 1 0 6Z"/></svg>
                        <span>Selecciona tu destino</span>
                        <svg class="ms-auto h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>
                    </button>

                    <div
                        v-if="!showingGuestRideForm"
                        class="welcome-desktop-field welcome-desktop-field--destination hidden md:block"
                    >
                        <AddressAutocomplete
                            v-model="guestForm.destination_address"
                            light
                            placeholder="Indica tu destino"
                            @place-selected="chooseDestination"
                            @clear="guestForm.destination_lat = guestForm.destination_lng = null"
                        />
                        <p v-if="guestForm.errors.destination_address" class="welcome-desktop-field-error">{{ guestForm.errors.destination_address }}</p>
                    </div>

                    <button
                        v-if="!showingGuestRideForm"
                        type="button"
                        class="welcome-quick-submit-preview hidden md:inline-flex"
                        @click="continueAsGuest"
                    >
                        Buscar viaje
                    </button>

                    <p v-if="guestForm.errors.cooperative_id" class="welcome-desktop-coop-error hidden md:block">
                        {{ guestForm.errors.cooperative_id }}
                    </p>

                    <div v-show="showingGuestRideForm" id="guest-ride-form" class="px-5 py-4 sm:px-6">
                        <div class="mb-2.5 flex items-center justify-between gap-3">
                            <div>
                                 <p class="mt-0.5 text-sm font-semibold text-arka-text">Origen y destino</p>
                            </div>
                            <button
                                type="button"
                                class="inline-flex h-8 items-center gap-1.5 rounded-full border border-arka-border px-2.5 text-[11px] font-semibold text-arka-text transition hover:border-arka-primary/50 hover:text-arka-primary"
                                aria-label="Invertir punto de partida y destino"
                                @click="swapGuestRoute"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h11m0 0-3-3m3 3-3 3M17 17H6m0 0 3 3m-3-3 3-3"/></svg>
                                Invertir
                            </button>
                        </div>

                        <!-- La línea y los puntos convierten los campos en un
                             recorrido reconocible, no en credenciales de login. -->
                        <div class="relative ps-8">
                            <div class="absolute bottom-8 left-[9px] top-8 w-px bg-gradient-to-b from-arka-primary via-arka-primary/40 to-arka-danger/70"></div>

                            <div class="relative pb-3">
                                <span class="absolute -left-8 top-8 h-[11px] w-[11px] rounded-full border-[3px] border-arka-card bg-arka-primary ring-1 ring-arka-primary/50"></span>
                                <div class="mb-1.5 flex items-center justify-between gap-3">
                                    <label class="text-[10px] font-bold uppercase tracking-[0.14em] text-arka-primary">Origen</label>
                                    <button type="button" class="inline-flex items-center gap-1 text-[11px] font-semibold text-arka-primary hover:text-arka-primary-bright" @click="useCurrentLocation">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path stroke-linecap="round" d="M12 2v3m0 14v3M2 12h3m14 0h3"/></svg>
                                        Mi ubicación
                                    </button>
                                </div>
                                <AddressAutocomplete v-model="guestForm.origin_address" placeholder="Ciudad, calle o punto de referencia" @place-selected="chooseOrigin" @clear="guestForm.origin_lat = guestForm.origin_lng = null" />
                                <p v-if="guestLocationMessage" class="mt-1.5 text-xs text-arka-text-muted">{{ guestLocationMessage }}</p>
                                <p v-if="guestForm.errors.origin_address" class="mt-1.5 text-xs text-arka-danger">{{ guestForm.errors.origin_address }}</p>
                            </div>

                            <div class="relative">
                                <span class="absolute -left-8 top-8 h-[11px] w-[11px] rotate-45 rounded-[3px] border-[3px] border-arka-card bg-arka-danger ring-1 ring-arka-danger/50"></span>
                                <label class="mb-1.5 block text-[10px] font-bold uppercase tracking-[0.14em] text-arka-danger">Destino</label>
                                <AddressAutocomplete v-model="guestForm.destination_address" placeholder="Ciudad, calle o punto de referencia" @place-selected="chooseDestination" @clear="guestForm.destination_lat = guestForm.destination_lng = null" />
                                <p v-if="guestForm.errors.destination_address" class="mt-1.5 text-xs text-arka-danger">{{ guestForm.errors.destination_address }}</p>
                            </div>
                        </div>

                        <div class="my-3 h-px bg-arka-text-muted/10"></div>

                        <div>
                            <div class="mb-1.5 flex items-center gap-2">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-arka-primary/10 text-arka-primary">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m12 3 7 3v5c0 4-2.6 7-7 9-4.4-2-7-5-7-9V6l7-3Z"/><path stroke-linecap="round" d="m9 11 2 2 4-4"/></svg>
                                </span>
                                <div>
                                    <label class="block text-xs font-semibold text-arka-text">Cooperativa que atenderá</label>
                                    <p class="text-[9px] text-arka-text-muted">Sugerimos la opción disponible más cercana.</p>
                                </div>
                            </div>
                            <SearchableSelect v-model="guestForm.cooperative_id" :options="cooperativeOptions" placeholder="Seleccione una cooperativa" />
                            <p v-if="assignedCooperative" class="mt-1.5 text-[10px] text-arka-primary">✓ {{ assignedCooperative.name }} seleccionada por cercanía. Puede cambiarla.</p>
                            <p v-if="guestForm.errors.cooperative_id" class="mt-1.5 text-xs text-arka-danger">{{ guestForm.errors.cooperative_id }}</p>
                        </div>

                        <div class="mt-3 grid grid-cols-3 gap-2 border-y border-arka-border py-2 text-center">
                            <div class="flex items-center justify-center gap-1.5"><span class="flex h-4 w-4 items-center justify-center rounded-full bg-arka-primary text-[9px] font-bold text-white">1</span><p class="text-[9px] text-arka-text-muted">Ruta</p></div>
                            <div class="flex items-center justify-center gap-1.5"><span class="flex h-4 w-4 items-center justify-center rounded-full border border-arka-primary/40 text-[9px] font-bold text-arka-primary">2</span><p class="text-[9px] text-arka-text-muted">Tarifa</p></div>
                            <div class="flex items-center justify-center gap-1.5"><span class="flex h-4 w-4 items-center justify-center rounded-full border border-arka-primary/40 text-[9px] font-bold text-arka-primary">3</span><p class="text-[9px] text-arka-text-muted">Confirmar</p></div>
                        </div>

                        <button type="button" class="mt-3 flex w-full items-center justify-center gap-2 rounded-arka bg-arka-primary px-5 py-3 text-xs font-bold uppercase tracking-wide text-white shadow-lg shadow-arka-primary/15 transition hover:bg-arka-primary-bright" @click="continueAsGuest">
                            Consultar tarifa
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
                        </button>
                        <p class="mt-2 text-center text-[9px] leading-4 text-arka-text-muted">Nada se envía hasta que revise la tarifa y confirme.</p>
                    </div>
                </div>
                </template>

                <a href="#como-funciona" class="welcome-mobile-how">
                    <span>Cómo Funciona</span>
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
                </a>

                </div>
            </div>

            <!-- Para Clientes / diagrama / Para Conductores (pedido explícito del
                 usuario, mockup provisto) — id como destino del botón "¿Cómo
                 funciona?" del hero, para reusar este mismo contenido en vez de
                 duplicarlo en una página aparte. -->
            <section id="como-funciona" class="scroll-mt-6" :class="authUser ? 'mt-8' : 'mt-16'">
            <div class="grid grid-cols-1 items-center gap-8 md:hidden">
                <!-- Para Clientes -->
                <div class="rounded-2xl border border-arka-border bg-arka-card p-6 shadow-sm">
                    <div class="flex items-center gap-3 mb-1">
                        <span class="h-11 w-11 rounded-full bg-arka-primary/15 flex items-center justify-center shrink-0">
                            <svg class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="9" cy="8" r="3" stroke-linecap="round" stroke-linejoin="round" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 19a6.5 6.5 0 0 1 13 0" />
                                <circle cx="17" cy="8" r="2.4" stroke-linecap="round" stroke-linejoin="round" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.5 12.5c2.4 0 4.5 1.9 5 6.5" />
                            </svg>
                        </span>
                        <h2 class="text-lg font-semibold text-arka-text">
                            Para <span class="text-arka-primary">Clientes</span>
                        </h2>
                    </div>
                    <p class="mb-5 mt-3 text-sm font-medium leading-6 text-arka-text-muted">
                        Empieza buscando un viaje. Termina construyendo tu propia red de conductores de confianza.
                    </p>

                    <ul>
                        <li v-for="(feature, i) in CLIENT_FEATURES" :key="feature.title" class="flex items-start gap-3">
                            <!-- Pedido explícito del usuario: una línea que una los
                                 puntos, no puntos sueltos — mismo criterio que el
                                 diagrama del medio, pero vertical. -->
                            <div class="flex w-8 shrink-0 flex-col items-center self-stretch">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-arka-primary/45 bg-arka-primary/10 text-[9px] font-bold tracking-wide text-arka-primary">
                                    {{ String(i + 1).padStart(2, '0') }}
                                </span>
                                <div v-if="i < CLIENT_FEATURES.length - 1" class="mt-1 min-h-[1.5rem] w-px flex-1 bg-arka-primary/30"></div>
                            </div>
                            <div class="pb-4">
                                <p class="text-sm font-medium text-arka-text">{{ feature.title }}</p>
                                <p class="text-xs text-arka-text-muted">{{ feature.text }}</p>
                            </div>
                        </li>
                    </ul>

                    <div class="mt-1 flex items-start gap-3 rounded-arka border border-arka-primary/25 bg-arka-primary/[0.06] p-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-arka-primary/15 text-arka-primary">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 11.5a8 8 0 0 1-11.8 7L4 20l1.5-4A8 8 0 1 1 20 11.5Z" />
                            </svg>
                        </span>
                        <div>
                            <p class="text-xs font-semibold text-arka-text">También desde WhatsApp</p>
                            <p class="mt-0.5 text-[11px] leading-4 text-arka-text-muted">Solicita viajes de forma sencilla cuando lo necesites.</p>
                        </div>
                    </div>

                    <Link
                        v-if="canRegister"
                        :href="route('register')"
                        class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-arka-primary hover:text-arka-primary-bright"
                    >
                        Soy cliente
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6" />
                        </svg>
                    </Link>
                </div>

                <!-- Diagrama del medio -->
                <div class="text-center px-2">
                    <p class="text-sm font-medium text-arka-text-muted mb-4 max-w-[14rem] mx-auto">
                        Conectamos a su círculo de <span class="text-arka-primary">confianza</span>
                    </p>

                    <div class="flex items-center justify-center gap-2 lg:flex-col lg:gap-3">
                        <div class="flex flex-col items-center gap-1">
                            <span class="h-12 w-12 rounded-full bg-arka-card border border-arka-border flex items-center justify-center">
                                <svg class="h-5 w-5 text-arka-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="8" r="3.5" stroke-linecap="round" stroke-linejoin="round" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 20a7.5 7.5 0 0 1 15 0" />
                                </svg>
                            </span>
                            <span class="text-[10px] text-arka-text-muted uppercase tracking-wide">Clientes</span>
                        </div>

                        <div class="w-10 lg:w-px lg:h-8 border-t-2 lg:border-t-0 lg:border-s-2 border-dashed border-arka-primary/30"></div>

                        <div class="flex flex-col items-center gap-1">
                            <!-- Pedido explícito del usuario (con captura): el
                                 lockup completo (isotipo + "Arka01") quedaba feo
                                 apretado en esta burbuja chica, al lado de íconos
                                 simples de Clientes/Conductores — acá va solo el
                                 isotipo "A", igual de simple que esos íconos. -->
                            <span class="h-16 w-16 rounded-full bg-arka-primary/15 border-2 border-arka-primary flex items-center justify-center shadow-[0_0_20px_rgba(52,211,153,0.25)]">
                                <img src="/img/logo-arka01-icono.png" alt="Arka01" class="h-9 w-auto" />
                            </span>
                        </div>

                        <div class="w-10 lg:w-px lg:h-8 border-t-2 lg:border-t-0 lg:border-s-2 border-dashed border-arka-primary/30"></div>

                        <div class="flex flex-col items-center gap-1">
                            <span class="h-12 w-12 rounded-full bg-arka-card border border-arka-border flex items-center justify-center">
                                <svg class="h-5 w-5 text-arka-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l2.5-6.5A2 2 0 0 1 8.35 8.2h7.3a2 2 0 0 1 1.85 1.3L20 16" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16h16v2.5a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1V17H7v1.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V16Z" />
                                </svg>
                            </span>
                            <span class="text-[10px] text-arka-text-muted uppercase tracking-wide">Conductores</span>
                        </div>
                    </div>

                    <ul class="mt-6 space-y-2 text-start max-w-[16rem] mx-auto">
                        <li v-for="point in ['Su red de confianza primero', 'Perfiles e índice de confianza', 'Seguimiento y seguridad en carrera']" :key="point" class="flex items-center gap-2 text-sm text-arka-text-muted">
                            <svg class="h-4 w-4 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" />
                            </svg>
                            {{ point }}
                        </li>
                    </ul>
                </div>

                <!-- Para Conductores -->
                <div class="rounded-2xl border border-arka-border bg-arka-card p-6 shadow-sm">
                    <div class="flex items-center gap-3 mb-1">
                        <span class="h-11 w-11 rounded-full bg-arka-primary/15 flex items-center justify-center shrink-0">
                            <svg class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l2.5-6.5A2 2 0 0 1 8.35 8.2h7.3a2 2 0 0 1 1.85 1.3L20 16" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16h16v2.5a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1V17H7v1.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V16Z" />
                            </svg>
                        </span>
                        <h2 class="text-lg font-semibold text-arka-text">
                            Para <span class="text-arka-primary">Conductores</span>
                        </h2>
                    </div>
                    <p class="mb-5 mt-3 text-sm font-medium leading-6 text-arka-text-muted">
                        Empieza con una carrera. Termina construyendo una red de clientes que quieren volver a viajar contigo.
                    </p>

                    <ul>
                        <li v-for="(feature, i) in DRIVER_FEATURES" :key="feature.title" class="flex items-start gap-3">
                            <div class="flex w-8 shrink-0 flex-col items-center self-stretch">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-arka-primary/45 bg-arka-primary/10 text-[9px] font-bold tracking-wide text-arka-primary">
                                    {{ String(i + 1).padStart(2, '0') }}
                                </span>
                                <div v-if="i < DRIVER_FEATURES.length - 1" class="mt-1 min-h-[1.5rem] w-px flex-1 bg-arka-primary/30"></div>
                            </div>
                            <div class="pb-4">
                                <p class="text-sm font-medium text-arka-text">{{ feature.title }}</p>
                                <p class="text-xs text-arka-text-muted">{{ feature.text }}</p>
                            </div>
                        </li>
                    </ul>

                    <Link
                        v-if="canRegister"
                        :href="route('register')"
                        class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-arka-primary hover:text-arka-primary-bright"
                    >
                        Soy conductor
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6" />
                        </svg>
                    </Link>
                </div>
            </div>

            <div class="welcome-desktop-story hidden md:block">
                <div class="welcome-how-heading text-center">
                    <span aria-hidden="true"></span>
                    <h2>¿Cómo funciona?</h2>
                    <p>Es muy fácil, en solo 3 pasos</p>
                </div>

                <div class="welcome-handwritten-note" aria-label="Tu confianza también nos mueve">
                    <span>Tu confianza</span>
                    <span>también nos mueve.</span>
                    <svg viewBox="0 0 150 28" aria-hidden="true">
                        <path d="M4 20C33 25 75 18 103 8c13-5 26-5 42-2" />
                    </svg>
                </div>

                <div class="welcome-how-steps">
                    <article>
                        <span class="welcome-how-step-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="9" cy="7.5" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M3.25 19a5.75 5.75 0 0 1 11.5 0M18.25 8v6m-3-3h6"/></svg>
                        </span>
                        <h3><strong>1.</strong> Crea tu cuenta</h3>
                        <p>Regístrate en segundos<br>y configura tu perfil.</p>
                    </article>
                    <span class="welcome-how-arrow" aria-hidden="true">→</span>
                    <article>
                        <span class="welcome-how-step-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><circle cx="8" cy="7.5" r="3"/><circle cx="17" cy="9" r="2.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.5 19a5.5 5.5 0 0 1 11 0M14 14.75A4.5 4.5 0 0 1 21.5 19"/></svg>
                        </span>
                        <h3><strong>2.</strong> Construye tu flota de confianza</h3>
                        <p>Agrega conductores que conoces<br>o descubre nuevos por tu zona.</p>
                    </article>
                    <span class="welcome-how-arrow" aria-hidden="true">→</span>
                    <article>
                        <span class="welcome-how-step-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.5 16.25h17l-2.65-6.4a2 2 0 0 0-1.85-1.23H8a2 2 0 0 0-1.85 1.23l-2.65 6.4Z"/><path stroke-linecap="round" d="M5.5 16.25v2.15m13-2.15v2.15M7.25 13h9.5"/></svg>
                        </span>
                        <h3><strong>3.</strong> Pide tu carrera a quien elijas</h3>
                        <p>Se la solicitas directo a él — Arka01<br>no asigna conductores, solo los conecta.</p>
                    </article>
                </div>

                <div id="para-quienes" class="welcome-platform scroll-mt-24">
                    <div class="welcome-platform__heading">
                        <div>
                            <h2>Una plataforma para todos</h2>
                            <p>Conectamos personas, conductores y cooperativas en un mismo ecosistema — empieza hoy, totalmente gratis.</p>
                        </div>
                        <Link v-if="canRegister" :href="route('register')">Conoce más <span>→</span></Link>
                    </div>

                    <div class="welcome-platform__grid">
                        <article class="welcome-role-card">
                            <div class="welcome-role-card__visual welcome-role-card__visual--passengers">
                                <img src="/img/home/pasajera.png" alt="Pasajera usando Arka01 desde su teléfono" loading="lazy" decoding="async" />
                            </div>
                            <div class="welcome-role-card__body">
                                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="9" cy="7.5" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M3.25 19a5.75 5.75 0 0 1 11.5 0M18.25 8v6m-3-3h6"/></svg></span>
                                <div><h3>Pasajeros</h3><p>Viaja con quienes confías y mira la tarifa exacta antes de aceptar, sin sorpresas.</p></div>
                            </div>
                        </article>
                        <article class="welcome-role-card">
                            <div class="welcome-role-card__visual welcome-role-card__visual--drivers">
                                <img src="/img/home/conductor.png" alt="Conductor de Arka01 dentro de su vehículo" loading="lazy" decoding="async" />
                            </div>
                            <div class="welcome-role-card__body">
                                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><circle cx="12" cy="12" r="7.5"/><circle cx="12" cy="12" r="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M5 10.25h14M12 14v5.5M7.1 10.25 10.6 14m6.3-3.75L13.4 14"/></svg></span>
                                <div><h3>Conductores</h3><p>Cero comisiones por viaje: te quedas con el 100% de lo que cobras.</p></div>
                            </div>
                        </article>
                        <article class="welcome-role-card">
                            <div class="welcome-role-card__visual welcome-role-card__visual--cooperatives">
                                <img src="/img/home/cooperativa.png" alt="Administrador gestionando una cooperativa en Arka01" loading="lazy" decoding="async" />
                            </div>
                            <div class="welcome-role-card__body">
                                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><circle cx="12" cy="7" r="2.6"/><circle cx="5.75" cy="9" r="2"/><circle cx="18.25" cy="9" r="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M7.25 19a4.75 4.75 0 0 1 9.5 0M2.5 18a3.5 3.5 0 0 1 4.75-3.3M21.5 18a3.5 3.5 0 0 0-4.75-3.3"/></svg></span>
                                <div><h3>Cooperativas</h3><p>Gestiona tu flota sin comisiones por viaje: un plan fijo, sin descuentos por carrera.</p></div>
                            </div>
                        </article>
                        <article class="welcome-role-card">
                            <div class="welcome-role-card__visual welcome-role-card__visual--coordinators">
                                <img src="/img/home/coordinador.png" alt="Coordinadora supervisando viajes desde Arka01" loading="lazy" decoding="async" />
                            </div>
                            <div class="welcome-role-card__body">
                                <span><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="4" y="12" width="3.5" height="7" rx="1"/><rect x="10.25" y="7" width="3.5" height="12" rx="1"/><rect x="16.5" y="3.5" width="3.5" height="15.5" rx="1"/></svg></span>
                                <div><h3>Coordinadores</h3><p>Supervisa cada carrera en tiempo real y optimiza la operación desde un solo panel.</p></div>
                            </div>
                        </article>
                    </div>
                </div>
            </div>
            </section>

            <!-- Las cooperativas forman parte de la red, pero conservamos el
                 bloque principal original limpio y fácil de recorrer. -->
            <div class="mt-8 flex flex-col items-center gap-4 rounded-arka border border-arka-primary/30 bg-arka-card p-5 text-center shadow md:hidden sm:flex-row sm:px-6 sm:text-start">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-arka-primary/15">
                    <svg class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 20V8l8-4 8 4v12M8 20v-4h8v4M8 10h.01M12 10h.01M16 10h.01" />
                    </svg>
                </span>
                <div class="flex-1">
                    <p class="text-sm font-semibold text-arka-text">Para cooperativas</p>
                    <p class="mt-1 text-xs leading-5 text-arka-text-muted">Organice sus unidades verificadas, reciba solicitudes y asigne cada carrera desde su propia central de despacho.</p>
                </div>
                <Link
                    v-if="canRegister"
                    :href="route('register')"
                    class="inline-flex shrink-0 items-center gap-1.5 text-sm font-semibold text-arka-primary hover:text-arka-primary-bright"
                >
                    Soy cooperativa
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6" />
                    </svg>
                </Link>
            </div>

            <!-- ¿Por qué elegir Arka01? -->
            <div class="mt-16 scroll-mt-24 md:hidden">
                <h2 class="mx-auto mb-2 max-w-2xl text-center text-2xl font-semibold leading-tight text-arka-text sm:text-3xl">
                    Más que encontrar un viaje.
                    <span class="block text-arka-primary">Construye una red para los próximos.</span>
                </h2>
                <p class="mx-auto mb-8 max-w-xl text-center text-sm leading-6 text-arka-text-muted">
                    Cinco formas en las que Arka01 convierte cada experiencia en más confianza y mejores conexiones.
                </p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <div v-for="item in WHY_ARKA01" :key="item.title" class="rounded-arka border border-arka-border bg-arka-card/50 p-4 text-center">
                        <span class="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-full bg-arka-primary/15">
                            <svg v-if="item.title === 'Relaciones que continúan'" class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.5 12.5 11 15a2 2 0 0 0 2.8 0l5.4-5.4a2.5 2.5 0 0 0-3.5-3.5L14 7.8l-1.7-1.7a2.5 2.5 0 0 0-3.5 0L7.4 7.5" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="m3.5 9 3.7 3.7a2 2 0 0 0 2.8 0l2.3-2.3M2 7l3-3 3 3-3 3-3-3Zm14 10 3-3 3 3-3 3-3-3Z" />
                            </svg>
                            <svg v-else-if="item.title === 'Tú eliges con quién viajar'" class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16h16l-2-6.2a2 2 0 0 0-1.9-1.4H7.9A2 2 0 0 0 6 9.8L4 16Zm2 0v2m12-2v2" />
                                <circle cx="8" cy="15.5" r="1" fill="currentColor" stroke="none" /><circle cx="16" cy="15.5" r="1" fill="currentColor" stroke="none" />
                            </svg>
                            <svg v-else-if="item.title === 'Confianza visible'" class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m12 3 7 3v5c0 4-2.6 7-7 9-4.4-2-7-5-7-9V6l7-3Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="m9 11 2 2 4-4" />
                            </svg>
                            <svg v-else-if="item.title === 'Una red que crece contigo'" class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <circle cx="12" cy="5" r="2.5" /><circle cx="5" cy="17" r="2.5" /><circle cx="19" cy="17" r="2.5" />
                                <path stroke-linecap="round" d="m10.7 7.2-4.4 7.6m7-7.6 4.4 7.6M7.5 17h9" />
                            </svg>
                            <svg v-else class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <rect x="5" y="3" width="10" height="18" rx="2" /><path stroke-linecap="round" d="M8.5 17.5h3" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14 7h6v7h-2.5L15 16v-2h-1V7Z" />
                            </svg>
                        </span>
                        <p class="text-sm font-semibold leading-5 text-arka-text">{{ item.title }}</p>
                        <p class="mt-1 text-xs leading-5 text-arka-text-muted">{{ item.text }}</p>
                    </div>
                </div>
                <p class="mt-8 text-center text-base font-medium text-arka-text">
                    Cada viaje puede ser el comienzo de la
                    <span class="text-arka-primary">próxima conexión.</span>
                </p>
            </div>
            <!-- "Ayúdanos a mejorar ARKA01" (roadmap de mejoras, sección 14): barra
                 discreta, el formulario en sí vive en un modal. -->
            <button
                type="button"
                class="mt-16 w-full flex items-center gap-3 p-4 bg-arka-card shadow rounded-arka text-start hover:bg-arka-card/70 transition"
                @click="showingFeedback = true"
            >
                <span class="h-10 w-10 rounded-full bg-arka-primary/15 flex items-center justify-center shrink-0">
                    <svg class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 5.5h16v10.5H8.5L4 20V5.5Z" />
                    </svg>
                </span>
                <span class="flex-1 min-w-0">
                    <span class="block text-sm font-medium text-arka-text">Ayúdanos a mejorar Arka01</span>
                    <span class="block text-xs text-arka-text-muted">Su opinión nos ayuda a construir una mejor experiencia.</span>
                </span>
                <span class="shrink-0 px-4 py-2 rounded-arka bg-arka-primary text-white text-xs font-semibold uppercase tracking-wide">
                    Enviar sugerencia
                </span>
            </button>

            <!-- Footer con redes sociales (pedido explícito del usuario) — mismo
                 componente que usan GuestLayout.vue y Survey/Show.vue. -->
            <div id="contacto" class="mt-10 flex scroll-mt-24 flex-col items-center gap-4">
                <SocialLinks />
                <p class="text-center text-xs text-arka-text-muted">
                    <Link href="/terminos" class="hover:text-arka-primary-bright">Términos</Link>
                    <span class="mx-2">·</span>
                    <Link href="/privacidad" class="hover:text-arka-primary-bright">Privacidad</Link>
                </p>
            </div>
        </div>

        <Modal :show="showingFeedback" max-width="md" @close="showingFeedback = false">
            <div class="p-6">
                <h3 class="text-lg font-medium text-arka-text mb-1">Ayúdanos a mejorar Arka01</h3>
                <p class="text-sm text-arka-text-muted mb-4">Su opinión nos ayuda a construir una mejor experiencia.</p>

                <form v-if="!feedbackForm.recentlySuccessful" @submit.prevent="submitFeedback" class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <input
                            v-model="feedbackForm.name"
                            type="text"
                            placeholder="Nombre (opcional)"
                            class="rounded-arka border-arka-border bg-transparent text-arka-text text-sm placeholder:text-arka-text-muted"
                        />
                        <input
                            v-model="feedbackForm.email"
                            type="email"
                            placeholder="Correo (opcional)"
                            class="rounded-arka border-arka-border bg-transparent text-arka-text text-sm placeholder:text-arka-text-muted"
                        />
                    </div>
                    <SearchableSelect v-model="feedbackForm.type" :options="FEEDBACK_TYPES" placeholder="Tipo" />
                    <textarea
                        v-model="feedbackForm.comment"
                        rows="3"
                        required
                        placeholder="Su comentario"
                        class="block w-full rounded-arka border-arka-border bg-transparent text-arka-text text-sm placeholder:text-arka-text-muted"
                    ></textarea>
                    <p v-if="feedbackForm.errors.comment" class="text-xs text-arka-danger">{{ feedbackForm.errors.comment }}</p>
                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            class="px-4 py-2 rounded-arka text-sm text-arka-text-muted hover:text-arka-text"
                            @click="showingFeedback = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="feedbackForm.processing"
                            class="inline-flex items-center justify-center px-4 py-2 bg-arka-primary rounded-arka font-semibold text-sm text-white hover:bg-arka-primary-bright transition disabled:opacity-50"
                        >
                            Enviar opinión
                        </button>
                    </div>
                </form>
                <div v-else class="text-center py-4">
                    <p class="text-sm text-arka-primary-bright">¡Gracias! Ya recibimos su opinión.</p>
                    <button type="button" class="mt-4 text-sm text-arka-text-muted hover:text-arka-text" @click="showingFeedback = false">
                        Cerrar
                    </button>
                </div>
            </div>
        </Modal>

        <!-- CTA de bienvenida: aparece una sola vez por semana y solo a
             visitantes sin sesión. Su apertura y el clic principal se miden
             por separado para distinguir alcance de intención real. -->
        <Modal :show="showingWelcomeCta" max-width="md" @close="closeWelcomeCta">
            <div class="relative overflow-hidden p-6 sm:p-7">
                <input v-model="ctaHoneypot" type="text" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true" />
                <div class="pointer-events-none absolute -end-16 -top-20 h-48 w-48 rounded-full bg-arka-primary/10 blur-2xl"></div>

                <button type="button" class="absolute end-4 top-4 grid h-9 w-9 place-items-center rounded-full border border-arka-border text-arka-text-muted transition hover:border-arka-primary/40 hover:text-arka-primary" aria-label="Cerrar" @click="closeWelcomeCta">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="m6 6 12 12M18 6 6 18" /></svg>
                </button>

                <div class="relative">
                    <span class="grid h-12 w-12 place-items-center rounded-2xl border border-arka-primary/25 bg-arka-primary/10 text-arka-primary">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <circle cx="7" cy="8" r="3" />
                            <circle cx="17" cy="8" r="3" />
                            <path stroke-linecap="round" d="M2.5 19a4.5 4.5 0 0 1 9 0M12.5 19a4.5 4.5 0 0 1 9 0M10 11.5h4" />
                        </svg>
                    </span>
                    <p class="mt-5 text-xs font-bold uppercase tracking-[0.18em] text-arka-primary">Movilidad basada en confianza</p>
                    <h2 class="mt-2 max-w-sm text-2xl font-bold leading-tight text-arka-text">No elija un viaje a ciegas. Construya su propia red.</h2>
                    <p class="mt-3 text-sm leading-6 text-arka-text-muted">
                        Conecte con conductores, clientes y cooperativas verificadas. Vea su reputación, encuentre relaciones en común y mantenga el control de cada viaje.
                    </p>

                    <div class="mt-5 grid grid-cols-3 gap-2">
                        <div v-for="item in ['Su círculo', 'Índice de confianza', 'Viaje acompañado']" :key="item" class="rounded-xl border border-arka-border bg-arka-base/50 px-2 py-3 text-center text-[11px] font-medium leading-4 text-arka-text-muted">
                            {{ item }}
                        </div>
                    </div>

                    <button type="button" class="mt-6 flex w-full items-center justify-center gap-2 rounded-arka bg-arka-primary px-5 py-3.5 text-sm font-bold uppercase tracking-wide text-white shadow-lg shadow-arka-primary/15 transition hover:bg-arka-primary-bright" @click="followWelcomeCta">
                        Crear mi cuenta
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" /></svg>
                    </button>
                    <button v-if="canLogin" type="button" class="mt-3 w-full text-center text-sm font-medium text-arka-text-muted hover:text-arka-primary" @click="goToLogin">
                        Ya tengo una cuenta
                    </button>
                    <p class="mt-4 text-center text-[10px] leading-4 text-arka-text-muted/80">Sin comisiones ocultas por viaje. Usted decide con quién conectarse.</p>
                </div>
            </div>
        </Modal>

        <Modal :show="showingGuestIdentity" max-width="md" @close="showingGuestIdentity = false">
            <form class="p-6" @submit.prevent="submitGuestRide">
                <input v-model="guestForm.website" type="text" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true" />
                <div class="mx-auto mb-3 h-1 w-12 rounded-full bg-arka-text-muted/30 sm:hidden"></div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-arka-primary">Último paso</p>
                <h3 class="mt-1 text-xl font-bold text-arka-text">¿A nombre de quién pedimos?</h3>
                <p class="mt-1 text-sm text-arka-text-muted">La cooperativa necesita estos datos para identificarle y contactarle.</p>

                <div class="mt-5 space-y-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-arka-text">Nombre</label>
                        <input v-model="guestForm.name" type="text" autocomplete="name" placeholder="Ej. María López" class="w-full rounded-arka border-arka-border bg-transparent text-arka-text placeholder:text-arka-text-muted focus:border-arka-primary focus:ring-arka-primary" />
                        <p v-if="guestForm.errors.name" class="mt-1 text-xs text-arka-danger">{{ guestForm.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-arka-text">Número de WhatsApp</label>
                        <div class="grid grid-cols-[7rem_1fr] gap-2">
                            <SearchableSelect v-model="guestForm.country_code" :options="[{value: '+593', label: '🇪🇨 +593'}, {value: '+51', label: '🇵🇪 +51'}, {value: '+57', label: '🇨🇴 +57'}, {value: '+58', label: '🇻🇪 +58'}, {value: '+56', label: '🇨🇱 +56'}, {value: '+54', label: '🇦🇷 +54'}]" />
                            <input v-model="guestForm.phone_local" type="tel" inputmode="numeric" autocomplete="tel-national" placeholder="999 000 222" class="min-w-0 rounded-arka border-arka-border bg-transparent text-arka-text placeholder:text-arka-text-muted focus:border-arka-primary focus:ring-arka-primary" />
                        </div>
                        <p v-if="guestForm.errors.phone_local" class="mt-1 text-xs text-arka-danger">{{ guestForm.errors.phone_local }}</p>
                        <p v-if="showsAccountExistsError" class="mt-1 text-sm">
                            <Link :href="route('login')" class="text-arka-primary hover:text-arka-primary-bright font-medium">
                                Iniciar sesión →
                            </Link>
                        </p>
                    </div>
                    <div class="rounded-arka bg-arka-primary/10 p-3 text-xs leading-relaxed text-arka-text-muted">
                        Recibirá un código corto por WhatsApp. No necesita correo ni crear una contraseña.
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-[auto_1fr] gap-2">
                    <button type="button" class="rounded-arka border border-arka-text-muted/25 px-4 py-3 text-sm font-semibold text-arka-text" @click="showingGuestIdentity = false">Volver</button>
                    <button type="submit" :disabled="guestForm.processing" class="rounded-arka bg-arka-primary px-4 py-3 text-sm font-bold uppercase tracking-wide text-white hover:bg-arka-primary-bright disabled:opacity-50">
                        {{ guestForm.processing ? 'Preparando…' : 'Continuar seguro' }}
                    </button>
                </div>
            </form>
        </Modal>
    </div>
</template>

<style scoped>
.welcome-nav {
    border: 1px solid rgb(var(--arka-border) / .66);
    background: rgb(var(--arka-card) / .90);
    box-shadow: 0 6px 22px rgba(11, 54, 38, .06);
    -webkit-backdrop-filter: blur(14px);
    backdrop-filter: blur(14px);
}

.welcome-hero {
    min-height: min(720px, calc(100svh - 7rem));
    background: #061a13;
    box-shadow: 0 12px 34px rgba(6, 35, 24, .13);
}

.welcome-hero__image {
    /* La fotografía conserva sus negros y verdes originales. El contenido ya
       está protegido por tarjetas propias, así que no necesita una capa clara. */
    filter: saturate(1.08) contrast(1.06) brightness(1.02);
    transform: scale(1.01);
}

.welcome-hero__image--mobile {
    background-position: center top;
    filter: saturate(1.05) contrast(1.04) brightness(.98);
    transform: none;
}

.welcome-hero__content {
    position: relative;
    isolation: isolate;
    -webkit-font-smoothing: antialiased;
    text-rendering: geometricPrecision;
}

.welcome-hero__journey {
    position: relative;
    z-index: 2;
}

.welcome-or-divider,
.welcome-mobile-how {
    display: none;
}

/* Bug real reportado por el usuario ("cuando le doy a dónde vamos se daña el
   diseño"): al expandir la solicitud rápida en un ancho de escritorio, la
   tarjeta perdía el layout especial de fila colapsada y crecía más alto que
   el hero (que recorta con overflow-hidden para encuadrar la foto de fondo),
   quedando cortada y superpuesta con la maqueta del teléfono. `position:
   fixed` la saca del recorte del hero (no lo atrapa un overflow-hidden en un
   ancestro) y la centra como un panel flotante — mismo tratamiento que ya
   funcionaba bien en el celular, ahora sin restringirlo a esa pantalla. */
.welcome-quick-backdrop {
    position: fixed;
    inset: 0;
    z-index: 50;
    display: block;
    width: 100%;
    height: 100%;
    border: 0;
    background: rgba(1,18,13,.48);
    -webkit-backdrop-filter: blur(3px);
    backdrop-filter: blur(3px);
}

.welcome-quick-card--expanded {
    /* Bug real reportado por el usuario ("quitale al modal ese espacio en
       blanco allí"): con top Y bottom fijos, el modal siempre estiraba su
       alto hasta el borde de la pantalla aunque el formulario fuera mucho
       más corto — el hueco de sobra quedaba en blanco debajo del botón
       "Consultar tarifa". `inset` + `margin: auto` centra un panel de alto
       automático (se ajusta a su contenido) en vez de forzarlo a llenar el
       viewport; `max-height` sigue ahí solo para activar el scroll interno
       cuando el contenido (ej. la lista larga de cooperativas) sí es alta. */
    position: fixed;
    inset: max(.75rem, env(safe-area-inset-top)) .75rem max(.75rem, env(safe-area-inset-bottom));
    z-index: 60;
    display: flex;
    flex-direction: column;
    width: auto;
    height: fit-content;
    max-width: 24rem;
    max-height: calc(100dvh - 1.5rem);
    overflow: hidden;
    margin: auto;
    border-radius: 1.25rem;
    box-shadow: 0 22px 60px rgba(0,18,13,.38);
    overscroll-behavior: contain;
}

.welcome-quick-card--expanded > button:first-of-type {
    position: sticky;
    top: 0;
    z-index: 2;
    flex: 0 0 auto;
    background: rgba(255,255,255,.98);
}

.welcome-quick-card--expanded #guest-ride-form {
    min-height: 0;
    flex: 1 1 auto;
    overflow-x: hidden;
    overflow-y: auto;
    padding-bottom: max(1.25rem, env(safe-area-inset-bottom));
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
    touch-action: pan-y;
}

/* Zona de luz integrada: no tiene borde, esquinas ni sombra de tarjeta. El
   blanco desaparece gradualmente y deja que la fotografía continúe alrededor. */
.welcome-hero__content::before {
    position: absolute;
    inset: -1rem -1.35rem;
    z-index: -1;
    content: '';
    pointer-events: none;
    background:
        radial-gradient(
            ellipse at 46% 44%,
            rgba(255, 255, 255, .99) 0%,
            rgba(243, 255, 249, .97) 38%,
            rgba(218, 248, 234, .86) 59%,
            rgba(87, 205, 155, .34) 78%,
            transparent 94%
        ),
        radial-gradient(ellipse at 48% 52%, rgba(52, 211, 153, .28), transparent 76%);
    filter: blur(8px);
}

.welcome-hero__title {
    text-shadow:
        0 1px 0 rgba(255, 255, 255, .72),
        0 3px 12px rgba(5, 35, 23, .08);
}

:global(html.dark) .welcome-hero__image {
    filter: saturate(1.1) contrast(1.07) brightness(.96);
}

:global(html.dark) .welcome-nav {
    background: rgb(var(--arka-card) / .88);
    box-shadow: 0 8px 24px rgba(0, 0, 0, .18);
}

:global(html.dark) .welcome-hero__content {
    text-shadow: 0 1px 2px rgba(0, 0, 0, .16);
}

:global(html.dark) .welcome-hero__content::before {
    background:
        radial-gradient(
            ellipse at 46% 44%,
            rgba(18, 35, 27, .96) 0%,
            rgba(15, 36, 27, .90) 45%,
            rgba(25, 106, 76, .52) 68%,
            rgba(52, 211, 153, .18) 82%,
            transparent 94%
        ),
        radial-gradient(ellipse at 48% 52%, rgba(52, 211, 153, .22), transparent 76%);
}

:global(html.dark) .welcome-hero__title {
    text-shadow: 0 3px 14px rgba(0, 0, 0, .34);
}

@media (min-width: 768px) {
    /* Textura exterior suave: da profundidad al lienzo que rodea el hero sin
       competir con la fotografía ni convertir el fondo en otra tarjeta. */
    .arka-app-background {
        background-color: #e8f2ee;
        background-image:
            radial-gradient(circle at 14% 10%, rgba(255,255,255,.94) 0, rgba(255,255,255,.56) 19rem, transparent 39rem),
            radial-gradient(circle at 88% 18%, rgba(79,196,151,.16) 0, transparent 33rem),
            linear-gradient(118deg, transparent 0 47%, rgba(255,255,255,.32) 47.1% 47.25%, transparent 47.35% 100%),
            repeating-linear-gradient(132deg, rgba(7,111,76,.024) 0 1px, transparent 1px 18px);
        background-attachment: fixed;
    }

    :global(html.dark) .arka-app-background {
        background-color: #061a14;
        background-image:
            radial-gradient(circle at 14% 10%, rgba(29,109,82,.22) 0, transparent 35rem),
            radial-gradient(circle at 88% 18%, rgba(27,186,127,.12) 0, transparent 31rem),
            linear-gradient(118deg, transparent 0 47%, rgba(93,232,181,.045) 47.1% 47.25%, transparent 47.35% 100%),
            repeating-linear-gradient(132deg, rgba(129,230,192,.026) 0 1px, transparent 1px 18px);
    }

    .welcome-hero {
        /* Pedido explícito del usuario ("la imagen de fondo... se ve como
           muy cerca"): la foto es cuadrada (1254×1254) contra un hero mucho
           más ancho que alto — con "cover" eso obliga a recortar casi la
           mitad de su alto para llenar el ancho, mostrando solo una franja
           muy acercada. Un hero un poco más alto deja ver más foto sin
           recortar tanto, "alejándola" sin tocar el zoom/posición del
           recorte. Pedido explícito del usuario ("quita un poco ese aire
           para evitar que baje tanto en escritorio"): subirlo de una a
           700px empujaba demasiado el resto de la página hacia abajo —
           660px es un aumento más discreto sobre el valor original (620px). */
        min-height: 660px;
        grid-template-columns: minmax(0, .92fr) minmax(0, 1.08fr);
        grid-template-rows: 1fr auto;
        padding: 2.6rem 2.5rem 1.15rem;
        border-color: rgba(28, 163, 115, .22);
        border-radius: 1.6rem;
        background: #f4fcf8;
        box-shadow: 0 18px 50px rgba(10, 82, 57, .10);
        /* Las sugerencias de origen/destino deben poder salir de la franja
           inferior. El recorte queda aplicado a la foto, no al formulario. */
        z-index: 2;
        overflow: visible;
    }

    .welcome-hero::after {
        position: absolute;
        inset: 0;
        z-index: -10;
        content: '';
        pointer-events: none;
        border-radius: inherit;
        background: linear-gradient(90deg, rgba(250,255,252,.99) 0%, rgba(247,255,251,.96) 31%, rgba(245,255,250,.76) 42%, rgba(245,255,250,.16) 56%, transparent 68%);
    }

    .welcome-hero__image--desktop {
        /* El hero deja salir el dropdown, pero la fotografía no puede usar el
           scale general porque mostraría fuera del bloque el logo impreso en
           la propia imagen. */
        border-radius: inherit;
        background-position: center 53%;
        background-size: cover;
        background-repeat: no-repeat;
        filter: saturate(1.04) contrast(1.02) brightness(1.02);
        transform: none;
    }

    .welcome-hero__content {
        grid-column: 1;
        z-index: 1;
        max-width: 34rem;
        padding: 0;
    }

    .welcome-hero__content::before {
        inset: -1.3rem -3rem -1.3rem -1.5rem;
        background: radial-gradient(ellipse at 34% 48%, rgba(255,255,255,.94) 0%, rgba(240,255,248,.72) 48%, transparent 79%);
        filter: blur(10px);
    }

    .welcome-hero__content > p:first-child {
        padding: .35rem .72rem;
        border: 1px solid rgba(19,143,100,.12);
        background: rgba(224,247,238,.82);
        color: #087653;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.7);
    }

    .welcome-hero__title {
        margin-top: 1.25rem;
        color: #071b14;
        font-size: clamp(3rem, 4.2vw, 4rem);
        line-height: .98;
        letter-spacing: -.04em;
        text-shadow: 0 2px 14px rgba(255,255,255,.34);
    }

    .welcome-hero__title + p {
        max-width: 28rem;
        color: #40564e;
        line-height: 1.45;
    }

    .welcome-desktop-actions > a {
        border-radius: .7rem;
        box-shadow: 0 8px 20px rgba(6,113,75,.12), inset 0 1px 0 rgba(255,255,255,.28);
        transition: transform .2s ease, box-shadow .2s ease, background-color .2s ease;
    }

    .welcome-desktop-actions > a:hover {
        transform: translateY(-1px);
        box-shadow: 0 12px 24px rgba(6,113,75,.18), inset 0 1px 0 rgba(255,255,255,.34);
    }

    .welcome-desktop-actions > a:last-child {
        background: rgba(255,255,255,.84);
        box-shadow: 0 6px 18px rgba(6,80,55,.07), inset 0 1px 0 rgba(255,255,255,.8);
        -webkit-backdrop-filter: blur(10px);
        backdrop-filter: blur(10px);
    }

    .welcome-desktop-benefits > li {
        display: grid;
        grid-template-columns: 2.25rem 1fr;
        align-items: center;
        gap: .55rem;
        min-width: 0;
        color: #17372c;
    }

    .welcome-desktop-benefits > li > span {
        display: flex;
        width: 2.25rem;
        height: 2.25rem;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(15,143,98,.18);
        border-radius: 999px;
        background: rgba(218,246,234,.88);
        color: #07835b;
        box-shadow: 0 5px 14px rgba(5,102,69,.09), inset 0 1px 0 rgba(255,255,255,.8);
    }

    .welcome-desktop-benefits svg {
        width: 1.05rem;
        height: 1.05rem;
    }

    .welcome-desktop-benefits p {
        font-size: .68rem;
        font-weight: 700;
        line-height: .86rem;
    }

    .welcome-survey-link {
        display: none;
    }

    .welcome-hero__journey {
        grid-column: 1 / -1;
        width: 100%;
        max-width: none;
        margin-top: 1.1rem;
    }

    .welcome-quick-card:not(.welcome-quick-card--expanded) {
        display: grid;
        grid-template-columns: minmax(11.5rem, .78fr) minmax(11rem, 1fr) 2rem minmax(11rem, 1fr) 8.5rem;
        align-items: center;
        gap: .5rem;
        padding: .55rem;
        border: 1px solid rgba(14,139,96,.30);
        border-radius: 1rem;
        background: rgba(255,255,255,.94);
        box-shadow: 0 12px 32px rgba(5,73,50,.15), inset 0 1px 0 rgba(255,255,255,.9);
        -webkit-backdrop-filter: blur(14px);
        backdrop-filter: blur(14px);
    }

    .welcome-quick-card:not(.welcome-quick-card--expanded) > button:first-of-type {
        min-height: 3.1rem;
        padding: .25rem .65rem;
        border-radius: .7rem;
        cursor: default;
    }

    .welcome-quick-card:not(.welcome-quick-card--expanded) > button:first-of-type > span {
        display: none;
    }

    .welcome-quick-card:not(.welcome-quick-card--expanded) h2 {
        font-size: 1rem;
        line-height: 1.1rem;
    }

    .welcome-quick-card:not(.welcome-quick-card--expanded) h2 + p {
        display: none;
    }

    .welcome-desktop-field {
        position: relative;
    }

    .welcome-desktop-field :deep(input) {
        min-height: 3rem;
        border: 1px solid rgba(29,109,81,.10);
        border-radius: .65rem;
        background: rgba(239,247,243,.90);
        padding-inline-start: 2.55rem;
        font-size: .72rem;
        box-shadow: none;
    }

    .welcome-desktop-field :deep(input:focus) {
        border-color: rgba(13,143,97,.42);
        background: rgba(255,255,255,.97);
        box-shadow: 0 0 0 3px rgba(13,143,97,.09);
    }

    .welcome-desktop-field-error {
        position: absolute;
        top: calc(100% + .72rem);
        left: .3rem;
        z-index: 5;
        max-width: 100%;
        padding: .3rem .5rem;
        border-radius: .4rem;
        background: #fff3f3;
        color: #b42318;
        font-size: .58rem;
        line-height: .8rem;
        box-shadow: 0 5px 14px rgba(82,20,20,.12);
    }

    .welcome-desktop-swap {
        width: 2rem;
        height: 2rem;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        color: #45645a;
        transition: color .2s ease, background-color .2s ease, transform .2s ease;
    }

    .welcome-desktop-swap:hover {
        background: rgba(13,143,97,.10);
        color: #07835b;
        transform: rotate(180deg);
    }

    .welcome-desktop-swap svg {
        width: 1rem;
        height: 1rem;
    }

    .welcome-quick-submit-preview {
        min-height: 3rem;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(44,211,151,.35);
        border-radius: .65rem;
        background: linear-gradient(135deg, #098a60, #06734f);
        color: white;
        font-size: .72rem;
        font-weight: 800;
        box-shadow: 0 7px 18px rgba(4,112,75,.20), inset 0 1px 0 rgba(255,255,255,.18);
    }

    .welcome-desktop-coop-error {
        position: absolute;
        right: .65rem;
        bottom: -1.55rem;
        z-index: 5;
        padding: .25rem .5rem;
        border-radius: .4rem;
        background: #fff3f3;
        color: #b42318;
        font-size: .6rem;
        box-shadow: 0 5px 14px rgba(82,20,20,.12);
    }

    #como-funciona {
        position: relative;
        z-index: 1;
        width: 100vw;
        margin-top: 0 !important;
        margin-left: calc(50% - 50vw);
        padding-top: 2.15rem;
        background: #ffffff;
        box-shadow: none;
    }

    .welcome-desktop-story {
        position: relative;
        color: #10251e;
    }

    .welcome-handwritten-note {
        position: absolute;
        top: -.25rem;
        right: max(2.4rem, calc((100vw - 72rem) / 2 + 2.4rem));
        z-index: 2;
        display: flex;
        width: 9.75rem;
        flex-direction: column;
        color: #42665b;
        font-family: "Segoe Print", "Bradley Hand", "Snell Roundhand", cursive;
        font-size: .88rem;
        font-style: italic;
        font-weight: 600;
        line-height: 1.05rem;
        text-align: center;
        letter-spacing: -.035em;
        transform: rotate(-6deg);
    }

    .welcome-handwritten-note span:last-of-type {
        margin-left: 1.25rem;
    }

    .welcome-handwritten-note svg {
        width: 7.4rem;
        height: 1.45rem;
        margin: -.15rem 0 0 1.25rem;
        overflow: visible;
        fill: none;
        stroke: #15966c;
        stroke-width: 1.6;
        stroke-linecap: round;
    }

    @media (max-width: 1023px) {
        .welcome-handwritten-note {
            display: none;
        }
    }

    .welcome-how-heading > span {
        display: block;
        width: 2.5rem;
        height: 2px;
        margin: 0 auto .65rem;
        border-radius: 999px;
        background: linear-gradient(90deg, #45d9a4, #07845b);
    }

    .welcome-how-heading h2 {
        font-size: 1.75rem;
        font-weight: 800;
        line-height: 1;
        letter-spacing: -.025em;
    }

    .welcome-how-heading p {
        margin-top: .45rem;
        color: #63766f;
        font-size: .75rem;
    }

    .welcome-how-steps {
        display: grid;
        grid-template-columns: 1fr 2.5rem 1fr 2.5rem 1fr;
        align-items: center;
        max-width: 64rem;
        margin: 1.8rem auto 2.4rem;
    }

    .welcome-how-steps article {
        text-align: center;
    }

    .welcome-how-step-icon {
        display: flex;
        width: 3.15rem;
        height: 3.15rem;
        align-items: center;
        justify-content: center;
        margin: 0 auto .65rem;
        border: 1px solid rgba(54,220,160,.45);
        border-radius: 999px;
        background: linear-gradient(145deg, #0a9367, #06704e);
        color: white;
        box-shadow: 0 9px 20px rgba(5,113,76,.17), inset 0 1px 0 rgba(255,255,255,.25);
    }

    .welcome-how-step-icon svg {
        width: 1.45rem;
        height: 1.45rem;
    }

    .welcome-how-steps h3 {
        font-size: .9rem;
        font-weight: 750;
    }

    .welcome-how-steps h3 strong {
        color: #087c57;
    }

    .welcome-how-steps article > p {
        margin-top: .35rem;
        color: #62736d;
        font-size: .7rem;
        line-height: 1.15rem;
    }

    .welcome-how-arrow {
        color: #079368;
        font-size: 1.75rem;
        font-weight: 300;
        text-align: center;
    }

    .welcome-platform {
        position: relative;
        width: 100vw;
        margin-left: calc(50% - 50vw);
        padding: 1.65rem max(2rem, calc((100vw - 72rem) / 2 + 2rem)) 2.5rem;
        overflow: hidden;
        background:
            radial-gradient(circle at 12% 20%, rgba(12,150,105,.22), transparent 34%),
            radial-gradient(circle at 88% 84%, rgba(0,109,89,.20), transparent 30%),
            linear-gradient(135deg, #06251f, #031b1b 58%, #052d2d);
        color: white;
    }

    .welcome-platform::before {
        position: absolute;
        inset: 0;
        content: '';
        pointer-events: none;
        opacity: .16;
        background-image: linear-gradient(120deg, transparent 42%, rgba(47,255,185,.22) 42.2%, transparent 42.5%);
    }

    .welcome-platform__heading {
        position: relative;
        z-index: 1;
        display: flex;
        align-items: end;
        justify-content: space-between;
        gap: 2rem;
        margin-bottom: 1.15rem;
        text-align: center;
    }

    .welcome-platform__heading > div {
        flex: 1;
        padding-left: 7rem;
    }

    .welcome-platform__heading h2 {
        font-size: 1.35rem;
        font-weight: 750;
    }

    .welcome-platform__heading p {
        margin-top: .25rem;
        color: rgba(231,255,247,.72);
        font-size: .68rem;
    }

    .welcome-platform__heading > a {
        display: inline-flex;
        min-height: 2.25rem;
        align-items: center;
        gap: .75rem;
        padding: 0 1rem;
        border: 1px solid rgba(220,255,244,.55);
        border-radius: .5rem;
        color: white;
        font-size: .68rem;
        font-weight: 700;
        transition: border-color .2s ease, background-color .2s ease;
    }

    .welcome-platform__heading > a:hover {
        border-color: #3cdaa2;
        background: rgba(52,211,153,.10);
    }

    .welcome-platform__grid {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: .8rem;
    }

    .welcome-role-card {
        overflow: hidden;
        border: 1px solid rgba(212,255,241,.36);
        border-radius: .8rem;
        background: rgba(250,255,252,.98);
        color: #10251e;
        box-shadow: 0 14px 28px rgba(0,10,8,.18);
    }

    .welcome-role-card__visual {
        position: relative;
        height: auto;
        aspect-ratio: 16 / 9;
        overflow: hidden;
        background: #dcece6;
    }

    .welcome-role-card__visual::after {
        position: absolute;
        inset: 0;
        content: '';
        pointer-events: none;
        background: linear-gradient(to bottom, transparent 58%, rgba(2,35,26,.19));
    }

    .welcome-role-card__visual img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        filter: saturate(.96) contrast(1.025);
        transition: transform .35s ease, filter .35s ease;
    }

    .welcome-role-card__visual--passengers img { object-position: center 40%; }
    .welcome-role-card__visual--drivers img { object-position: center 36%; }
    .welcome-role-card__visual--cooperatives img { object-position: center 45%; }
    .welcome-role-card__visual--coordinators img { object-position: center 43%; }

    .welcome-role-card:hover .welcome-role-card__visual img {
        transform: scale(1.025);
        filter: saturate(1.02) contrast(1.035);
    }

    .welcome-role-card__body {
        display: flex;
        align-items: center;
        gap: .65rem;
        min-height: 4.4rem;
        padding: .65rem .75rem;
    }

    .welcome-role-card__body > span {
        display: flex;
        width: 2.25rem;
        height: 2.25rem;
        flex: 0 0 auto;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: linear-gradient(145deg, #0b9669, #06704e);
        color: white;
    }

    .welcome-role-card__body svg {
        width: 1.05rem;
        height: 1.05rem;
    }

    .welcome-role-card__body h3 {
        font-size: .78rem;
        font-weight: 800;
    }

    .welcome-role-card__body p {
        margin-top: .1rem;
        color: #687a73;
        font-size: .6rem;
        line-height: .85rem;
    }

    :global(html.dark) .welcome-hero::after {
        background: linear-gradient(90deg, rgba(7,25,19,.98) 0%, rgba(8,29,22,.94) 34%, rgba(8,29,22,.66) 46%, rgba(8,29,22,.10) 62%, transparent 72%);
    }

    :global(html.dark) .welcome-hero__title,
    :global(html.dark) .welcome-desktop-benefits > li {
        color: #f2fff9;
    }

    :global(html.dark) .welcome-hero__title + p {
        color: #c7d8d1;
    }
}

/* Todo el rango anterior al breakpoint de escritorio usa una sola composición
   móvil. Antes estas reglas terminaban en 639px: varios iPhone en horizontal
   CSS, teléfonos grandes y WebViews quedaban con una mezcla de estilos sm/md. */
@media (max-width: 767px) {
    .welcome-page-shell {
        position: relative;
        width: 100%;
        max-width: none;
        padding: 0;
    }

    .welcome-nav {
        position: absolute;
        top: 0;
        left: 0;
        z-index: 10;
        width: 100%;
        margin: 0;
        padding-top: .85rem;
        border-color: transparent;
        background: transparent;
        box-shadow: none;
        -webkit-backdrop-filter: none;
        backdrop-filter: none;
        justify-content: center;
    }

    .welcome-nav > div:last-child {
        display: none;
    }

    .welcome-nav > div:last-child {
        display: none;
    }

    .welcome-hero {
        display: flex;
        flex-direction: column;
        width: 100%;
        min-height: 100svh;
        margin-top: 0;
        padding: clamp(3.75rem, 16vw, 4.5rem) clamp(1rem, 5.5vw, 1.45rem) max(1rem, env(safe-area-inset-bottom));
        gap: 0;
        border: 0;
        border-radius: 0;
        box-shadow: none;
        /* Primer render antes de que llegue la fotografía: replica su cielo
           claro y su cierre verde para que el contenido nunca quede negro. */
        background: linear-gradient(180deg, #effcff 0%, #f7fbf8 43%, #d9eee6 57%, #0b4939 78%, #03140f 100%);
    }

    .welcome-hero__image--mobile {
        background-position: center top;
        /* La foto llena el viewport aunque cambie la relación de aspecto del
           teléfono. Así no aparece una franja vacía en iPhone altos. */
        background-size: cover;
        background-repeat: no-repeat;
        background-color: #03140f;
        filter: saturate(1.04) contrast(1.02) brightness(1.02);
    }

    /* El cierre verde oscuro acompaña las ondas originales de la imagen y
       mantiene legibles la solicitud rápida y el enlace inferior. */
    .welcome-hero__image--mobile::after {
        position: absolute;
        inset: auto 0 0 0;
        height: 38%;
        content: '';
        pointer-events: none;
        background: linear-gradient(to bottom, transparent, #03140f);
    }

    .welcome-hero__content {
        padding: 0;
        text-shadow: none;
    }

    .welcome-hero__content::before {
        top: -1rem;
        right: -1rem;
        bottom: auto;
        left: -1rem;
        height: 15rem;
        background: linear-gradient(180deg, rgba(255,255,255,.20), rgba(255,255,255,.06) 68%, transparent);
        filter: blur(10px);
    }

    .welcome-hero__content > p:first-child {
        position: relative;
        margin-top: .05rem;
        padding: 0;
        border-radius: 0;
        background: transparent;
        color: #173128;
        font-size: clamp(.62rem, 2.8vw, .72rem);
        font-weight: 500;
    }

    .welcome-hero__content > p:first-child svg {
        display: none;
    }

    .welcome-hero__content > p:first-child::after {
        position: absolute;
        left: 50%;
        bottom: -.42rem;
        width: 2.25rem;
        height: 1px;
        content: '';
        background: #159067;
        transform: translateX(-50%);
    }

    .welcome-hero__title {
        margin-top: clamp(.8rem, 3.6vw, 1.05rem);
        color: #081812;
        font-size: clamp(1.9rem, 9.8vw, 2.65rem);
        font-weight: 750;
        line-height: 1;
        letter-spacing: -.025em;
        text-shadow: 0 1px 8px rgba(255,255,255,.34);
    }

    .welcome-hero__content > .welcome-hero__title + p {
        max-width: 16rem;
        margin-top: .55rem;
        color: #40554e;
        font-size: clamp(.67rem, 2.9vw, .76rem);
        line-height: 1.05rem;
    }

    .welcome-desktop-benefits {
        position: relative;
        width: 100%;
        max-width: 21rem;
        margin: clamp(.8rem, 4vw, 1.05rem) auto 0;
        gap: 0;
    }

    .welcome-desktop-benefits > li {
        position: relative;
        display: flex;
        min-width: 0;
        flex-direction: column;
        align-items: center;
        gap: .3rem;
        padding-inline: .3rem;
        color: #081812;
    }

    .welcome-desktop-benefits > li:not(:last-child)::after {
        position: absolute;
        top: .25rem;
        right: 0;
        width: 1px;
        height: 3.55rem;
        content: '';
        background: linear-gradient(to bottom, transparent, rgba(12,112,77,.24), transparent);
    }

    .welcome-desktop-benefits > li > span {
        display: flex;
        width: clamp(1.9rem, 9vw, 2.25rem);
        height: clamp(1.9rem, 9vw, 2.25rem);
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(8,139,94,.26);
        border-radius: 999px;
        background: rgba(225,247,238,.88);
        color: #07835b;
        box-shadow: 0 4px 12px rgba(4,98,66,.10), inset 0 1px 0 rgba(255,255,255,.9);
        -webkit-backdrop-filter: blur(7px);
        backdrop-filter: blur(7px);
    }

    .welcome-desktop-benefits svg {
        width: 1rem;
        height: 1rem;
    }

    .welcome-desktop-benefits p {
        font-size: clamp(.53rem, 2.35vw, .64rem);
        font-weight: 700;
        line-height: 1.12;
        text-align: center;
        text-shadow: 0 1px 5px rgba(255,255,255,.9);
    }

    .welcome-survey-link {
        display: none;
    }

    .welcome-hero__journey {
        width: 100%;
        max-width: none;
        /* Mantiene los botones sobre la zona del auto y no pegados al título.
           El espacio escala con el ancho, igual que la fotografía 9:16. */
        margin-top: calc(clamp(8.35rem, 43vw, 12.25rem) + 20px);
    }

    .welcome-hero__actions {
        width: 100%;
        max-width: 21.5rem;
        grid-template-columns: minmax(0, 1fr);
        margin: 0 auto .5rem;
        gap: .48rem;
    }

    .welcome-create-button,
    .welcome-login-button {
        min-height: 3.15rem;
        border-radius: 1rem;
        font-size: .86rem;
    }

    .welcome-create-button {
        position: relative;
        justify-content: center;
        border: 1px solid rgba(76,255,186,.30);
        background:
            radial-gradient(circle at 50% -35%, rgba(126,255,209,.52), transparent 58%),
            linear-gradient(135deg, #08bd7b, #079b65);
        box-shadow: 0 8px 24px rgba(0,112,73,.30), 0 0 18px rgba(28,215,149,.14), inset 0 1px 0 rgba(255,255,255,.28);
    }

    .welcome-create-button > svg:last-child {
        position: absolute;
        right: 1rem;
    }

    .welcome-login-button {
        border-color: rgba(231,255,246,.66);
        background: rgba(6,27,21,.72);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.06);
    }

    .welcome-how-button {
        display: none;
    }

    .welcome-or-divider {
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        align-items: center;
        gap: .65rem;
        width: 100%;
        max-width: 17rem;
        margin: .55rem auto 1.2rem;
        color: rgba(255,255,255,.72);
        font-size: .58rem;
    }

    .welcome-or-divider span {
        height: 1px;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,.38));
    }

    .welcome-or-divider span:last-child {
        background: linear-gradient(90deg, rgba(255,255,255,.38), transparent);
    }

    .welcome-quick-card {
        width: 100%;
        max-width: 21.5rem;
        margin-inline: auto;
        border-color: rgba(255,255,255,.70);
        border-radius: 1.25rem;
        background: rgba(255,255,255,.97);
        box-shadow: 0 10px 28px rgba(0,27,19,.24);
    }

    /* Estado cerrado más compacto. Las reglas no alcanzan al formulario
       expandido para conservar su espacio de escritura y desplazamiento. */
    .welcome-quick-card:not(.welcome-quick-card--expanded) {
        max-width: 20.25rem;
        border-radius: 1.1rem;
    }

    .welcome-quick-card:not(.welcome-quick-card--expanded) > button:first-of-type {
        padding: .58rem .85rem .22rem;
        border-radius: 1.1rem;
    }

    .welcome-quick-card:not(.welcome-quick-card--expanded) > button:first-of-type h2 {
        margin-top: .05rem;
        font-size: 1.04rem;
        line-height: 1.15rem;
    }

    .welcome-quick-card:not(.welcome-quick-card--expanded) > button:first-of-type p:first-child {
        font-size: .54rem;
        letter-spacing: .18em;
    }

    .welcome-quick-card:not(.welcome-quick-card--expanded) > button:first-of-type h2 + p {
        margin-top: .05rem;
        font-size: .62rem;
    }

    .welcome-quick-card:not(.welcome-quick-card--expanded) > button:first-of-type > span {
        width: 2.25rem;
        height: 2.25rem;
        border-radius: .68rem;
    }

    .welcome-quick-card:not(.welcome-quick-card--expanded) > button:first-of-type > span svg {
        width: 1.05rem;
        height: 1.05rem;
    }

    .welcome-quick-card h2 {
        color: #081812;
    }

    .welcome-quick-card p:not(.text-arka-primary) {
        color: #53645e;
    }

    .welcome-quick-card > button {
        padding: .75rem 1rem .35rem;
        border-radius: 1.25rem;
    }

    .welcome-quick-card > button h2 {
        font-size: 1.16rem;
        line-height: 1.35rem;
    }

    .welcome-quick-preview {
        display: flex;
        align-items: center;
        gap: .55rem;
        width: calc(100% - 1.5rem);
        min-height: 2.3rem;
        margin: .08rem .75rem .64rem;
        padding: .48rem .68rem;
        box-sizing: border-box;
        border: 1px solid rgb(var(--arka-border) / .55);
        border-radius: .72rem;
        background: rgb(var(--arka-base) / .72);
        color: rgb(var(--arka-text-muted));
        font-size: .66rem;
        text-align: left;
        transition: border-color .2s ease, background-color .2s ease, transform .2s ease;
    }

    /* Cerrada, la solicitud móvil muestra una sola acción, como el prototipo.
       Se fuerza incluso si el navegador reporta un ancho CSS inesperado. */
    .welcome-quick-preview--origin,
    .welcome-quick-submit-preview {
        display: none !important;
    }

    .welcome-quick-preview:hover {
        border-color: rgb(var(--arka-primary) / .42);
        background: rgb(var(--arka-primary) / .08);
    }

    .welcome-quick-preview:focus-visible {
        outline: 2px solid rgb(var(--arka-primary));
        outline-offset: 2px;
    }

    .welcome-mobile-how {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: .05rem;
        width: 100%;
        max-width: 21.5rem;
        margin: .75rem auto 0;
        color: rgba(255,255,255,.92);
        font-size: .62rem;
        font-weight: 700;
        letter-spacing: .01em;
    }

    /* El inicio fotográfico mantiene contraste propio en modo oscuro; no se
       hereda la placa oscura global que apagaría el cielo y el texto. */
    :global(html.dark) .welcome-nav {
        background: transparent;
        box-shadow: none;
    }

    :global(html.dark) .welcome-hero__content {
        text-shadow: none;
    }

    :global(html.dark) .welcome-hero__content::before {
        background: linear-gradient(180deg, rgba(255,255,255,.20), rgba(255,255,255,.06) 68%, transparent);
    }

    :global(html.dark) .welcome-hero__title,
    :global(html.dark) .welcome-desktop-benefits > li {
        color: #081812;
    }

    #como-funciona {
        margin-top: 0;
        padding: 2rem 1rem 0;
    }
}
</style>
