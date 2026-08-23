<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Modal from '@/Components/Modal.vue';
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
});

const authUser = usePage().props.auth?.user ?? null;

const showingGuestIdentity = ref(false);
const guestLocationMessage = ref('');
const guestForm = useForm({
    origin_address: '', origin_lat: null, origin_lng: null,
    destination_address: '', destination_lat: null, destination_lng: null,
    cooperative_id: null,
    name: '', country_code: '+593', phone_local: '',
    website: '',
});

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
    { title: 'Tu propia flota', text: 'Agregue a sus conductores de confianza.' },
    { title: 'Elige con quién viajar', text: 'Usted decide quién le lleva y cuándo.' },
    { title: 'Conductores verificados', text: 'Perfiles verificados, viajes más seguros.' },
    { title: 'Viaja cuando lo necesites', text: 'Programe o solicite su viaje al instante.' },
];
const DRIVER_FEATURES = [
    { title: 'Tú fijas tu tarifa por km', text: 'Defina el precio que desea cobrar.' },
    { title: '0% comisión por viaje', text: 'Sin comisión por viaje, solo paga una tarifa fija mensual.' },
    { title: 'Clientes de confianza', text: 'Viaje con personas verificadas y con buen historial.' },
    { title: 'Tú decides qué viajes aceptar', text: 'Acepte solo los viajes que le convienen.' },
];

