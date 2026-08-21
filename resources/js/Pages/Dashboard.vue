<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DriverAvailabilityToggle from '@/Components/DriverAvailabilityToggle.vue';
import AdBannerSlider from '@/Components/AdBannerSlider.vue';
import FleetMap from '@/Components/FleetMap.vue';
import HomeSearchSheet from '@/Components/HomeSearchSheet.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { playAttentionAlert, playUpdateChime } from '@/Utils/liveAlert';
import { buildWhatsAppOptInUrl } from '@/Utils/whatsapp';
import { etaMinutes } from '@/Utils/eta';
import { saveClientLocation } from '@/Utils/sessionLocation';

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
});

const whatsappSessionActive = computed(() => props.whatsappSession && props.whatsappSession.status !== 'expired');
const whatsappOptInUrl = buildWhatsAppOptInUrl(props.whatsappBusinessNumber, usePage().props.auth.user.id);

const userId = usePage().props.auth.user.id;

const hasRoute = (name) => route().has(name);

// El admin no es cliente ni conductor (sección 9.5-C): el saludo de bienvenida
// de "armá tu flota"/"convertite en conductor" no le sirve de nada — su
// puerta de entrada real es el panel admin.
const isAdmin = usePage().props.auth.user.is_admin;

// Saludo de la cabecera (pedido explícito del usuario: bajarlo de la barra
// superior a acá, a la izquierda, en el lugar donde antes decía "Inicio").
const firstName = (usePage().props.auth.user.name ?? '').trim().split(/\s+/)[0] ?? '';

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
function requestGeolocation(onSuccess) {
    if (!navigator.geolocation) {
        locationDenied.value = true;
        return;
    }

    navigator.geolocation.getCurrentPosition(
        (position) => {
            locationDenied.value = false;
            onSuccess(position);
        },
        (error) => {
            console.warn('Arka01: no se pudo obtener la ubicación en vivo del cliente.', error);
            locationDenied.value = true;
        }
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
    const originAddress = homeLat.value != null && homeLng.value != null
        ? await resolveCurrentAddress(homeLat.value, homeLng.value)
        : null;
    router.get(route('ride-requests.create'), {
        ...(homeLat.value != null && homeLng.value != null
            ? { origin_lat: homeLat.value, origin_lng: homeLng.value, origin_address: originAddress ?? '' }
            : {}),
        destination_lat: lat,
        destination_lng: lng,
        destination_address: address ?? '',
    });
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
const driverChannels = [];

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
}

// Aviso en vivo de invitaciones a flota nuevas (se reportó: un cliente
// agregó a un conductor y a este no le llegó ningún aviso porque solo
// "Mis clientes de confianza" escuchaba este evento — si el conductor
// estaba parado en el Inicio, como acá, no pasaba nada visible). Mismo
// patrón que handleNewRequest().
const pendingInvitationsCount = ref(props.driverStats?.pending_invitations ?? 0);
const newInvitationAlert = ref(null);

function handleNewInvitation(e) {
    playAttentionAlert();
    pendingInvitationsCount.value++;
    newInvitationAlert.value = { ownerName: e.owner_name };
    setTimeout(() => {
        if (newInvitationAlert.value?.ownerName === e.owner_name) {
            newInvitationAlert.value = null;
        }
    }, 12000);
}

onMounted(() => {
    if (!props.driverStats) return;

    const personal = window.Echo.private(`App.Models.User.${userId}`);
    personal.listen('.ride-request.created', (e) => handleNewRequest(e, { alert: false }));
    personal.listen('.ride-request.cancelled', handleRequestGoneWhileWaiting);
    personal.listen('.fleet-invitation.created', handleNewInvitation);
    // Recordatorio de 15-20 min antes de una carrera programada (pedido
    // explícito del usuario) — "Próximos viajes" ya muestra la hora, acá
    // solo hace falta el aviso sonoro.
    personal.listen('.ride.reminder-due', () => playAttentionAlert());
    driverChannels.push(`App.Models.User.${userId}`);

    (props.driverFleetIds ?? []).forEach((fleetId) => {
        const channel = window.Echo.private(`fleet.${fleetId}`);
        channel.listen('.ride-request.created', handleNewRequest);
        channel.listen('.ride-request.cancelled', handleRequestGoneWhileWaiting);
        driverChannels.push(`fleet.${fleetId}`);
    });
});

onBeforeUnmount(() => {
    driverChannels.forEach((name) => window.Echo.leave(name));
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
            <div class="flex items-center justify-between gap-3 flex-wrap">
                <!-- Pedido explícito del usuario: el saludo baja de la barra
                     superior a acá (donde antes decía "Inicio"), a la izquierda. -->
                <h2 class="font-semibold text-xl text-arka-text leading-tight">¡Hola, {{ firstName }}! 👋</h2>

                <div
                    v-if="driverStats"
                    class="flex items-center gap-2.5 px-3 py-1.5 rounded-arka"
                    :class="isAvailableNow ? 'bg-arka-primary/10' : 'bg-arka-text-muted/10'"
                >
                    <div
                        class="h-8 w-8 rounded-full flex items-center justify-center shrink-0"
                        :class="isAvailableNow ? 'bg-arka-primary text-arka-base' : 'bg-arka-text-muted/20 text-arka-text-muted'"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v8" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 5.5a8 8 0 1 0 10 0" />
                        </svg>
                    </div>
                    <div class="hidden sm:block leading-tight">
                        <p class="text-xs font-medium text-arka-text">{{ isAvailableNow ? 'Desconectarme' : 'Activarme' }}</p>
                        <p class="text-[11px] text-arka-text-muted">
                            {{ isAvailableNow ? 'Dejar de recibir solicitudes' : 'Empezar a recibir solicitudes' }}
                        </p>
                    </div>
                    <DriverAvailabilityToggle
                        ref="availabilityToggleRef"
                        :initial-available="driverStats.is_available"
                        :can-connect="driverStats.can_connect"
                        :blocked-reason="driverStats.connection_block_reason"
                        @update:available="handleAvailabilityChange"
                    />
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

                    <div>
                        <!-- Pedido explícito del usuario (roadmap de mejoras, sección 1): el
                             saludo se mudó al navbar (AuthenticatedLayout.vue) para
                             recuperar este espacio vertical — acá queda la insignia de
                             rol/disponibilidad, que sí es información funcional. -->
                        <p class="text-arka-text-muted flex items-center gap-1.5">
                            Conductor
                            <span class="text-arka-primary-bright" title="Cuenta de conductor">✓</span>
                            <span
                                class="ms-1 px-2 py-0.5 rounded-full text-xs font-medium"
                                :class="isAvailableNow ? 'bg-arka-primary/15 text-arka-primary-bright' : 'bg-arka-text-muted/15 text-arka-text-muted'"
                            >
                                {{ isAvailableNow ? '● Disponible' : '○ No disponible' }}
                            </span>
                        </p>
                        <Link
                            v-if="driverStats.cooperative"
                            :href="route('cooperatives.show', driverStats.cooperative.id)"
                            class="mt-2 inline-flex items-center gap-2 rounded-full border border-arka-primary/25 bg-arka-primary/10 px-3 py-1.5 text-xs font-semibold text-arka-primary-bright transition hover:border-arka-primary/60 hover:bg-arka-primary/15"
                        >
                            <span class="grid h-5 w-5 place-items-center rounded-full bg-arka-primary text-arka-base" aria-hidden="true">◉</span>
                            Conductor de {{ driverStats.cooperative.name }}
                            <span aria-hidden="true">→</span>
                        </Link>
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

                    <AdBannerSlider :banners="adBanners" />

                    <!-- Hoy es un buen día: ganancias del mes + sparkline de los
                         últimos 14 días + las 4 métricas de siempre, con tarjeta. -->
                    <div class="p-4 bg-arka-card shadow rounded-arka space-y-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-arka-text font-medium">Hoy es un buen día</p>
                                <p class="text-xs text-arka-text-muted">Más viajes, más libertad.</p>
                            </div>
                            <svg viewBox="0 0 100 32" preserveAspectRatio="none" class="w-24 h-8 shrink-0">
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

                        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                            <Link :href="route('driver.invitations.index')" class="text-center hover:opacity-80">
                                <p class="text-2xl font-semibold text-arka-text">{{ driverStats.active_clients }}</p>
                                <p class="text-xs text-arka-text-muted">Clientes en su flota</p>
                            </Link>
                            <Link :href="route('rides.index')" class="text-center hover:opacity-80">
                                <p class="text-2xl font-semibold text-arka-text">{{ driverStats.completed_rides }}</p>
                                <p class="text-xs text-arka-text-muted">Viajes este mes</p>
                            </Link>
                            <div class="text-center">
                                <p class="text-2xl font-semibold text-arka-lime">
                                    <span v-if="driverStats.review_count > 0">★ {{ driverStats.rating.toFixed(1) }}</span>
                                    <span v-else class="text-base text-arka-text-muted">Sin calificar</span>
                                </p>
                                <p class="text-xs text-arka-text-muted">Calificación</p>
                            </div>
                            <!-- Pedido explícito del usuario: "que le muestre
                                 también la cantidad de $ que ha hecho en el
                                 día" — con un fondo suave para que resalte
                                 como el dato "vivo" del día frente al del mes. -->
                            <div class="text-center rounded-arka bg-arka-primary/10 py-1.5">
                                <p class="text-2xl font-semibold text-arka-primary-bright">${{ driverStats.earnings_today.toFixed(2) }}</p>
                                <p class="text-xs text-arka-text-muted">HOY</p>
                            </div>
                            <div class="text-center">
                                <p class="text-2xl font-semibold text-arka-primary-bright">${{ driverStats.earnings_this_month.toFixed(2) }}</p>
                                <p class="text-xs text-arka-text-muted">MES</p>
                            </div>
                        </div>
                    </div>

                    <!-- Acciones rápidas -->
                    <div class="space-y-2">
                        <h4 class="text-sm font-medium text-arka-text-muted uppercase tracking-wide">Acciones rápidas</h4>
                        <div class="grid grid-cols-3 gap-2">
                            <Link :href="route('rides.index')" class="relative p-3 bg-arka-card shadow rounded-arka text-center hover:bg-arka-card/70">
                                <span
                                    v-if="pendingRequestsCount > 0"
                                    class="absolute top-1.5 right-1.5 h-4 w-4 rounded-full bg-arka-primary text-arka-base text-[10px] font-bold flex items-center justify-center"
                                >
                                    {{ pendingRequestsCount }}
                                </span>
                                <svg class="h-6 w-6 mx-auto text-arka-primary" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <path d="M3.4 3.3a.75.75 0 0 1 .8-.08l16 8a.75.75 0 0 1 0 1.34l-16 8A.75.75 0 0 1 3.13 19.7L6.98 12 3.13 4.3a.75.75 0 0 1 .27-1Z" />
                                </svg>
                                <p class="mt-1 text-xs text-arka-text">Solicitudes</p>
                            </Link>
                            <Link :href="route('driver.invitations.index')" class="relative p-3 bg-arka-card shadow rounded-arka text-center hover:bg-arka-card/70">
                                <span
                                    v-if="pendingInvitationsCount > 0"
                                    class="absolute top-1.5 right-1.5 h-4 w-4 rounded-full bg-arka-primary text-arka-base text-[10px] font-bold flex items-center justify-center"
                                >
                                    {{ pendingInvitationsCount }}
                                </span>
                                <svg class="h-6 w-6 mx-auto text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="9" cy="9" r="3" stroke-linecap="round" stroke-linejoin="round" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 19a5.5 5.5 0 0 1 11 0" />
                                    <circle cx="17" cy="9" r="2.4" stroke-linecap="round" stroke-linejoin="round" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.5 13.5c2.4 0 4.5 1.9 5 5" />
                                </svg>
                                <p class="mt-1 text-xs text-arka-text">Mis clientes</p>
                            </Link>
                            <Link :href="route('driver.profile.edit')" class="p-3 bg-arka-card shadow rounded-arka text-center hover:bg-arka-card/70">
                                <svg class="h-6 w-6 mx-auto text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="8" r="3.5" stroke-linecap="round" stroke-linejoin="round" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 20a7.5 7.5 0 0 1 15 0" />
                                </svg>
                                <p class="mt-1 text-xs text-arka-text">Mi perfil</p>
                            </Link>
                        </div>
                    </div>

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

                    <!-- Próximos viajes -->
                    <div v-if="upcomingTrips" class="space-y-2">
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-medium text-arka-text-muted uppercase tracking-wide">Próximos viajes</h4>
                            <Link :href="route('rides.index')" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                                Ver todos
                            </Link>
                        </div>
                        <p v-if="!upcomingTrips.length" class="text-sm text-arka-text-muted">
                            No tiene viajes pendientes ni en curso ahora mismo.
                        </p>
                        <Link
                            v-for="(trip, i) in upcomingTrips"
                            :key="i"
                            :href="trip.ride_id ? route('rides.show', trip.ride_id) : route('rides.index')"
                            class="flex items-center justify-between gap-3 p-3 bg-arka-card shadow rounded-arka hover:bg-arka-card/70"
                        >
                            <div class="min-w-0">
                                <p class="text-sm text-arka-text truncate">{{ trip.origin_label }} &rarr; {{ trip.destination_label }}</p>
                                <p class="text-xs text-arka-text-muted truncate">Con {{ trip.counterpart_name }}</p>
                                <p v-if="trip.status === 'scheduled'" class="text-xs text-arka-warning truncate">
                                    {{ formatScheduledAt(trip.scheduled_at) }}
                                    <span v-if="trip.round_trip"> · Ida y vuelta</span>
                                </p>
                            </div>
                            <div class="text-right shrink-0">
                                <span class="px-2 py-0.5 rounded-full text-[11px] font-medium" :class="TRIP_STATUS[trip.status].class">
                                    {{ TRIP_STATUS[trip.status].label }}
                                </span>
                                <p class="mt-1 text-sm text-arka-primary-bright font-semibold">${{ trip.price.toFixed(2) }}</p>
                            </div>
                        </Link>
                    </div>

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
                            📲 1. Mándenos ese WhatsApp ya listo (si no se abrió la pestaña, toque acá) — 2. apenas lo
                            envíe, queda conectado solo para recibir avisos.
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
                            />
                        </div>
                    </div>
                </div>
            </template>
        </template>
    </AuthenticatedLayout>
</template>
