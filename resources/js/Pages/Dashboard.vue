<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AdminNavIcon from '@/Components/AdminNavIcon.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DriverAvailabilityToggle from '@/Components/DriverAvailabilityToggle.vue';
import AdBannerSlider from '@/Components/AdBannerSlider.vue';
import FleetMap from '@/Components/FleetMap.vue';
import HomeSearchSheet from '@/Components/HomeSearchSheet.vue';
import ArkaRouteLoader from '@/Components/ArkaRouteLoader.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { playAttentionAlert, playUpdateChime } from '@/Utils/liveAlert';
import { buildWhatsAppOptInUrl } from '@/Utils/whatsapp';
import { etaMinutes } from '@/Utils/eta';
import { saveClientLocation } from '@/Utils/sessionLocation';
import { ADMIN_NAV_GROUPS } from '@/Utils/adminNav';

// Pedido explícito del usuario (con captura del Inicio del admin, casi
// vacío): "coloca recuadro con las opciones que son agrupadas... y allí
// dentro incluyes" — mismas 6 secciones que ya arma Utils/adminNav.js para
// el dropdown de escritorio y el bottom sheet del FAB en móvil, acá como
// tarjetas tipo acordeón (tocar una la abre y cierra las demás), sin modal
// ni pantalla aparte.
const expandedAdminGroup = ref(null);
function toggleAdminGroup(key) {
    expandedAdminGroup.value = expandedAdminGroup.value === key ? null : key;
}

const props = defineProps({
    driverStats: { type: Object, default: null },
    fleetDrivers: { type: Array, default: null },
    nearbyDrivers: { type: Array, default: null },
    targetFleetId: { type: Number, default: null },
    upcomingTrips: { type: Array, default: null },
    inviteCode: { type: String, default: null },
    earningsSparkline: { type: Array, default: null },
    driverFleetIds: { type: Array, default: null },
    adBanners: { type: Array, default: () => [] },
    // Avisos de carrera nueva por WhatsApp (pedido explícito del usuario):
    // se ofrece conectar la ventana de 24h justo al activarse.
    whatsappSession: { type: Object, default: null },
    whatsappBusinessNumber: { type: String, default: null },
    // Rediseño UX (pedido explícito del usuario, guiado por
    // ARKA01_Rediseno_UX_Flujo_Carreras.md): buscador "¿A dónde vas?" arriba
    // de Inicio — mismos datos que ya usaba Ride/Request.vue para
    // direcciones favoritas y rutas guardadas, ver DashboardController.
    frequentPlaces: { type: Array, default: () => [] },
    savedRoutes: { type: Array, default: () => [] },
    // Bug real reportado por el usuario ("porque no centra la ubicación
    // actual"): sin esto, el mapa arrancaba en el centro de Quito por
    // defecto (FleetMap.vue) hasta que la geolocalización en vivo del
    // navegador resolviera — sin permiso, o mientras el aviso del navegador
    // seguía sin respuesta, se quedaba ahí sin ningún indicio de qué pasó.
    homeInitialCenter: { type: Object, default: null },
    // Indicadores del panel admin (pedido explícito del usuario: "personas
    // registradas, Pasajeros, conductores, cooperativas... esta semana. este
    // mes. hoy") — null para cualquier cuenta que no sea admin.
    adminStats: { type: Object, default: null },
});

const whatsappSessionActive = computed(() => props.whatsappSession && props.whatsappSession.status !== 'expired');
const whatsappOptInUrl = buildWhatsAppOptInUrl(props.whatsappBusinessNumber, usePage().props.auth.user.id);

const userId = usePage().props.auth.user.id;

const hasRoute = (name) => route().has(name);

// El admin no es cliente ni conductor (sección 9.5-C): el saludo de bienvenida
// de "armá tu flota"/"convertite en conductor" no le sirve de nada — su
// puerta de entrada real es el panel admin.
const isAdmin = usePage().props.auth.user.is_admin;

// Indicadores del panel admin (pedido explícito del usuario: "personas
// registradas, Pasajeros, conductores, cooperativas... esta semana. este
// mes. hoy... y cuando le de click alguno me lleve al modulo
// correspondiente con la lista"). "Personas registradas" no tiene un
// listado propio (es la suma de los otros roles + admins) — queda como
// indicador de solo lectura, sin link.
const adminIndicators = computed(() => {
    if (!props.adminStats) return [];

    return [
        { key: 'people', label: 'Personas registradas', route: null, ...props.adminStats.people },
        { key: 'clients', label: 'Pasajeros', route: 'admin.clients.index', ...props.adminStats.clients },
        { key: 'drivers', label: 'Conductores', route: 'admin.drivers.index', ...props.adminStats.drivers },
        { key: 'cooperatives', label: 'Cooperativas', route: 'admin.cooperatives.index', ...props.adminStats.cooperatives },
    ];
});

// Saludo de la cabecera (pedido explícito del usuario: bajarlo de la barra
// superior a acá, a la izquierda, en el lugar donde antes decía "Inicio").
const firstName = (usePage().props.auth.user.name ?? '').trim().split(/\s+/)[0] ?? '';

// Encuesta corta (pedido explícito del usuario: "un botón que me ayuda ir a
// una encuestas... bien ubicada" — visible directo en el Home, no solo
// dentro de "Más opciones") — mismo criterio de localStorage que Login.vue
// para no insistir a quien ya respondió.
const surveyDone = ref(typeof window !== 'undefined' && window.localStorage.getItem('arka01_survey_done') === '1');

const TRIP_STATUS = {
    pending: { label: 'Pendiente', class: 'bg-arka-warning/15 text-arka-warning' },
    confirmed: { label: 'Confirmado', class: 'bg-arka-primary/15 text-arka-primary-bright' },
    // Aceptada a partir de una solicitud PROGRAMADA (consideración agregada
    // al alcance), pero el conductor todavía no la arrancó.
    scheduled: { label: 'Programado', class: 'bg-arka-warning/15 text-arka-warning' },
};