const WHY_ARKA01 = [
    { title: 'Seguridad', text: 'Botón SOS y contactos de confianza.' },
    { title: 'Privacidad', text: 'Tú decides quién ve tu información y tus viajes.' },
    { title: 'Precios justos', text: 'Transparente, sin sorpresas ni cobros ocultos.' },
    { title: 'Califica y mejora', text: 'Tu opinión ayuda a tener mejores experiencias.' },
    { title: 'Soporte real', text: 'Estamos para ayudarte cuando lo necesites.' },
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
    <Head title="Arka01 — Tu círculo. Tus viajes. Tu decisión." />

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
                class="relative isolate grid grid-cols-1 lg:grid-cols-2 gap-8 items-center overflow-hidden rounded-[2rem] px-4 py-6 sm:px-8 sm:py-9 bg-cover bg-top"
                :style="heroBackgroundUrl
                    ? `background-image: linear-gradient(180deg, rgba(7,17,13,0.35) 0%, rgba(7,17,13,0.55) 60%, rgba(7,17,13,0.85) 100%), url('${heroBackgroundUrl}')`
                    : ''"
            >
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

                    <div class="mt-5 flex flex-col sm:flex-row justify-center lg:justify-start gap-3">
                        <!-- Pedido explícito del usuario ("la gente se pierde"):
                             los dos en modo botón, iniciar sesión primero — quien
                             ya tiene cuenta es quien más se confundía antes, con
                             "crear mi círculo" como único botón grande y el
                             login escondido en un texto chico debajo. Pedido
                             explícito del usuario (con mockup de referencia):
                             botones más grandes y directos. -->
                        <Link
                            v-if="canLogin"
                            :href="route('login')"
                            class="inline-flex items-center justify-center gap-2 px-6 py-3.5 bg-arka-primary rounded-arka font-bold text-sm uppercase tracking-wide text-arka-base shadow-lg shadow-arka-primary/20 hover:bg-arka-primary-bright transition"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14 4.5h4a1.5 1.5 0 0 1 1.5 1.5v12a1.5 1.5 0 0 1-1.5 1.5h-4" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 8.5 15 12l-4 3.5M15 12H4" />
                            </svg>
                            Iniciar sesión
                        </Link>
                        <!-- Bug real reportado por el usuario: con tipo=cliente
                             forzado, quien quería registrarse como conductor
                             nunca veía esa opción — tenía sentido cuando el botón
                             se llamaba "Crear mi círculo" (acción específica de
                             cliente), ya no ahora que es genérico "Crear una
                             cuenta". Sin el parámetro, Auth/Register.vue arranca
                             en el primer paso y deja elegir. -->
                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="inline-flex items-center justify-center gap-2 px-6 py-3.5 bg-transparent border border-arka-primary/50 rounded-arka font-bold text-sm uppercase tracking-wide text-arka-primary-bright hover:bg-arka-primary/10 transition"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="9.5" cy="8.5" r="3" stroke-linecap="round" stroke-linejoin="round" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 20a6 6 0 0 1 12 0" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 8v6M15 11h6" />
                            </svg>
                            Crear cuenta
                        </Link>
                        <!-- Ancla simple a la sección que ya existe más abajo — sin
                             duplicar contenido en una página aparte (pedido
                             explícito del usuario). -->
                        <a
                            href="#como-funciona"
                            class="inline-flex items-center justify-center gap-2 px-6 py-3.5 bg-transparent border border-arka-text-muted/30 rounded-arka font-bold text-sm uppercase tracking-wide text-arka-text hover:bg-arka-card transition"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M8 5.14v13.72a1 1 0 0 0 1.53.85l11-6.86a1 1 0 0 0 0-1.7l-11-6.86A1 1 0 0 0 8 5.14Z" />
                            </svg>
                            ¿Cómo funciona?
                        </a>
                    </div>

                    <!-- Pedido explícito del usuario (con la imagen de
                         referencia): una sola línea con el mismo mensaje que
                         ya tiene la tarjeta de invitado al lado, en vez de
                         las dos insignias de antes. -->
                    <p class="mt-5 flex items-center justify-center gap-1.5 text-xs text-arka-text-muted lg:justify-start">
                        <svg class="h-3.5 w-3.5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m12 3 8 3.5v5.2c0 4.4-3 7.6-8 9.3-5-1.7-8-4.9-8-9.3V6.5L12 3Z" />
                        </svg>
                        Sin correo ni contraseña. Solo validamos tu WhatsApp.
                    </p>
                </div>

                <!-- Acceso urgente sin correo: conserva la identidad visual de
                     la app y asigna la cooperativa más cercana al origen. -->
                <div class="relative mx-auto w-full max-w-md rounded-[1.75rem] border border-arka-primary/15 bg-arka-card p-5 shadow-2xl sm:p-7">
                    <div class="mb-5 flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-2xl font-bold text-arka-text">¿A dónde vamos?</h2>
                        </div>
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-arka-primary/15 text-arka-primary">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 17h14l-1.5-6h-11L5 17Zm2-6 2-4h6l2 4M7 17v2m10-2v2"/><circle cx="8" cy="15" r="1" fill="currentColor"/><circle cx="16" cy="15" r="1" fill="currentColor"/></svg>
                        </span>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <div class="mb-1.5 flex items-center justify-between gap-3">
                                <label class="text-xs font-semibold uppercase tracking-wide text-arka-text-muted">Punto de partida</label>
                                <button type="button" class="text-xs font-medium text-arka-primary hover:text-arka-primary-bright" @click="useCurrentLocation">Usar mi ubicación</button>
                            </div>
                            <AddressAutocomplete v-model="guestForm.origin_address" placeholder="¿Dónde le recogemos?" @place-selected="chooseOrigin" @clear="guestForm.origin_lat = guestForm.origin_lng = null" />
                            <p v-if="guestLocationMessage" class="mt-1.5 text-xs text-arka-text-muted">{{ guestLocationMessage }}</p>
                            <p v-if="guestForm.errors.origin_address" class="mt-1.5 text-xs text-arka-danger">{{ guestForm.errors.origin_address }}</p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-arka-text-muted">Destino</label>
                            <AddressAutocomplete v-model="guestForm.destination_address" placeholder="Escriba su destino" @place-selected="chooseDestination" @clear="guestForm.destination_lat = guestForm.destination_lng = null" />
                            <p v-if="guestForm.errors.destination_address" class="mt-1.5 text-xs text-arka-danger">{{ guestForm.errors.destination_address }}</p>
                        </div>
                        <div v-if="guestCooperatives.length">
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-arka-text-muted">Le atenderá</label>
                            <SearchableSelect v-model="guestForm.cooperative_id" :options="cooperativeOptions" placeholder="Seleccione una cooperativa" />
                            <p v-if="assignedCooperative" class="mt-2 text-xs text-arka-text-muted">Asignada por cercanía a su punto de partida. Puede cambiarla.</p>
                            <p v-if="guestForm.errors.cooperative_id" class="mt-1.5 text-xs text-arka-danger">{{ guestForm.errors.cooperative_id }}</p>
                        </div>
                        <div v-else class="rounded-arka border border-arka-warning/30 bg-arka-warning/10 p-3 text-xs text-arka-warning">
                            No hay cooperativas disponibles en este momento.
                        </div>
                        <button type="button" :disabled="!guestCooperatives.length" class="flex w-full items-center justify-center gap-2 rounded-arka bg-arka-primary px-5 py-3.5 text-sm font-bold uppercase tracking-wide text-arka-base transition hover:bg-arka-primary-bright disabled:cursor-not-allowed disabled:opacity-40" @click="continueAsGuest">
                            Ver tarifa y solicitar
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
                        </button>
                        <p class="text-center text-[11px] leading-relaxed text-arka-text-muted">Antes de enviar verá la ruta, el precio y podrá confirmar o corregir los datos.</p>
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
                    <p class="text-sm text-arka-text-muted mb-4">Viaje con personas que conoce y en quienes confía.</p>

                    <ul>
                        <li v-for="(feature, i) in CLIENT_FEATURES" :key="feature.title" class="flex items-start gap-3">
                            <!-- Pedido explícito del usuario: una línea que una los
                                 puntos, no puntos sueltos — mismo criterio que el
                                 diagrama del medio, pero vertical. -->
                            <div class="flex flex-col items-center self-stretch shrink-0">
                                <span class="mt-1.5 h-2 w-2 rounded-full bg-arka-primary shrink-0"></span>
                                <div v-if="i < CLIENT_FEATURES.length - 1" class="w-px flex-1 min-h-[1.5rem] bg-arka-primary/25 mt-1"></div>
                            </div>
                            <div class="pb-3">
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
                        <li v-for="point in ['Red privada y segura', 'Control total para usted', 'Viajes sin intermediarios']" :key="point" class="flex items-center gap-2 text-sm text-arka-text-muted">
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
                    <p class="text-sm text-arka-text-muted mb-4">Usted maneja su negocio, nosotros le conectamos.</p>

                    <ul>
                        <li v-for="(feature, i) in DRIVER_FEATURES" :key="feature.title" class="flex items-start gap-3">
                            <div class="flex flex-col items-center self-stretch shrink-0">
                                <span class="mt-1.5 h-2 w-2 rounded-full bg-arka-primary shrink-0"></span>
                                <div v-if="i < DRIVER_FEATURES.length - 1" class="w-px flex-1 min-h-[1.5rem] bg-arka-primary/25 mt-1"></div>
                            </div>
                            <div class="pb-3">
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

            <!-- ¿Por qué elegir Arka01? -->
            <div class="mt-16">
                <h2 class="text-center text-lg font-semibold text-arka-text mb-6">
                    ¿Por qué elegir <span class="text-arka-primary">Arka01</span>?
                </h2>
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
                    <div v-for="item in WHY_ARKA01" :key="item.title" class="text-center">
                        <span class="mx-auto h-11 w-11 rounded-full bg-arka-primary/15 flex items-center justify-center mb-2">
                            <svg v-if="item.title === 'Seguridad'" class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m12 3 8 3.5v5.2c0 4.4-3 7.6-8 9.3-5-1.7-8-4.9-8-9.3V6.5L12 3Z" />
                            </svg>
                            <svg v-else-if="item.title === 'Privacidad'" class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="4.5" y="10.5" width="15" height="9.5" rx="2" stroke-linecap="round" stroke-linejoin="round" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 10.5V7a4.5 4.5 0 0 1 9 0v3.5" />
                            </svg>
                            <svg v-else-if="item.title === 'Precios justos'" class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="8.5" stroke-linecap="round" stroke-linejoin="round" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 7v10M14.5 9.5a2.5 2.5 0 0 0-2.5-1.5c-1.4 0-2.5.9-2.5 2s1.1 1.7 2.5 2 2.5.9 2.5 2-1.1 2-2.5 2a2.5 2.5 0 0 1-2.5-1.5" />
                            </svg>
                            <svg v-else-if="item.title === 'Califica y mejora'" class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m12 3.5 2.6 5.6 6.1.7-4.5 4.2 1.2 6-5.4-3-5.4 3 1.2-6-4.5-4.2 6.1-.7L12 3.5Z" />
                            </svg>
                            <svg v-else class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 5.5h16v10.5H8.5L4 20V5.5Z" />
                            </svg>
                        </span>
                        <p class="text-sm font-medium text-arka-text">{{ item.title }}</p>
                        <p class="text-xs text-arka-text-muted">{{ item.text }}</p>
                    </div>
                </div>
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

            <p class="mt-10 text-center text-xs text-arka-text-muted">
                <Link href="/terminos" class="hover:text-arka-primary-bright">Términos</Link>
                <span class="mx-2">·</span>
                <Link href="/privacidad" class="hover:text-arka-primary-bright">Privacidad</Link>
            </p>
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
