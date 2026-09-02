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
    // un admin suba una, el hero se ve con el fondo oscuro liso de siempre.
    heroBackgroundUrl: { type: String, default: null },
    ctaInteractionToken: { type: String, default: '' },
});

const authUser = usePage().props.auth?.user ?? null;

// Pedido explícito del usuario ("que no se vea tan brusco por temas de
// carga de la imagen... lo hiciste en el login y me gustó"): mismo criterio
// que GuestLayout.vue — la foto se precarga en JS y recién se hace visible
// con un fundido suave una vez lista, en vez de aparecer de golpe cuando
// pesa mucho. Mientras tanto se ve el fondo oscuro liso de siempre.
const heroBackgroundLoaded = ref(false);
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
        <link v-if="heroBackgroundUrl" rel="preload" as="image" :href="heroBackgroundUrl" fetchpriority="high" />
    </Head>

    <div class="arka-app-background min-h-screen">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-5 sm:py-8">
            <!-- Barra superior: ayuda + cuenta, solo si ya tiene sesión iniciada. -->
            <div class="flex justify-end items-center gap-3 mb-4">
                <Link
                    v-if="authUser"
                    :href="route('dashboard')"
                    class="flex items-center gap-2 text-sm text-arka-text-muted hover:text-arka-text"
                >
                    <UserAvatar :user="authUser" size-class="h-9 w-9 text-sm" />
                </Link>
            </div>

            <!-- Con sesión iniciada, el hero completo (mockup, CTAs de registro)
                 no aplica — ya tiene el acceso directo arriba. -->
            <div v-if="authUser" class="text-center">
                <ApplicationLogo size="h-14 sm:h-16" />
                <Link
                    :href="route('dashboard')"
                    class="mt-6 inline-flex items-center px-5 py-2.5 bg-arka-primary rounded-arka font-semibold text-sm text-arka-base hover:bg-arka-primary-bright transition"
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
                 oscuro de siempre de toda la app. `bg-cover`/`bg-top` para
                 que la parte de arriba (donde vive el texto) sea la que más
                 se vea. -->
            <div
                v-else
                class="relative isolate grid grid-cols-1 lg:grid-cols-2 gap-8 items-center overflow-hidden rounded-[2rem] px-4 py-6 sm:px-8 sm:py-9"
            >
                <!-- Foto de fondo con fundido suave (pedido explícito del usuario:
                     "que no se vea tan brusco... lo hiciste en el login y me
                     gustó") — capa aparte que recién se hace visible cuando la
                     imagen ya terminó de precargarse (ver heroBackgroundLoaded),
                     en vez de pintarse de golpe como antes. -->
                <div
                    v-if="heroBackgroundUrl"
                    class="pointer-events-none absolute inset-0 -z-10 bg-cover bg-top transition-opacity duration-700 ease-out"
                    :class="heroBackgroundLoaded ? 'opacity-100' : 'opacity-0'"
                    :style="{ backgroundImage: `linear-gradient(180deg, rgba(7,17,13,0.35) 0%, rgba(7,17,13,0.55) 60%, rgba(7,17,13,0.85) 100%), url('${heroBackgroundUrl}')` }"
                />

                <div class="text-center lg:text-start">
                    <ApplicationLogo size="h-11 sm:h-14" />

                    <p class="mt-3 inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-arka-primary/10 text-arka-primary-bright text-xs font-medium">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m12 3 8 3.5v5.2c0 4.4-3 7.6-8 9.3-5-1.7-8-4.9-8-9.3V6.5L12 3Z" />
                        </svg>
                        Su círculo, sus viajes, su tranquilidad
                    </p>

                    <!-- Pedido explícito del usuario ("quiero que el diseño
                         quede tal cual te pase"): acá se sigue el tuteo
                         informal de la imagen de referencia tal como la
                         mandó, aunque el resto de la app (y la propia
                         tarjeta de invitado de al lado) use "usted" —
                         avisado en la respuesta, por si preferís unificarlo
                         después. -->
                    <h1 class="mt-4 text-4xl sm:text-5xl lg:text-6xl font-bold text-arka-text leading-[1.05]">
                        Viaja con<br />
                        <span class="text-arka-primary">quienes</span> confías
                    </h1>

                    <!-- 3 puntos exactos pedidos por el usuario, conectados
                         con una línea vertical sólida — mismo patrón que la
                         lista "Para Clientes" más abajo en #como-funciona,
                         para que se lean como pasos de una misma experiencia
                         y no como 3 datos sueltos. -->
                    <ul class="mt-4 max-w-md mx-auto lg:mx-0">
                        <li class="flex items-start gap-3 text-start">
                            <div class="flex flex-col items-center self-stretch shrink-0">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-arka-primary/15 border border-arka-primary/40">
                                    <svg class="h-3.5 w-3.5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7-4.6-7-10.5A7 7 0 0 1 12 4a7 7 0 0 1 7 6.5C19 16.4 12 21 12 21Z" />
                                        <circle cx="12" cy="10.5" r="2.3" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </span>
                                <div class="w-0.5 flex-1 min-h-[0.75rem] bg-arka-primary/40 my-0.5 rounded-full"></div>
                            </div>
                            <p class="pt-1.5 text-sm font-semibold text-arka-text">Tranquilidad en cada viaje</p>
                        </li>
                        <li class="flex items-start gap-3 text-start">
                            <div class="flex flex-col items-center self-stretch shrink-0">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-arka-primary/15 border border-arka-primary/40">
                                    <svg class="h-3.5 w-3.5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="9" cy="8" r="3" stroke-linecap="round" stroke-linejoin="round" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 19a6.5 6.5 0 0 1 13 0" />
                                        <circle cx="17" cy="8" r="2.4" stroke-linecap="round" stroke-linejoin="round" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.5 12.5c2.4 0 4.5 1.9 5 6.5" />
                                    </svg>
                                </span>
                                <div class="w-0.5 flex-1 min-h-[0.75rem] bg-arka-primary/40 my-0.5 rounded-full"></div>
                            </div>
                            <p class="pt-1.5 text-sm font-semibold text-arka-text">Siempre cerca de los tuyos</p>
                        </li>
                        <li class="flex items-start gap-3 text-start">
                            <div class="flex flex-col items-center self-stretch shrink-0">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-arka-primary/15 border border-arka-primary/40">
                                    <svg class="h-3.5 w-3.5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="4.5" y="10.5" width="15" height="9.5" rx="2" stroke-linecap="round" stroke-linejoin="round" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 10.5V7a4.5 4.5 0 0 1 9 0v3.5" />
                                    </svg>
                                </span>
                            </div>
                            <p class="pt-1.5 text-sm font-semibold text-arka-text">Una nueva forma de moverte</p>
                        </li>
                    </ul>

                    <!-- Encuesta corta (pedido explícito del usuario: "en la
                         raíz también") — mismo lado izquierdo que el resto
                         del bloque (hereda text-center lg:text-start). -->
                    <p v-if="!surveyDone" class="mt-4">
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
                <div class="h-px w-full max-w-xs mx-auto bg-gradient-to-r from-transparent via-arka-primary/25 to-transparent lg:hidden"></div>
                <div class="hidden lg:block absolute inset-y-6 left-1/2 w-px -translate-x-1/2 bg-gradient-to-b from-transparent via-arka-primary/25 to-transparent"></div>

                <!-- Acceso urgente sin correo: conserva la identidad visual de
                     la app y asigna la cooperativa más cercana al origen. -->
                <div class="mx-auto w-full max-w-lg">
                <!-- Acciones principales antes del buscador: el visitante decide
                     primero si entra, se registra o conoce el funcionamiento. -->
                <div class="mb-4 grid grid-cols-2 gap-2.5">
                    <Link
                        v-if="canLogin"
                        :href="route('login')"
                        class="group inline-flex min-h-12 items-center justify-center gap-2 rounded-xl bg-arka-primary px-4 py-3 text-sm font-bold uppercase tracking-wide text-arka-base shadow-lg shadow-arka-primary/20 transition hover:-translate-y-0.5 hover:bg-arka-primary-bright focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-arka-primary"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14 4.5h4a1.5 1.5 0 0 1 1.5 1.5v12a1.5 1.5 0 0 1-1.5 1.5h-4M11 8.5 15 12l-4 3.5M15 12H4"/></svg>
                        Iniciar sesión
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="route('register')"
                        class="group inline-flex min-h-12 items-center justify-center gap-2 rounded-xl border border-arka-primary/45 bg-arka-card/80 px-4 py-3 text-sm font-bold uppercase tracking-wide text-arka-primary-bright transition hover:-translate-y-0.5 hover:border-arka-primary hover:bg-arka-primary/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-arka-primary"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9.5" cy="8.5" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M3.5 20a6 6 0 0 1 12 0M18 8v6M15 11h6"/></svg>
                        Crear cuenta
                    </Link>
                    <a
                        href="#como-funciona"
                        class="group col-span-2 inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-arka-text-muted/15 bg-arka-card/55 px-4 py-2.5 text-sm font-semibold text-arka-text-muted transition hover:border-arka-primary/35 hover:bg-arka-primary/[0.06] hover:text-arka-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-arka-primary"
                    >
                        <span class="flex h-6 w-6 items-center justify-center rounded-full border border-arka-primary/30 text-arka-primary">
                            <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.14v13.72a1 1 0 0 0 1.53.85l11-6.86a1 1 0 0 0 0-1.7l-11-6.86A1 1 0 0 0 8 5.14Z"/></svg>
                        </span>
                        Cómo funciona
                    </a>
                </div>

                <div class="relative overflow-visible rounded-[1.75rem] border border-arka-primary/20 bg-arka-card shadow-2xl">
                    <!-- Cabecera inspirada en un buscador de reservas: primero
                         explica la acción y después presenta el trayecto. -->
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 rounded-[1.75rem] px-5 py-3.5 text-start transition hover:bg-arka-primary/[0.04] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-arka-primary focus-visible:ring-offset-2 focus-visible:ring-offset-arka-base sm:px-6"
                        :class="showingGuestRideForm ? 'rounded-b-none border-b border-arka-text-muted/10' : ''"
                        :aria-expanded="showingGuestRideForm"
                        aria-controls="guest-ride-form"
                        @click="showingGuestRideForm = !showingGuestRideForm"
                    >
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-arka-primary">Solicitud rápida</p>
                            <h2 class="mt-0.5 text-xl font-bold text-arka-text">¿A dónde vamos?</h2>
                            <p class="mt-0.5 text-[11px] text-arka-text-muted">{{ showingGuestRideForm ? 'Defina su recorrido y consulte la tarifa.' : 'Toque para indicar su origen y destino.' }}</p>
                        </div>
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-arka-primary/15 text-arka-primary">
                            <svg class="h-5 w-5 transition-transform duration-200" :class="showingGuestRideForm ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
                        </span>
                    </button>

                    <div v-show="showingGuestRideForm" id="guest-ride-form" class="px-5 py-4 sm:px-6">
                        <div class="mb-2.5 flex items-center justify-between gap-3">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-arka-text-muted">Trayecto</p>
                                <p class="mt-0.5 text-sm font-semibold text-arka-text">Origen y destino</p>
                            </div>
                            <button
                                type="button"
                                class="inline-flex h-8 items-center gap-1.5 rounded-full border border-arka-text-muted/20 px-2.5 text-[11px] font-semibold text-arka-text transition hover:border-arka-primary/50 hover:text-arka-primary"
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

                        <div v-if="guestCooperatives.length">
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
                        <div v-else class="rounded-arka border border-arka-warning/30 bg-arka-warning/10 p-3 text-xs text-arka-warning">
                            No hay cooperativas disponibles en este momento.
                        </div>

                        <div class="mt-3 grid grid-cols-3 gap-2 border-y border-arka-text-muted/10 py-2 text-center">
                            <div class="flex items-center justify-center gap-1.5"><span class="flex h-4 w-4 items-center justify-center rounded-full bg-arka-primary text-[9px] font-bold text-arka-base">1</span><p class="text-[9px] text-arka-text-muted">Ruta</p></div>
                            <div class="flex items-center justify-center gap-1.5"><span class="flex h-4 w-4 items-center justify-center rounded-full border border-arka-primary/40 text-[9px] font-bold text-arka-primary">2</span><p class="text-[9px] text-arka-text-muted">Tarifa</p></div>
                            <div class="flex items-center justify-center gap-1.5"><span class="flex h-4 w-4 items-center justify-center rounded-full border border-arka-primary/40 text-[9px] font-bold text-arka-primary">3</span><p class="text-[9px] text-arka-text-muted">Confirmar</p></div>
                        </div>

                        <button type="button" :disabled="!guestCooperatives.length" class="mt-3 flex w-full items-center justify-center gap-2 rounded-arka bg-arka-primary px-5 py-3 text-xs font-bold uppercase tracking-wide text-arka-base shadow-lg shadow-arka-primary/15 transition hover:bg-arka-primary-bright disabled:cursor-not-allowed disabled:opacity-40" @click="continueAsGuest">
                            Consultar tarifa
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
                        </button>
                        <p class="mt-2 text-center text-[9px] leading-4 text-arka-text-muted">Nada se envía hasta que revise la tarifa y confirme.</p>
                    </div>
                </div>

                </div>
            </div>

            <!-- Para Clientes / diagrama / Para Conductores (pedido explícito del
                 usuario, mockup provisto) — id como destino del botón "¿Cómo
                 funciona?" del hero, para reusar este mismo contenido en vez de
                 duplicarlo en una página aparte. -->
            <div id="como-funciona" class="mt-16 scroll-mt-6 grid grid-cols-1 lg:grid-cols-[1fr_auto_1fr] items-center gap-8">
                <!-- Para Clientes -->
                <div class="p-6 bg-arka-card shadow rounded-arka">
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

                    <div class="mt-1 flex items-start gap-3 rounded-arka border border-arka-primary/15 bg-arka-primary/[0.06] p-3">
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
                            <span class="h-12 w-12 rounded-full bg-arka-card border border-arka-text-muted/20 flex items-center justify-center">
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
                            <span class="h-12 w-12 rounded-full bg-arka-card border border-arka-text-muted/20 flex items-center justify-center">
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
                <div class="p-6 bg-arka-card shadow rounded-arka">
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

            <!-- Las cooperativas forman parte de la red, pero conservamos el
                 bloque principal original limpio y fácil de recorrer. -->
            <div class="mt-8 flex flex-col items-center gap-4 rounded-arka border border-arka-primary/20 bg-arka-card p-5 text-center shadow sm:flex-row sm:px-6 sm:text-start">
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
            <div class="mt-16">
                <h2 class="mx-auto mb-2 max-w-2xl text-center text-2xl font-semibold leading-tight text-arka-text sm:text-3xl">
                    Más que encontrar un viaje.
                    <span class="block text-arka-primary">Construye una red para los próximos.</span>
                </h2>
                <p class="mx-auto mb-8 max-w-xl text-center text-sm leading-6 text-arka-text-muted">
                    Cinco formas en las que Arka01 convierte cada experiencia en más confianza y mejores conexiones.
                </p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <div v-for="item in WHY_ARKA01" :key="item.title" class="rounded-arka border border-arka-text-muted/10 bg-arka-card/50 p-4 text-center">
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
                <span class="shrink-0 px-4 py-2 rounded-arka bg-arka-primary text-arka-base text-xs font-semibold uppercase tracking-wide">
                    Enviar sugerencia
                </span>
            </button>

            <!-- Footer con redes sociales (pedido explícito del usuario) — mismo
                 componente que usan GuestLayout.vue y Survey/Show.vue. -->
            <div class="mt-10 flex flex-col items-center gap-4">
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
                            class="rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text text-sm placeholder:text-arka-text-muted"
                        />
                        <input
                            v-model="feedbackForm.email"
                            type="email"
                            placeholder="Correo (opcional)"
                            class="rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text text-sm placeholder:text-arka-text-muted"
                        />
                    </div>
                    <SearchableSelect v-model="feedbackForm.type" :options="FEEDBACK_TYPES" placeholder="Tipo" />
                    <textarea
                        v-model="feedbackForm.comment"
                        rows="3"
                        required
                        placeholder="Su comentario"
                        class="block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text text-sm placeholder:text-arka-text-muted"
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
                            class="inline-flex items-center justify-center px-4 py-2 bg-arka-primary rounded-arka font-semibold text-sm text-arka-base hover:bg-arka-primary-bright transition disabled:opacity-50"
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

                <button type="button" class="absolute end-4 top-4 grid h-9 w-9 place-items-center rounded-full border border-arka-text-muted/15 text-arka-text-muted transition hover:border-arka-primary/40 hover:text-arka-primary" aria-label="Cerrar" @click="closeWelcomeCta">
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
                        <div v-for="item in ['Su círculo', 'Índice de confianza', 'Viaje acompañado']" :key="item" class="rounded-xl border border-arka-text-muted/10 bg-arka-base/50 px-2 py-3 text-center text-[11px] font-medium leading-4 text-arka-text-muted">
                            {{ item }}
                        </div>
                    </div>

                    <button type="button" class="mt-6 flex w-full items-center justify-center gap-2 rounded-arka bg-arka-primary px-5 py-3.5 text-sm font-bold uppercase tracking-wide text-arka-base shadow-lg shadow-arka-primary/15 transition hover:bg-arka-primary-bright" @click="followWelcomeCta">
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
                        <input v-model="guestForm.name" type="text" autocomplete="name" placeholder="Ej. María López" class="w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text placeholder:text-arka-text-muted focus:border-arka-primary focus:ring-arka-primary" />
                        <p v-if="guestForm.errors.name" class="mt-1 text-xs text-arka-danger">{{ guestForm.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-arka-text">Número de WhatsApp</label>
                        <div class="grid grid-cols-[7rem_1fr] gap-2">
                            <SearchableSelect v-model="guestForm.country_code" :options="[{value: '+593', label: '🇪🇨 +593'}, {value: '+51', label: '🇵🇪 +51'}, {value: '+57', label: '🇨🇴 +57'}, {value: '+58', label: '🇻🇪 +58'}, {value: '+56', label: '🇨🇱 +56'}, {value: '+54', label: '🇦🇷 +54'}]" />
                            <input v-model="guestForm.phone_local" type="tel" inputmode="numeric" autocomplete="tel-national" placeholder="999 000 222" class="min-w-0 rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text placeholder:text-arka-text-muted focus:border-arka-primary focus:ring-arka-primary" />
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
                    <button type="submit" :disabled="guestForm.processing" class="rounded-arka bg-arka-primary px-4 py-3 text-sm font-bold uppercase tracking-wide text-arka-base hover:bg-arka-primary-bright disabled:opacity-50">
                        {{ guestForm.processing ? 'Preparando…' : 'Continuar seguro' }}
                    </button>
                </div>
            </form>
        </Modal>
    </div>
</template>