function formatScheduledAt(iso) {
    return new Date(iso).toLocaleString('es-EC', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

// Rediseño UX (pedido explícito del usuario): mapa + buscador "¿A dónde
// vas?" arriba de Inicio (sección 4 del documento).
const homeLat = ref(null);
const homeLng = ref(null);
// Arranca con la ubicación de registro del cliente si la hay (ver
// DashboardController::index(), prop `homeInitialCenter`) — la
// geolocalización en vivo, si se consigue, la corrige después con más
// precisión (ver onMounted). Sin esto, sin permiso de ubicación el mapa se
// quedaba en el centro de Quito por defecto de FleetMap.vue, sin ningún
// indicio de qué había pasado.
const homeMapCenter = ref(props.homeInitialCenter);
const homeSearchQuery = ref('');
const destinationSelectionLoading = ref(false);
const destinationNavigationLoading = ref(false);
const destinationLoading = computed(() => destinationSelectionLoading.value || destinationNavigationLoading.value);
const destinationLoadingTitle = computed(() =>
    destinationSelectionLoading.value && !destinationNavigationLoading.value
        ? 'Ubicando tu destino'
        : 'Preparando tu recorrido'
);

// Móvil vs escritorio, DECIDIDO EN JAVASCRIPT (pedido explícito del usuario,
// tras dos rondas de bugs reales seguidas: "en tipo web dañaste, no se ve el
// navbar" + un hueco negro enorme entre el mapa y la tarjeta): la versión
// anterior usaba las MISMAS etiquetas HTML para los dos layouts, alternando
// `fixed`/`static`/`relative`/`absolute` con clases `sm:` — un elemento
// `position: static` ignora el `z-index` por completo, así que el mapa fijo
// (con su propio contexto de apilamiento) terminaba tapando la nav en
// escritorio sin que ningún z-index lo evitara, entre otros problemas de
// superposición difíciles de razonar a ciegas sin poder abrir un navegador.
// Ahora son dos ramas de plantilla TOTALMENTE separadas (ver el template más
// abajo) — móvil sigue con el mapa de fondo fijo a pantalla completa sin
// scroll; escritorio es una página normal, sin ningún `fixed`/`absolute`
// de por medio, mucho más simple de que salga bien.
const isDesktopViewport = ref(window.innerWidth >= 640);
function updateViewportKind() {
    isDesktopViewport.value = window.innerWidth >= 640;
}

// Bug real reportado por el usuario ("ayudame a que la ubicación actual
// aparezca en el centro de la pantalla del espacio del mapa, porque sale
// más abajo"): en móvil el bottom sheet tapa visualmente la mitad de abajo
// del mapa de fondo — sin esto, `setView()` centraba sobre el contenedor
// COMPLETO (los 100dvh de alto, incluida la parte tapada), así que el pin
// terminaba pegado al borde inferior de lo que de verdad se alcanza a ver.
// Se mide el alto REAL del bottom sheet (no un número fijo a ojo — cambia
// según cuántos "Recientes" hay) con `ResizeObserver`, y se lo pasa a
// FleetMap.vue (`center-offset-y`) para que corra el centro visual hacia
// arriba esa mitad.
const bottomSheetRef = ref(null);
const bottomSheetHeight = ref(0);
let bottomSheetObserver = null;

// El `ref` cambia de elemento cada vez que se alterna entre la rama móvil y
// la de escritorio (`isDesktopViewport`) — se re-observa cada vez que
// aparece uno nuevo, en vez de una sola vez en `onMounted()`.
watch(bottomSheetRef, (el) => {
    bottomSheetObserver?.disconnect();
    bottomSheetObserver = null;

    if (!el) {
        bottomSheetHeight.value = 0;
        return;
    }

    bottomSheetHeight.value = el.getBoundingClientRect().height;
    bottomSheetObserver = new ResizeObserver(() => {
        bottomSheetHeight.value = el.getBoundingClientRect().height;
    });
    bottomSheetObserver.observe(el);
});

// Bug real reportado por el usuario ("porque no centra la ubicación
// actual"): las dos llamadas a `getCurrentPosition()` de acá abajo (al
// montar, y al tocar el botón de recentrar) no tenían callback de error —
// con el permiso denegado, o el navegador tardando en preguntar, no pasaba
// nada visible ni en la consola, quedaba en silencio total. `locationDenied`
// deja un rastro chico y visible junto al botón de recentrar, para que no
// parezca que "no hace nada".
const locationDenied = ref(false);
const locationLoading = ref(Boolean(props.fleetDrivers));
function requestGeolocation(onSuccess) {
    if (props.fleetDrivers) locationLoading.value = true;

    if (!navigator.geolocation) {
        locationLoading.value = false;
        locationDenied.value = true;
        return;
    }

    navigator.geolocation.getCurrentPosition(
        (position) => {
            locationLoading.value = false;
            locationDenied.value = false;
            onSuccess(position);
        },
        (error) => {
            locationLoading.value = false;
            console.warn('Arka01: no se pudo obtener la ubicación en vivo del cliente.', error);
            locationDenied.value = true;
        },
        // Evita que la capa de carga quede indefinidamente si el GPS del
        // dispositivo no responde. Una posición reciente sirve para centrar
        // rápido y luego el usuario puede volver a solicitar mayor precisión.
        { enableHighAccuracy: true, timeout: 12_000, maximumAge: 30_000 }
    );
}

// Botón "mi ubicación" (pedido explícito del usuario: "necesitamos un botón
// de ubicación actual para que se centre el mapa") — `setView()` ya lo
// expone FleetMap.vue para esto mismo en otras pantallas. Sin ubicación
// todavía (permiso recién denegado, o tardío), la vuelve a pedir en vez de
// no hacer nada.
const fleetMapRef = ref(null);
function recenterMap() {
    if (homeLat.value != null && homeLng.value != null) {
        fleetMapRef.value?.setView(homeLat.value, homeLng.value, 15);
        return;
    }

    requestGeolocation((position) => {
        homeLat.value = position.coords.latitude;
        homeLng.value = position.coords.longitude;
        homeMapCenter.value = { lat: homeLat.value, lng: homeLng.value };
        fleetMapRef.value?.setView(homeLat.value, homeLng.value, 15);
    });
}

// Conductores activos en el mapa de Inicio (pedido explícito del usuario:
// "colocar todos los conductores sean de su flota o cooperativas... o
// públicos, que aparezcan solo los activos y cerca del origen de la
// carrera... en minutos") — `nearbyDrivers` ya viene filtrado/ordenado por
// cercanía desde DashboardController::nearbyActiveDriversFor(); acá solo se
// arman los marcadores (ícono de auto, igual que en el resto de la app) y un
// texto corto en minutos, nunca en km exacto (mismo criterio de privacidad
// que Ride/Request.vue y Directory/Index.vue).
//
// Color por categoría (pedido explícito del usuario, con imagen de
// referencia: "unos de mi flota, otros de cooperativa... colocar que sea
// amarillo, los públicos") — acá SÍ hay dato de cooperativa por unidad
// (`nearbyActiveDriversFor()` ya expone `source: 'fleet'|'cooperative'|'public'`
// por conductor), a diferencia de Ride/Request.vue, donde esa bolsa no trae
// unidades individuales. Mismo ámbar que la insignia de "Cooperativas" en
// el paso "Elige tu conductor" (`arka-warning`), para que sea el mismo
// color en toda la app.
const DRIVER_MARKER_COLOR = { fleet: '#34d399', cooperative: '#fbbf24', public: '#60a5fa' };
const nearbyDriverMarkers = computed(() =>
    (props.nearbyDrivers ?? []).map((driver) => ({
        id: 'car',
        lat: driver.lat,
        lng: driver.lng,
        color: DRIVER_MARKER_COLOR[driver.source] ?? DRIVER_MARKER_COLOR.public,
    }))
);

const nearbyDriversCaption = computed(() => {
    const drivers = props.nearbyDrivers ?? [];
    if (!drivers.length) return null;

    // Ya vienen ordenados por cercanía desde el backend — el primero es el más próximo.
    const minutes = etaMinutes(drivers[0].distance_km);
    const count = `${drivers.length} conductor${drivers.length === 1 ? '' : 'es'} activo${drivers.length === 1 ? '' : 's'} cerca`;

    return minutes != null ? `${count} · el más próximo llega en ${minutes} min` : count;
});

// Pedido explícito del usuario (documento formal de ajuste UX, sección 13):
// si ya se sabe la ubicación en vivo del cliente, se manda de una vez como
// origen — así la pantalla de "Elige tu conductor" no repite el pedido de
// geolocalización que ya se resolvió acá (ver App\Http\Controllers\RideRequestController::create(),
// prop `initialOrigin`).
async function resolveCurrentAddress(lat, lng) {
    try {
        const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`);
        const data = await response.json();
        return data?.display_name ?? null;
    } catch {
        return null;
    }
}

async function goToDestination({ lat, lng, address }) {
    destinationNavigationLoading.value = true;

    try {
        const originAddress = homeLat.value != null && homeLng.value != null
            ? await resolveCurrentAddress(homeLat.value, homeLng.value)
            : null;
        router.get(
            route('ride-requests.create'),
            {
                ...(homeLat.value != null && homeLng.value != null
                    ? { origin_lat: homeLat.value, origin_lng: homeLng.value, origin_address: originAddress ?? '' }
                    : {}),
                destination_lat: lat,
                destination_lng: lng,
                destination_address: address ?? '',
            },
            {
                onFinish: () => { destinationNavigationLoading.value = false; },
            },
        );
    } catch {
        destinationNavigationLoading.value = false;
    }
}

// Rediseño UX (pedido explícito del usuario): "Tu flota" y "Conductores que
// quizás conozcas" salieron de Inicio (siguen accesibles por la nav
// "Flotas" y por "Directorio de conductores" en el menú de accesos
// rápidos) — con eso se fue también el único motivo por el que este
// onMounted pedía geolocalización dos veces y escuchaba el canal de flota:
// ya no hay ninguna lista en pantalla que recolorear en vivo. Lo único que
// sigue haciendo falta es el marcador de "mi ubicación" en el mapa de
// arriba, y — pedido explícito del usuario, "conductores... cerca del
// origen de la carrera" — pedirle al servidor los conductores activos
// cercanos otra vez YA con la ubicación en vivo (llega sin ella en la carga
// inicial, `nearbyActiveDriversFor()` recién ahí cae al respaldo de la
// ubicación de registro). Recarga parcial (`only`), no se pierde nada del
// resto de la pantalla.
onMounted(() => {
    // Limpia de inmediato una URL generada por una versión anterior del
    // frontend. El backend también redirige esos enlaces para cubrir una
    // carga completa, pero replaceState evita que permanezcan visibles si
    // la actualización de assets ocurre con la página ya abierta.
    const currentUrl = new URL(window.location.href);
    if (currentUrl.searchParams.has('lat') || currentUrl.searchParams.has('lng')) {
        window.history.replaceState(window.history.state, '', route('dashboard'));
    }

    if (props.fleetDrivers) {
        window.addEventListener('resize', updateViewportKind);
    }

    requestGeolocation(async (position) => {
        homeLat.value = position.coords.latitude;
        homeLng.value = position.coords.longitude;
        homeMapCenter.value = { lat: homeLat.value, lng: homeLng.value };
        saveClientLocation({ lat: homeLat.value, lng: homeLng.value });

        if (props.fleetDrivers) {
            try {
                await window.axios.post(route('dashboard.location.update'), {
                    lat: homeLat.value,
                    lng: homeLng.value,
                });

                router.reload({
                    only: ['nearbyDrivers'],
                    preserveScroll: true,
                    preserveState: true,
                });
            } catch (error) {
                // El mapa local sigue funcionando aunque falle la
                // actualización de proximidad del servidor.
                console.error('No se pudo actualizar la ubicación del Dashboard.', error);
            }
        }
    });
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', updateViewportKind);
    bottomSheetObserver?.disconnect();
});

// Banner "Activarme"/"Desconectarme" del conductor (consideración agregada al
// alcance): el switch de DriverAvailabilityToggle avisa cada cambio acá para
// que el título y el ícono del banner reflejen el estado real, en vez de un
// texto fijo que no dice si tocarlo prende o apaga.
const isAvailableNow = ref(props.driverStats?.is_available ?? false);
const availabilityToggleRef = ref(null);

// Pedido explícito del usuario: apenas se activa, mandarlo derecho a
// WhatsApp a mandar el mensaje (no solo mostrar un aviso para tocar aparte)
// — mientras no tenga la ventana de 24h abierta. El banner de abajo queda
// como respaldo visible por si el navegador bloqueó la pestaña nueva.
//
// Se abre acá, en el manejador del evento del propio switch (no en un
// watch()), a propósito: los navegadores solo dejan abrir una pestaña nueva
// sin bloquearla como pop-up si `window.open` se llama synchronamente
// dentro de la cadena del click del usuario — un watch() corre después,
// como una reacción aparte, y ahí sí lo bloquean.
const showWhatsappPrompt = ref(isAvailableNow.value && whatsappOptInUrl && !whatsappSessionActive.value);
function handleAvailabilityChange(nowAvailable) {
    isAvailableNow.value = nowAvailable;

    if (nowAvailable && whatsappOptInUrl && !whatsappSessionActive.value) {
        showWhatsappPrompt.value = true;
        window.open(whatsappOptInUrl, '_blank');
    }
}

// Aviso en vivo de solicitudes nuevas (consideración agregada al alcance: se
// reportó pedirle una carrera a un conductor que estaba parado en su propio
// Inicio y no pasó nada visible — esta pantalla nunca escuchaba el evento,
// a diferencia de Ride/Index.vue). Mismo patrón: canal personal para las
// solicitudes dirigidas a mí, canal de cada flota activa para las de "toda
// la flota disponible" (ver RideRequested::broadcastOn()).
const pendingRequestsCount = ref(props.driverStats?.pending_requests ?? 0);
const newRequestAlert = ref(null);
const driverChannelListeners = [];

function listenToDriverChannel(channel, event, callback) {
    channel.listen(event, callback);
    driverChannelListeners.push({ channel, event, callback });
}

function handlePersonalNewRequest(e) {
    handleNewRequest(e, { alert: false });
}

function handleRideReminderDue() {
    playAttentionAlert();
}

function handleNewRequest(e, { alert = true } = {}) {
    // Aviso con sonido + vibración (pedido explícito del usuario) — más
    // confiable que la notificación push del sistema operativo, que muchos
    // navegadores no suenan si la pestaña ya está enfocada. La del canal
    // personal NO suena acá: ya la maneja el modal global de carrera entrante
    // (IncomingRideRequestModal, montado en AuthenticatedLayout) con un
    // aviso más fuerte — sonarían las dos a la vez si no se evitara.
    if (alert) playAttentionAlert();
    pendingRequestsCount.value++;
    newRequestAlert.value = { clientName: e.client_name, price: e.current_offered_price, isScheduled: e.is_scheduled };
}

function handleRequestGoneWhileWaiting() {
    pendingRequestsCount.value = Math.max(0, pendingRequestsCount.value - 1);
    if (pendingRequestsCount.value === 0) newRequestAlert.value = null;
}

// Aviso en vivo de invitaciones a flota nuevas (se reportó: un cliente
// agregó a un conductor y a este no le llegó ningún aviso porque solo
// "Mis clientes de confianza" escuchaba este evento — si el conductor
// estaba parado en el Inicio, como acá, no pasaba nada visible). Mismo
// patrón que handleNewRequest().
const newInvitationAlert = ref(null);

function handleNewInvitation(e) {
    playAttentionAlert();
    newInvitationAlert.value = { ownerName: e.owner_name };
    setTimeout(() => {
        if (newInvitationAlert.value?.ownerName === e.owner_name) {
            newInvitationAlert.value = null;
        }
    }, 12000);
}

onMounted(() => {
    if (!props.driverStats) return;

    window.addEventListener('arka:ride-request-answered', handleRequestGoneWhileWaiting);

    const personal = window.Echo.private(`App.Models.User.${userId}`);
    listenToDriverChannel(personal, '.ride-request.created', handlePersonalNewRequest);
    listenToDriverChannel(personal, '.ride-request.cancelled', handleRequestGoneWhileWaiting);
    listenToDriverChannel(personal, '.fleet-invitation.created', handleNewInvitation);
    // Recordatorio de 15-20 min antes de una carrera programada (pedido
    // explícito del usuario) — "Próximos viajes" ya muestra la hora, acá
    // solo hace falta el aviso sonoro.
    listenToDriverChannel(personal, '.ride.reminder-due', handleRideReminderDue);

    (props.driverFleetIds ?? []).forEach((fleetId) => {
        const channel = window.Echo.private(`fleet.${fleetId}`);
        listenToDriverChannel(channel, '.ride-request.created', handleNewRequest);
        listenToDriverChannel(channel, '.ride-request.cancelled', handleRequestGoneWhileWaiting);
    });
});

onBeforeUnmount(() => {
    driverChannelListeners.forEach(({ channel, event, callback }) => channel.stopListening(event, callback));
    window.removeEventListener('arka:ride-request-answered', handleRequestGoneWhileWaiting);
});

// Sparkline de ganancias (consideración agregada al alcance, mockup del
// conductor): una sola serie, sin librería de gráficos — un <polyline> plano
// armado a partir de los últimos 14 días que manda el backend.
const sparklinePoints = computed(() => {
    const data = props.earningsSparkline ?? [];
    if (!data.length) return '';
    const max = Math.max(...data, 1);
    const stepX = 100 / (data.length - 1 || 1);
    return data.map((value, i) => `${(i * stepX).toFixed(1)},${(32 - (value / max) * 32).toFixed(1)}`).join(' ');
});

// Compartir el código de invitación (consideración agregada al alcance,
// mockup del conductor) — mismo `invite_code` que ya se muestra con QR en
// Driver/Profile.vue, solo que acá con copiar rápido.
const codeCopied = ref(false);
function copyInviteCode() {
    navigator.clipboard?.writeText(props.inviteCode).then(() => {
        codeCopied.value = true;
        setTimeout(() => (codeCopied.value = false), 2000);
    });
}

// Carrera en curso sin cerrar (pedido explícito del usuario: si a alguna de
// las dos partes se le apagó el celular con una carrera en curso, mostrarle
// un aviso claro cuando vuelva de que tiene algo pendiente) — se calcula acá
// mismo a partir de "Próximos viajes", que ya trae los `Ride` en_progress con
// `ride_id`, en vez de agregar otra ida al servidor.
const pendingRideToClose = computed(() => (props.upcomingTrips ?? []).find((trip) => trip.status === 'confirmed' && trip.ride_id));
</script>

<template>
    <Head title="Inicio" />

    <AuthenticatedLayout :transparent-nav="!!fleetDrivers">
        <ArkaRouteLoader :show="destinationLoading" :title="destinationLoadingTitle" />
        <!-- Pedido explícito del usuario (documento formal de ajuste UX):
             en Inicio del pasajero la nav flota FIJA sobre el mapa (ver
             `transparent-nav` en AuthenticatedLayout.vue, ahora con
             `position: fixed` de verdad, no `absolute` — eso fue lo que
             falló la vez anterior) — sin una cabecera sólida aparte que
             tape el mapa. Admin/conductor no cambian. -->
        <template v-if="!fleetDrivers" #header>
            <!-- Pedido explícito del usuario: el switch de disponibilidad
                 subió a la cabecera (antes era un banner grande más abajo,
                 para que quede visible sin hacer scroll) — pero conservando
                 el mismo diseño "bonito" que tenía ese banner (ícono +
                 título + subtítulo), no solo el switch pelado. En pantallas
                 chicas el título/subtítulo se ocultan (queda ícono + switch)
                 para no romper el layout de la cabecera junto con "Inicio". -->
            <div class="flex items-center justify-between gap-3">
                <!-- Pedido explícito del usuario: el saludo baja de la barra
                     superior a acá (donde antes decía "Inicio"), a la izquierda. -->
                <div class="text-start">
                    <h2 class="font-semibold text-xl text-arka-text leading-tight">¡Hola, {{ firstName }}! 👋</h2>
                    <!-- Pedido explícito del usuario ("conductor activo y el
                         estado colocalo donde tienes el enlace de la
                         encuesta"): la encuesta se movió al final de la
                         pantalla (ver más abajo) — acá queda el estado de
                         disponibilidad, que es información funcional que se
                         usa todos los días. "Conductor ✓" (qué ES la cuenta)
                         se sacó de acá por redundante: ya se ve en el menú
                         de cuenta (AuthenticatedLayout.vue), que se abre
                         tocando la foto. -->
                    <span
                        v-if="driverStats"
                        class="mt-1 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                        :class="!driverStats.can_connect ? 'bg-arka-warning/15 text-arka-warning' : isAvailableNow ? 'bg-arka-primary/15 text-arka-primary-bright' : 'bg-arka-text-muted/15 text-arka-text-muted'"
                    >
                        {{ !driverStats.can_connect ? '● Perfil pendiente' : isAvailableNow ? '● Disponible' : '○ No disponible' }}
                    </span>
                </div>
            </div>
        </template>

        <div v-if="!fleetDrivers" class="py-12">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Carrera en curso sin cerrar (consideración agregada al alcance):
                     si se le apagó el celular a alguna de las dos partes con una
                     carrera en curso, esto es lo primero que ve al volver a entrar. -->
                <Link
                    v-if="pendingRideToClose"
                    :href="route('rides.show', pendingRideToClose.ride_id)"
                    class="p-4 bg-arka-warning/15 border border-arka-warning/40 rounded-arka flex items-center justify-between gap-4 hover:bg-arka-warning/20"
                >
                    <div>
                        <p class="font-semibold text-arka-warning">⚠️ Tiene una carrera en curso sin cerrar</p>
                        <p class="text-sm text-arka-text-muted">
                            Con {{ pendingRideToClose.counterpart_name }} — {{ pendingRideToClose.origin_label }} &rarr;
                            {{ pendingRideToClose.destination_label }}. Tocá para continuar.
                        </p>
                    </div>
                    <span class="text-arka-warning text-sm font-medium shrink-0">Continuar &rarr;</span>
                </Link>

                <div v-if="isAdmin" class="bg-arka-card overflow-hidden shadow-sm rounded-arka">
                    <div class="p-6 text-arka-text space-y-2">
                        <p>
                            Esta cuenta administra la plataforma: suscripciones, planes, tarifas, indicadores,
                            verificación de conductores y alertas SOS.
                        </p>
                        <Link
                            v-if="hasRoute('admin.subscriptions.index')"
                            :href="route('admin.subscriptions.index')"
                            class="block text-arka-warning hover:opacity-80 font-medium"
                        >
                            Ir al panel admin &rarr;
                        </Link>
                    </div>
                </div>

                <!-- Indicadores (pedido explícito del usuario: "personas
                     registradas, Pasajeros, conductores, cooperativas...
                     esta semana. este mes. hoy... y cuando le de click
                     alguno me lleve al modulo correspondiente con la
                     lista") — cada tarjeta con link es la puerta directa al
                     listado filtrado de ese módulo. -->
                <div v-if="isAdmin && adminIndicators.length" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <component
                        :is="stat.route && hasRoute(stat.route) ? Link : 'div'"
                        v-for="stat in adminIndicators"
                        :key="stat.key"
                        :href="stat.route && hasRoute(stat.route) ? route(stat.route) : undefined"
                        class="rounded-2xl border border-arka-text-muted/10 bg-arka-card p-4"
                        :class="stat.route ? 'transition hover:border-arka-warning/30' : ''"
                    >
                        <p class="text-sm font-semibold text-arka-text">{{ stat.label }}</p>
                        <p class="text-xs text-arka-text-muted">{{ stat.total }} en total</p>
                        <div class="mt-3 grid grid-cols-3 gap-1 text-center">
                            <div>
                                <p class="text-lg font-bold text-arka-text">{{ stat.today }}</p>
                                <p class="text-[11px] text-arka-text-muted">Hoy</p>
                            </div>
                            <div>
                                <p class="text-lg font-bold text-arka-text">{{ stat.week }}</p>
                                <p class="text-[11px] text-arka-text-muted">Semana</p>
                            </div>
                            <div>
                                <p class="text-lg font-bold text-arka-text">{{ stat.month }}</p>
                                <p class="text-[11px] text-arka-text-muted">Mes</p>
                            </div>
                        </div>
                    </component>
                </div>

                <!-- Secciones agrupadas del panel admin (pedido explícito del
                     usuario): tocar una tarjeta la expande mostrando sus
                     enlaces debajo, y colapsa cualquier otra que estuviera
                     abierta. -->
                <div v-if="isAdmin" class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <div
                        v-for="group in ADMIN_NAV_GROUPS"
                        :key="group.key"
                        class="rounded-2xl border transition"
                        :class="expandedAdminGroup === group.key ? 'border-arka-warning/40 bg-arka-warning/5 col-span-2 sm:col-span-3' : 'border-arka-text-muted/10 bg-arka-card hover:border-arka-warning/30'"
                    >
                        <button
                            type="button"
                            class="flex w-full flex-col items-center gap-2 p-4 text-center"
                            @click="toggleAdminGroup(group.key)"
                        >
                            <span class="grid h-11 w-11 place-items-center rounded-full bg-arka-warning/15 text-arka-warning">
                                <span class="h-5 w-5"><AdminNavIcon :icon="group.icon" /></span>
                            </span>
                            <span class="text-sm font-semibold text-arka-text">{{ group.label }}</span>
                        </button>

                        <div v-if="expandedAdminGroup === group.key" class="grid gap-1 border-t border-arka-text-muted/10 p-2 sm:grid-cols-2">
                            <Link
                                v-for="item in group.items"
                                :key="item.route"
                                :href="route(item.route)"
                                class="rounded-arka px-3 py-2 text-sm text-arka-text-muted hover:bg-arka-base hover:text-arka-text"
                            >
                                {{ item.label }}
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Como conductor (consideración agregada al alcance: mockup del
                     conductor provisto por el usuario). -->
                <template v-else-if="driverStats">
                    <!-- Aviso de solicitud nueva mientras estás parado en el inicio
                         (consideración agregada al alcance) — antes no pasaba nada
                         visible acá, solo en la pantalla de Carreras. -->
                    <div
                        v-if="newRequestAlert || pendingRequestsCount > 0"
                        class="relative overflow-hidden rounded-2xl border-2 border-arka-primary bg-arka-card p-4 shadow-[0_18px_45px_rgba(52,211,153,0.22)] ring-4 ring-arka-primary/10"
                        role="alert"
                        aria-live="assertive"
                    >
                        <span class="absolute right-0 top-0 h-24 w-24 -translate-y-1/2 translate-x-1/2 rounded-full bg-arka-primary/15" aria-hidden="true"></span>
                        <div class="relative flex items-start gap-3">
                            <div class="relative grid h-12 w-12 shrink-0 place-items-center rounded-full bg-arka-primary text-arka-base shadow-lg shadow-arka-primary/30">
                                <span class="absolute inset-0 animate-ping rounded-full bg-arka-primary/40" aria-hidden="true"></span>
                                <svg class="relative h-6 w-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <path d="M12 2.25a6.25 6.25 0 0 0-6.25 6.25v2.04c0 .87-.28 1.72-.8 2.42l-1.3 1.74A1.75 1.75 0 0 0 5.05 17.5h13.9a1.75 1.75 0 0 0 1.4-2.8l-1.3-1.74a4.03 4.03 0 0 1-.8-2.42V8.5A6.25 6.25 0 0 0 12 2.25Z" />
                                    <path d="M9.4 19a2.75 2.75 0 0 0 5.2 0H9.4Z" />
                                </svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-arka-primary">Acción prioritaria</p>
                                <p class="mt-0.5 text-lg font-bold text-arka-text">{{ newRequestAlert?.isScheduled ? 'Nueva carrera programada' : 'Tiene una solicitud de carrera' }}</p>
                                <p v-if="newRequestAlert" class="mt-1 text-sm text-arka-text-muted">
                                    {{ newRequestAlert.clientName }} le {{ newRequestAlert.isScheduled ? 'programó una carrera' : 'ofrece' }}
                                    <strong class="text-arka-primary-bright">${{ Number(newRequestAlert.price).toFixed(2) }}</strong>
                                </p>
                                <p v-else class="mt-1 text-sm text-arka-text-muted">Tiene {{ pendingRequestsCount }} solicitud{{ pendingRequestsCount === 1 ? '' : 'es' }} esperando respuesta.</p>
                            </div>
                        </div>
                        <div class="relative mt-4 flex items-center gap-2">
                            <Link :href="route('rides.index')" class="flex min-h-12 flex-1 items-center justify-center gap-2 rounded-xl bg-arka-primary px-4 text-sm font-bold uppercase tracking-wide text-arka-base shadow-lg shadow-arka-primary/20">
                                Ver solicitud
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.22 4.72a.75.75 0 0 1 1.06 0l6.75 6.75a.75.75 0 0 1 0 1.06l-6.75 6.75a.75.75 0 1 1-1.06-1.06l5.47-5.47H3.5a.75.75 0 0 1 0-1.5h15.19l-5.47-5.47a.75.75 0 0 1 0-1.06Z" /></svg>
                            </Link>
                            <span class="rounded-full bg-arka-primary/15 px-3 py-1.5 text-xs font-bold text-arka-primary-bright">{{ pendingRequestsCount }} pendiente{{ pendingRequestsCount === 1 ? '' : 's' }}</span>
                        </div>
                    </div>

                    <!-- Aviso de invitación a flota nueva (se reportó: agregar a un
                         conductor no le avisaba nada si estaba parado acá). -->
                    <div
                        v-if="newInvitationAlert"
                        class="p-4 bg-arka-primary text-arka-base rounded-arka flex items-center justify-between gap-4"
                    >
                        <div>
                            <p class="font-semibold">¡Le invitaron a una flota!</p>
                            <p class="text-sm">{{ newInvitationAlert.ownerName }} le quiere agregar a su flota de confianza.</p>
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            <Link :href="route('driver.invitations.index')" class="px-3 py-1.5 rounded-arka bg-arka-base text-arka-primary-bright text-sm font-medium">
                                Ver
                            </Link>
                            <button type="button" class="text-arka-base/70 hover:text-arka-base" @click="newInvitationAlert = null">
                                ✕
                            </button>
                        </div>
                    </div>

                    <!-- Disponibilidad compacta: sigue siendo el control más
                         accesible del Inicio, pero deja el protagonismo visual
                         a los resultados diarios del conductor. -->
                    <section
                        class="rounded-2xl border p-3 shadow-sm"
                        :class="!driverStats.can_connect
                            ? 'border-arka-warning/35 bg-arka-warning/5'
                            : isAvailableNow
                                ? 'border-arka-primary/25 bg-arka-primary/5'
                                : 'border-arka-text-muted/15 bg-arka-card'"
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="grid h-9 w-9 shrink-0 place-items-center rounded-xl"
                                :class="!driverStats.can_connect
                                    ? 'bg-arka-warning/15 text-arka-warning'
                                    : isAvailableNow
                                        ? 'bg-arka-primary/15 text-arka-primary-bright'
                                        : 'bg-arka-text-muted/15 text-arka-text-muted'"
                            >
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v8" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 5.5a8 8 0 1 0 10 0" />
                                </svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold" :class="isAvailableNow && driverStats.can_connect ? 'text-arka-primary-bright' : 'text-arka-text'">
                                    {{ !driverStats.can_connect
                                        ? 'Perfil pendiente'
                                        : isAvailableNow
                                            ? 'Disponible para carreras'
                                            : 'No disponible' }}
                                </p>
                                <p class="truncate text-[11px] text-arka-text-muted">
                                    {{ !driverStats.can_connect ? 'Complete los requisitos para conectarse' : isAvailableNow ? 'Sus clientes pueden solicitarle viajes' : 'Active el estado cuando empiece a trabajar' }}
                                </p>
                            </div>
                            <Link
                                v-if="!driverStats.can_connect"
                                :href="route('driver.profile.edit')"
                                class="shrink-0 text-xs font-semibold text-arka-warning underline underline-offset-2"
                            >
                                Completar
                            </Link>
                            <DriverAvailabilityToggle
                                v-else
                                ref="availabilityToggleRef"
                                :initial-available="driverStats.is_available"
                                :can-connect="driverStats.can_connect"
                                :blocked-reason="driverStats.connection_block_reason"
                                :show-label="false"
                                @update:available="handleAvailabilityChange"
                            />
                        </div>
                        <p v-if="!driverStats.can_connect" class="mt-2 border-t border-arka-warning/15 pt-2 text-[11px] leading-relaxed text-arka-warning">
                            {{ driverStats.connection_block_reason }}
                        </p>
                    </section>

                    <!-- Los resultados aparecen inmediatamente después del
                         estado. En móvil, el conductor ve cuánto produjo y
                         cuántos viajes realizó sin tener que desplazarse por
                         la agenda ni interpretar cifras históricas como si
                         fueran del día. -->
                    <section class="overflow-hidden rounded-2xl border border-arka-text-muted/10 bg-arka-card shadow-sm">
                        <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-arka-primary">Resultados</p>
                                <h3 class="mt-0.5 font-semibold text-arka-text">Así va su trabajo</h3>
                            </div>
                            <svg viewBox="0 0 100 32" preserveAspectRatio="none" class="h-7 w-20 shrink-0" aria-label="Tendencia de ingresos de los últimos 14 días">
                                <polyline
                                    :points="sparklinePoints"
                                    fill="none"
                                    class="stroke-arka-primary"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                        </div>

                        <div class="grid grid-cols-2 gap-px bg-arka-text-muted/10">
                            <div class="bg-arka-primary/10 p-4">
                                <div class="flex items-center gap-1.5 text-xs font-medium text-arka-text-muted">
                                    <svg class="h-4 w-4 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16v10H4zM8 12h.01M16 12h.01M12 9.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5Z" />
                                    </svg>
                                    Ingresos de hoy
                                </div>
                                <p class="mt-1 text-3xl font-bold tracking-tight text-arka-primary-bright">${{ driverStats.earnings_today.toFixed(2) }}</p>
                                <p class="mt-1 text-[10px] text-arka-text-muted">Generado durante el día</p>
                            </div>
                            <Link :href="route('rides.index')" class="bg-arka-card p-4 transition hover:bg-arka-base/40">
                                <div class="flex items-center gap-1.5 text-xs font-medium text-arka-text-muted">
                                    <svg class="h-4 w-4 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 17h14l-1-6-2-4H8l-2 4-1 6ZM3 13h18M7 17v2m10-2v2" />
                                    </svg>
                                    Viajes de hoy
                                </div>
                                <p class="mt-1 text-3xl font-bold tracking-tight text-arka-text">{{ driverStats.completed_rides_today }}</p>
                                <p class="mt-1 text-[10px] text-arka-text-muted">Carreras completadas</p>
                            </Link>
                        </div>

                        <div class="grid grid-cols-2 gap-px border-t border-arka-text-muted/10 bg-arka-text-muted/10 sm:grid-cols-4">
                            <div class="bg-arka-card px-3 py-3">
                                <p class="text-[10px] leading-tight text-arka-text-muted">Ingresos del mes</p>
                                <p class="mt-1 text-lg font-semibold text-arka-text">${{ driverStats.earnings_this_month.toFixed(2) }}</p>
                            </div>
                            <Link :href="route('rides.index')" class="bg-arka-card px-3 py-3 transition hover:bg-arka-base/40">
                                <p class="text-[10px] leading-tight text-arka-text-muted">Viajes del mes</p>
                                <p class="mt-1 text-lg font-semibold text-arka-text">{{ driverStats.completed_rides_this_month }}</p>
                                <p class="text-[9px] text-arka-text-muted">{{ driverStats.completed_rides }} históricos</p>
                            </Link>
                            <Link :href="route('driver.invitations.index')" class="bg-arka-card px-3 py-3 transition hover:bg-arka-base/40">
                                <p class="text-[10px] leading-tight text-arka-text-muted">Clientes</p>
                                <p class="mt-1 text-lg font-semibold text-arka-text">{{ driverStats.active_clients }}</p>
                                <p class="text-[9px] text-arka-text-muted">De confianza</p>
                            </Link>
                            <div class="bg-arka-card px-3 py-3">
                                <p class="text-[10px] leading-tight text-arka-text-muted">Calificación</p>
                                <p v-if="driverStats.review_count > 0" class="mt-1 text-lg font-semibold text-arka-lime">{{ driverStats.rating.toFixed(1) }}<span class="text-xs font-medium">/5</span></p>
                                <p v-else class="mt-1 text-sm font-medium text-arka-text-muted">Sin calificar</p>
                                <p class="text-[9px] text-arka-text-muted">{{ driverStats.review_count }} reseña{{ driverStats.review_count === 1 ? '' : 's' }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- Después del resumen, la agenda responde cuál es la
                         siguiente tarea sin competir con los indicadores. -->
                    <section v-if="upcomingTrips" class="rounded-2xl border border-arka-text-muted/10 bg-arka-card p-4 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-arka-primary">Agenda</p>
                                <h3 class="mt-1 font-semibold text-arka-text">Próximo viaje</h3>
                            </div>
                            <Link :href="route('rides.index')" class="text-xs font-semibold text-arka-primary hover:text-arka-primary-bright">
                                Ver solicitudes →
                            </Link>
                        </div>

                        <div v-if="!upcomingTrips.length" class="mt-3 flex items-center gap-3 rounded-xl border border-dashed border-arka-text-muted/20 bg-arka-base/35 p-3">
                            <div class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-arka-primary/10 text-arka-primary">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 6V3m8 3V3M4 9h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z" />
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-arka-text">Sin viajes pendientes</p>
                                <p class="text-xs text-arka-text-muted">Cuando reciba o programe uno, aparecerá aquí.</p>
                            </div>
                        </div>

                        <Link
                            v-else
                            :href="upcomingTrips[0].ride_id ? route('rides.show', upcomingTrips[0].ride_id) : route('rides.index')"
                            class="mt-3 block rounded-xl border border-arka-primary/15 bg-arka-base/45 p-3 transition hover:border-arka-primary/35"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="TRIP_STATUS[upcomingTrips[0].status].class">
                                        {{ TRIP_STATUS[upcomingTrips[0].status].label }}
                                    </span>
                                    <p class="mt-2 truncate text-sm font-semibold text-arka-text">{{ upcomingTrips[0].origin_label }} → {{ upcomingTrips[0].destination_label }}</p>
                                    <p class="mt-0.5 truncate text-xs text-arka-text-muted">Con {{ upcomingTrips[0].counterpart_name }}</p>
                                    <p v-if="upcomingTrips[0].status === 'scheduled'" class="mt-1 text-xs font-medium text-arka-warning">
                                        {{ formatScheduledAt(upcomingTrips[0].scheduled_at) }}
                                        <span v-if="upcomingTrips[0].round_trip"> · Ida y vuelta</span>
                                    </p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="text-lg font-bold text-arka-primary-bright">${{ upcomingTrips[0].price.toFixed(2) }}</p>
                                    <p class="text-[10px] text-arka-text-muted">Ver detalle →</p>
                                </div>
                            </div>
                        </Link>
                    </section>

                    <div>
                        <!-- Pedido explícito del usuario: "lo de que es
                             conductor colocalo en la barra donde se
                             despliega desde la foto", el estado de
                             disponibilidad ahora vive junto al saludo arriba,
                             y la etiqueta de cooperativa se mudó al menú de
                             cuenta (AuthenticatedLayout.vue, "colocalo alli
                             como una etiqueta mas... debajo de la que dice
                             conductor") — acá queda solo el aviso de ubicación,
                             que sí es propio de esta tarjeta. -->
                        <!-- Bug reportado por el usuario: un conductor con el switch
                             prendido podía seguir viéndose "Desconectado" en el
                             roster de sus clientes, sin ningún aviso de por qué —
                             pasaba sin ping de ubicación reciente (más de 2 min,
                             ver DriverProfile::isReachable()) y sin la ventana de
                             WhatsApp abierta como respaldo. Antes esto era invisible
                             para el propio conductor. -->
                        <p v-if="driverStats.is_available && !driverStats.is_reachable" class="text-xs text-arka-warning mt-1 max-w-sm">
                            ⚠️ Sin ubicación reciente — sus clientes pueden seguir viéndolo desconectado. Revise que el
                            navegador tenga permiso de ubicación y que la app siga abierta.
                            <!-- Pedido explícito del usuario ("debería ser automático o por lo
                                 menos un botón que refresque"): además del auto-resume al cargar
                                 la página (DriverAvailabilityToggle.vue, onMounted), este botón
                                 fuerza un ping ya mismo sin esperar al próximo de forma automática. -->
                            <button
                                type="button"
                                class="ms-1 underline hover:no-underline font-medium"
                                @click="availabilityToggleRef?.refreshNow()"
                            >
                                Actualizar ubicación ahora
                            </button>
                        </p>
                    </div>

                    <!-- Pedido explícito del usuario: mostrar en Inicio, de un
                         vistazo, la tarifa por km y la tarifa base que tiene
                         declaradas — de solo lectura acá, con un link directo
                         al formulario para corregirlas (sin tener que ir a
                         buscarlo en Mi perfil). -->
                    <Link
                        :href="`${route('driver.profile.edit')}#rate_per_km`"
                        class="block rounded-2xl border border-arka-text-muted/10 bg-arka-card p-4 shadow-sm transition hover:border-arka-primary/40"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-arka-primary">Configuración</p>
                                <p class="mt-1 text-sm font-semibold text-arka-text">Tarifa de trabajo</p>
                            </div>
                            <span class="text-xs font-semibold text-arka-primary">Cambiar →</span>
                        </div>
                        <div class="mt-3 flex items-center gap-6 rounded-xl bg-arka-base/45 px-3 py-2.5">
                            <div>
                                <p class="text-lg font-semibold text-arka-text">
                                    <template v-if="driverStats.rate_per_km != null">${{ driverStats.rate_per_km.toFixed(2) }}</template>
                                    <template v-else>—</template>
                                </p>
                                <p class="text-[10px] text-arka-text-muted">Por kilómetro</p>
                            </div>
                            <div class="h-8 w-px bg-arka-text-muted/15"></div>
                            <div>
                                <p class="text-lg font-semibold text-arka-text">
                                    <template v-if="driverStats.minimum_fare != null">${{ driverStats.minimum_fare.toFixed(2) }}</template>
                                    <template v-else>Sin mínimo</template>
                                </p>
                                <p class="text-[10px] text-arka-text-muted">Tarifa base</p>
                            </div>
                        </div>
                    </Link>

                    <!-- Las promociones quedan después de la información
                         operativa para no interrumpir las tareas del conductor. -->
                    <AdBannerSlider :banners="adBanners" />

                    <!-- Pedido explícito del usuario: "eliminemos esta parte
                         [Acciones rápidas] y coloca solicitudes en el
                         navbar... y la de cliente dejemosla en la opcion de
                         +" — "Solicitudes" ahora es el badge de la pestaña
                         "Carreras" de la nav inferior (AuthenticatedLayout.vue);
                         "Mis clientes" y "Mi perfil" ya vivían en el "+"
                         (Accesos rápidos, ver quickLinks) sin duplicarlos acá. -->

                    <!-- Compartí tu código (consideración agregada al alcance): mismo
                         invite_code que ya existe con QR en Mi perfil de conductor.
                    <div v-if="inviteCode" class="p-4 bg-arka-card shadow rounded-arka flex items-center justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-arka-text font-medium">Comparta su código</p>
                            <p class="text-xs text-arka-text-muted">Un cliente nuevo puede agregarlo a su flota con este código.</p>
                            <p class="mt-1 text-lg font-mono tracking-widest text-arka-primary-bright">{{ inviteCode }}</p>
                        </div>
                        <SecondaryButton class="shrink-0" @click="copyInviteCode">
                            {{ codeCopied ? 'Copiado' : 'Copiar' }}
                        </SecondaryButton>
                    </div>-->

                    <!-- Pedido explícito del usuario: al activarse, ya se intentó abrir
                         WhatsApp solo (handleAvailabilityChange) — esto queda como
                         respaldo visible por si el navegador bloqueó la pestaña nueva,
                         o si la pantalla cargó con el conductor ya disponible. Se puede
                         cerrar sin conectar (no bloquea nada) — vuelve a aparecer la
                         próxima vez que se active mientras siga sin ventana. Mismo
                         lenguaje de "pasos" que el widget de recuperar sesión del login,
                         para que se sienta el mismo mecanismo en toda la app. -->
                    <div
                        v-if="showWhatsappPrompt"
                        class="p-3 rounded-arka bg-arka-primary/10 flex items-center justify-between gap-3 text-sm"
                    >
                        <span class="text-arka-text">
                            📲 Conéctese por WhatsApp y siga recibiendo solicitudes aunque cierre la app o la deje en
                            segundo plano. 1. Mándenos ese WhatsApp ya listo (si no se abrió la pestaña, toque acá) —
                            2. apenas lo envíe, queda conectado.
                        </span>
                        <div class="flex items-center gap-3 shrink-0">
                            <a
                                :href="whatsappOptInUrl"
                                target="_blank"
                                rel="noopener"
                                class="text-arka-primary hover:text-arka-primary-bright font-medium"
                                @click="showWhatsappPrompt = false"
                            >
                                Abrir WhatsApp
                            </a>
                            <button type="button" class="text-arka-text-muted hover:text-arka-text" @click="showWhatsappPrompt = false">
                                ✕
                            </button>
                        </div>
                    </div>

                    <!-- Encuesta corta (pedido explícito del usuario: "lo de
                         la encuesta mandalo al final") — antes competía con
                         el saludo arriba de todo; acá abajo sigue siendo
                         fácil de encontrar sin taparle el paso a lo que se
                         usa todos los días (disponibilidad, solicitudes, tarifa). -->
                    <Link
                        v-if="!surveyDone"
                        :href="route('survey.show')"
                        class="block text-center text-sm font-medium text-arka-primary hover:text-arka-primary-bright"
                    >
                        Cuéntanos tu experiencia (2 min) →
                    </Link>
                </template>

            </div>
        </div>

        <!-- Inicio del pasajero: dos ramas de plantilla SEPARADAS para móvil
             y escritorio, decidido en JavaScript (`isDesktopViewport`, ver
             comentario junto a su declaración) — tras dos rondas de bugs
             reales con una sola plantilla alternando `fixed`/`static`/
             `absolute` por clases `sm:` (la nav quedaba tapada en
             escritorio, un hueco negro enorme entre el mapa y la tarjeta),
             separarlas de verdad es mucho más simple de razonar y de que
             salga bien. -->
        <template v-else>
            <!-- MÓVIL: mapa de fondo fijo a toda la pantalla (pedido
                 explícito: "quisiera no scrollear que todo quede en la
                 misma pantalla"), nav flotando fija encima (ver
                 `transparent-nav`, AuthenticatedLayout.vue), bottom sheet
                 anclado abajo. -->
            <template v-if="!isDesktopViewport">
                <div class="fixed inset-0 z-0">
                    <FleetMap
                        ref="fleetMapRef"
                        :markers="[
                            ...(homeLat != null ? [{ id: 'origin', lat: homeLat, lng: homeLng, label: 'Mi ubicación' }] : []),
                            ...nearbyDriverMarkers,
                        ]"
                        :center="homeMapCenter ?? undefined"
                        :clickable="true"
                        :auto-fit="false"
                        :dark="false"
                        :rounded="false"
                        height="100%"
                        controls-top-offset="64px"
                        :center-offset-y="bottomSheetHeight"
                        @map-click="({ lat, lng }) => goToDestination({ lat, lng })"
                    />

                    <!-- Feedback inmediato mientras el navegador obtiene el
                         GPS y FleetMap ajusta el centro/zoom. Sin esta capa el
                         mapa general parecía congelado antes del salto a la
                         ubicación real. No bloquea los controles ni el sheet. -->
                    <div
                        v-if="locationLoading"
                        class="pointer-events-none absolute inset-0 z-[9] flex items-center justify-center bg-white/45 backdrop-blur-[1px]"
                        role="status"
                        aria-live="polite"
                    >
                        <div class="flex items-center gap-3 rounded-full border border-white/80 bg-arka-base/85 px-4 py-3 text-sm font-medium text-white shadow-xl backdrop-blur-md">
                            <span class="h-5 w-5 animate-spin rounded-full border-2 border-white/35 border-t-arka-primary" aria-hidden="true"></span>
                            <span>Ubicando y ajustando el mapa…</span>
                        </div>
                    </div>

                    <!-- Conductores activos cerca — insignia discreta, debajo
                         de la nav, sin competir con "¿A dónde vas?". Nunca en
                         km exacto (mismo criterio de privacidad que
                         Ride/Request.vue y Directory/Index.vue). Pedido
                         explícito del usuario ("no se ve profesional, el
                         ícono ni se ve serio"): el emoji 🚗 se reemplazó por
                         un punto "en vivo" pulsando — mismo lenguaje visual
                         que un indicador de estado, sin depender de cómo cada
                         sistema operativo dibuje un emoji. -->
                    <p
                        v-if="nearbyDriversCaption"
                        class="absolute left-3 top-[4.5rem] z-10 flex max-w-[calc(100%-4.75rem)] items-center gap-2 rounded-full border border-white/10 bg-arka-base/75 py-2 pl-3 pr-3.5 text-xs font-semibold text-white shadow-lg backdrop-blur-md"
                    >
                        <span class="relative flex h-2 w-2 shrink-0">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-arka-primary opacity-75"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-arka-primary"></span>
                        </span>
                        <span class="truncate">{{ nearbyDriversCaption }}</span>
                    </p>

                    <!-- Botón "mi ubicación" (pedido explícito del usuario) —
                         reutiliza `setView()`, ya expuesto por FleetMap.vue
                         para esto mismo en otras pantallas. -->
                    <button
                        type="button"
                        class="absolute right-3 top-[4.5rem] z-10 flex h-11 w-11 items-center justify-center rounded-full border border-black/5 bg-white text-arka-base/70 shadow-xl transition hover:text-arka-primary active:scale-95"
                        aria-label="Centrar en mi ubicación"
                        @click="recenterMap"
                    >
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="3" stroke-linecap="round" stroke-linejoin="round" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 2v3M12 19v3M2 12h3M19 12h3" />
                        </svg>
                    </button>

                    <!-- Encuesta corta (pedido explícito del usuario: "al lado
                         izquierdo") — mismo lado que la insignia de
                         "conductores cerca" de arriba, debajo para no pisarla. -->
                    <Link
                        v-if="!surveyDone"
                        :href="route('survey.show')"
                        class="absolute left-3 top-[7.25rem] z-10 flex items-center gap-1.5 rounded-full border border-white/10 bg-arka-base/75 py-2 pl-3 pr-3.5 text-xs font-semibold text-arka-primary-bright shadow-lg backdrop-blur-md hover:bg-arka-base/90 transition"
                    >
                        Encuesta (2 min) →
                    </Link>

                    <!-- Sin esto, un permiso de ubicación denegado no dejaba
                         ningún rastro visible — el botón de arriba "no hacía
                         nada" en apariencia. -->
                    <p
                        v-if="locationDenied"
                        class="absolute right-3 top-32 z-10 max-w-[10rem] px-2.5 py-1.5 rounded-arka text-[11px] leading-tight bg-black/70 text-white backdrop-blur-sm"
                    >
                        Active el permiso de ubicación del navegador para centrar el mapa.
                    </p>

                    <!-- Carrera en curso sin cerrar — solo aparece en el caso
                         raro de que exista. -->
                    <Link
                        v-if="pendingRideToClose"
                        :href="route('rides.show', pendingRideToClose.ride_id)"
                        class="absolute inset-x-2 top-32 z-10 p-3 rounded-arka bg-arka-warning/95 text-arka-base shadow-lg"
                    >
                        <p class="font-semibold text-sm">⚠️ Carrera en curso sin cerrar</p>
                        <p class="text-xs">Con {{ pendingRideToClose.counterpart_name }}. Tocá para continuar.</p>
                    </Link>
                </div>

                <!-- Bottom sheet: fondo GRIS, sin padding lateral, pegado a
                     los dos bordes y a la nav inferior, sin espacio vacío
                     entre medio (pedido explícito del usuario). -->
                <div ref="bottomSheetRef" class="fixed inset-x-3 bottom-[4.75rem] z-20 sm:inset-x-5">
                    <div class="flex h-[46dvh] max-h-[25rem] min-h-[20rem] flex-col overflow-hidden rounded-[28px] border border-white/70 bg-[#f7f8fa]/95 px-4 pb-4 pt-3 shadow-[0_20px_60px_rgba(3,15,9,0.24)] backdrop-blur-xl sm:px-5">
                        <div class="mx-auto h-1.5 w-11 rounded-full bg-arka-base/10" aria-hidden="true"></div>
                        <HomeSearchSheet
                            v-model="homeSearchQuery"
                            compact
                            :frequent-places="frequentPlaces"
                            :saved-routes="savedRoutes"
                            :origin-lat="homeLat"
                            :origin-lng="homeLng"
                            :can-schedule="hasRoute('ride-requests.create')"
                            :schedule-href="hasRoute('ride-requests.create') ? route('ride-requests.create', { programar: 1 }) : null"
                            @place-selected="({ lat, lng, address }) => goToDestination({ lat, lng, address })"
                            @select-recent="(place) => goToDestination({ lat: place.lat, lng: place.lng, address: place.address })"
                            @destination-loading="destinationSelectionLoading = $event"
                        />
                    </div>
                </div>
            </template>

            <!-- ESCRITORIO (pedido explícito del usuario: "en web tiene que
                 ser un poco diferente"): página normal, sin `fixed` ni
                 `absolute` de por medio — nav sólida de siempre, mapa
                 contenido de alto fijo, tarjeta debajo, todo en el flujo
                 normal del documento. -->
            <template v-else>
                <div class="py-10">
                    <div class="max-w-xl mx-auto px-4 space-y-4">
                        <div class="relative rounded-arka overflow-hidden shadow bg-gray-100">
                            <FleetMap
                                ref="fleetMapRef"
                                :markers="[
                                    ...(homeLat != null ? [{ id: 'origin', lat: homeLat, lng: homeLng, label: 'Mi ubicación' }] : []),
                                    ...nearbyDriverMarkers,
                                ]"
                                :center="homeMapCenter ?? undefined"
                                :clickable="true"
                                :auto-fit="false"
                                :dark="false"
                                height="340px"
                                @map-click="({ lat, lng }) => goToDestination({ lat, lng })"
                            />

                            <div
                                v-if="locationLoading"
                                class="pointer-events-none absolute inset-0 z-[9] flex items-center justify-center bg-white/50 backdrop-blur-[1px]"
                                role="status"
                                aria-live="polite"
                            >
                                <div class="flex items-center gap-3 rounded-full bg-arka-base/85 px-4 py-3 text-sm font-medium text-white shadow-xl backdrop-blur-md">
                                    <span class="h-5 w-5 animate-spin rounded-full border-2 border-white/35 border-t-arka-primary" aria-hidden="true"></span>
                                    <span>Ubicando y ajustando el mapa…</span>
                                </div>
                            </div>

                            <p
                                v-if="nearbyDriversCaption"
                                class="absolute left-3 top-3 z-10 flex items-center gap-2 pl-2.5 pr-3 py-1.5 rounded-full text-xs font-medium bg-black/55 text-white backdrop-blur-sm shadow-sm"
                            >
                                <span class="relative flex h-2 w-2 shrink-0">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-arka-primary opacity-75"></span>
                                    <span class="relative inline-flex h-2 w-2 rounded-full bg-arka-primary"></span>
                                </span>
                                {{ nearbyDriversCaption }}
                            </p>

                            <button
                                type="button"
                                class="absolute right-3 top-3 z-10 h-10 w-10 rounded-full bg-white shadow-lg flex items-center justify-center text-arka-base/70 hover:text-arka-primary"
                                aria-label="Centrar en mi ubicación"
                                @click="recenterMap"
                            >
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="3" stroke-linecap="round" stroke-linejoin="round" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 2v3M12 19v3M2 12h3M19 12h3" />
                                </svg>
                            </button>

                            <p
                                v-if="locationDenied"
                                class="absolute right-3 top-16 z-10 max-w-[10rem] px-2.5 py-1.5 rounded-arka text-[11px] leading-tight bg-black/70 text-white backdrop-blur-sm"
                            >
                                Active el permiso de ubicación del navegador para centrar el mapa.
                            </p>
                        </div>

                        <Link
                            v-if="pendingRideToClose"
                            :href="route('rides.show', pendingRideToClose.ride_id)"
                            class="block p-4 bg-arka-warning/15 border border-arka-warning/40 rounded-arka hover:bg-arka-warning/20"
                        >
                            <p class="font-semibold text-arka-warning">⚠️ Tiene una carrera en curso sin cerrar</p>
                            <p class="text-sm text-arka-text-muted">
                                Con {{ pendingRideToClose.counterpart_name }} — {{ pendingRideToClose.origin_label }} &rarr;
                                {{ pendingRideToClose.destination_label }}. Tocá para continuar.
                            </p>
                        </Link>

                        <div class="space-y-5 rounded-[28px] border border-white/70 bg-[#f7f8fa] px-6 pb-6 pt-6 shadow-[0_18px_55px_rgba(3,15,9,0.16)]">
                            <HomeSearchSheet
                                v-model="homeSearchQuery"
                                :frequent-places="frequentPlaces"
                                :saved-routes="savedRoutes"
                                :origin-lat="homeLat"
                                :origin-lng="homeLng"
                                :can-schedule="hasRoute('ride-requests.create')"
                                :schedule-href="hasRoute('ride-requests.create') ? route('ride-requests.create', { programar: 1 }) : null"
                                @place-selected="({ lat, lng, address }) => goToDestination({ lat, lng, address })"
                                @select-recent="(place) => goToDestination({ lat: place.lat, lng: place.lng, address: place.address })"
                                @destination-loading="destinationSelectionLoading = $event"
                            />
                        </div>
                    </div>
                </div>
            </template>
        </template>
    </AuthenticatedLayout>
</template>
