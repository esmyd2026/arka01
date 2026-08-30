<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import FleetMap from '@/Components/FleetMap.vue';
import RatingStars from '@/Components/RatingStars.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import BottomSheet from '@/Components/BottomSheet.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { etaBetween } from '@/Utils/eta';
import { confirmDialog } from '@/Utils/confirmDialog';
import { playAttentionAlert, playCabinChime, playUpdateChime } from '@/Utils/liveAlert';
import { fetchOsrmRoute } from '@/Utils/osrmRoute';
import { distanceKm } from '@/Utils/haversine';

const props = defineProps({
    ride: { type: Object, required: true },
    isDriver: { type: Boolean, required: true },
    myReview: { type: Object, default: null },
    theirReview: { type: Object, default: null },
    // Pedido explícito del usuario: catálogo de motivos administrable desde
    // el admin, distinto según quién califica a quién (ya viene filtrado por
    // dirección y solo con los activos — ver RideController::show()).
    ratingReasons: { type: Array, default: () => [] },
    // Chat temporal cliente↔conductor (sección 10 del roadmap de mejoras).
    messages: { type: Array, default: () => [] },
    // Cuentas bancarias del conductor (pedido explícito del usuario): solo
    // llega con datos cuando la carrera es por transferencia y quien mira
    // es el cliente — ver RideController::show().
    driverBankAccounts: { type: Array, default: () => [] },
});

// Posición en vivo del conductor durante el viaje: reutiliza el mismo canal
// de flota que ya se usa para ver la disponibilidad (sección 8 del alcance:
// "reutiliza la misma infraestructura de ubicación en tiempo real").
const driverLat = ref(props.ride.driver.driver_profile?.current_lat ?? null);
const driverLng = ref(props.ride.driver.driver_profile?.current_lng ?? null);

// Rumbo del auto en el mapa del cliente (pedido explícito del usuario, con
// mockup de referencia: "busca ese carro así también" — el ícono girado
// siguiendo la ruta, no siempre mirando para arriba). Se recalcula solo con
// dos posiciones reales consecutivas (fórmula estándar de rumbo entre dos
// puntos) — con una sola posición conocida no hay de dónde sacar hacia
// dónde mira, se deja sin girar (0°) hasta que llegue una segunda.
const carHeading = ref(0);
let previousDriverPos = null;

function bearingBetween(lat1, lng1, lat2, lng2) {
    const toRad = (deg) => (deg * Math.PI) / 180;
    const toDeg = (rad) => (rad * 180) / Math.PI;
    const dLng = toRad(lng2 - lng1);
    const y = Math.sin(dLng) * Math.cos(toRad(lat2));
    const x = Math.cos(toRad(lat1)) * Math.sin(toRad(lat2)) - Math.sin(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.cos(dLng);
    return (toDeg(Math.atan2(y, x)) + 360) % 360;
}

function updateCarHeading(lat, lng) {
    // Bug evitado: dos pings casi en el mismo punto (semáforo, tráfico
    // parado) dan un rumbo prácticamente al azar por el ruido normal del
    // GPS — se ignoran los movimientos menores a ~5 metros en vez de hacer
    // temblar el ícono girando sin sentido.
    if (previousDriverPos && distanceKm(previousDriverPos.lat, previousDriverPos.lng, lat, lng) > 0.005) {
        carHeading.value = bearingBetween(previousDriverPos.lat, previousDriverPos.lng, lat, lng);
    }
    previousDriverPos = { lat, lng };
}

// Pedido explícito del usuario: el link solo abría la pantalla de Google
// Maps para elegir modo de viaje y ver la ruta, sin arrancar la navegación —
// el conductor tenía que buscar el botón para iniciar él mismo. Con
// `travelmode=driving` evita que arranque en otro modo (a pie, transporte
// público), y `dir_action=navigate` hace que la app de Google Maps (en el
// celular) arranque derecho en navegación turn-by-turn, con el origen
// puesto solo en la ubicación actual del conductor (sin parámetro
// `origin`, Google Maps la resuelve solo con el GPS del dispositivo).
function googleNavigateUrl(lat, lng) {
    const params = new URLSearchParams({
        api: '1',
        destination: `${lat},${lng}`,
        travelmode: 'driving',
        dir_action: 'navigate',
    });
    return `https://www.google.com/maps/dir/?${params.toString()}`;
}

let rideChannel = null;
let rideStatePoller = null;
let waitingClockTimer = null;
let activeRideLocationWatchId = null;
let activeRideLocationHeartbeat = null;
let lastRideLocationSentAt = 0;
const RIDE_LOCATION_INTERVAL_MS = 8000;

function sendActiveRideLocation(position, force = false) {
    if (!props.isDriver || props.ride.status !== 'in_progress') return;

    const now = Date.now();
    if (!force && now - lastRideLocationSentAt < RIDE_LOCATION_INTERVAL_MS) return;
    lastRideLocationSentAt = now;

    // `toOthers()` evita devolverle por WebSocket al mismo navegador lo que
    // acaba de enviar. Por eso el mapa del conductor se mueve localmente;
    // el cliente recibe exactamente estas coordenadas por el canal privado.
    updateCarHeading(position.coords.latitude, position.coords.longitude);
    driverLat.value = position.coords.latitude;
    driverLng.value = position.coords.longitude;

    window.axios.post(route('rides.location.update', props.ride.id), {
        lat: position.coords.latitude,
        lng: position.coords.longitude,
    }).then(({ data }) => {
        // El backend puede detectar automáticamente que se alcanzó el punto
        // de recogida. `toOthers()` no devuelve el evento a este mismo
        // navegador, así que se refresca al conductor desde la respuesta.
        if (data.arrived_at && !props.ride.arrived_at) {
            router.reload({ only: ['ride'], preserveScroll: true, preserveState: true });
        }
    }).catch((error) => {
        // 422 significa que la carrera terminó entre la lectura del GPS y el
        // envío. El siguiente cambio reactivo detiene el seguimiento; los
        // cortes de red se recuperan con el próximo pulso.
        if (error.response?.status !== 422) {
            console.warn('No se pudo actualizar la ubicación de la carrera.', error);
        }
    });
}

function stopActiveRideLocationTracking() {
    if (activeRideLocationWatchId !== null) {
        if (typeof navigator !== 'undefined') navigator.geolocation?.clearWatch(activeRideLocationWatchId);
        activeRideLocationWatchId = null;
    }
    if (activeRideLocationHeartbeat !== null) {
        window.clearInterval(activeRideLocationHeartbeat);
        activeRideLocationHeartbeat = null;
    }
}

function startActiveRideLocationTracking() {
    if (typeof navigator === 'undefined' || !navigator.geolocation || activeRideLocationWatchId !== null || !props.isDriver || props.ride.status !== 'in_progress') return;

    const options = { enableHighAccuracy: true, maximumAge: 3000, timeout: 10000 };
    activeRideLocationWatchId = navigator.geolocation.watchPosition(
        (position) => sendActiveRideLocation(position),
        () => {},
        options,
    );

    // Algunos móviles espacian watchPosition cuando el vehículo permanece
    // quieto o la pestaña pierde actividad. Este pulso mantiene actualizada
    // la sesión y fuerza una lectura periódica mientras la vista siga viva.
    activeRideLocationHeartbeat = window.setInterval(() => {
        navigator.geolocation.getCurrentPosition(
            (position) => sendActiveRideLocation(position, true),
            () => {},
            options,
        );
    }, RIDE_LOCATION_INTERVAL_MS);
}

watch(
    [() => props.isDriver, () => props.ride.status],
    ([isDriver, status]) => {
        if (isDriver && status === 'in_progress') startActiveRideLocationTracking();
        else stopActiveRideLocationTracking();
    },
    { immediate: true },
);

// Pedido explícito del usuario: "el conductor se desconecta... se fue al
// mapa [para navegar], debería seguir conectado" — el navegador pausa
// watchPosition() y hasta el setInterval() de respaldo mientras la pestaña
// está en segundo plano (ej. cambió a la app de Google Maps), así que
// location_updated_at se va quedando vieja mientras tanto. En cuanto vuelve
// a esta pestaña, se fuerza un ping inmediato en vez de esperar al próximo
// intervalo — reduce la ventana en la que parece desconectado. El barrido
// del backend (SweepStaleDriverAvailability) ya no lo desconecta de verdad
// mientras la carrera siga en curso, esto solo acorta cuánto tarda en
// verse "al día" de nuevo.
function handleVisibilityChange() {
    if (document.visibilityState !== 'visible' || !props.isDriver || props.ride.status !== 'in_progress') return;
    if (typeof navigator === 'undefined' || !navigator.geolocation) return;

    navigator.geolocation.getCurrentPosition(
        (position) => sendActiveRideLocation(position, true),
        () => {},
        { enableHighAccuracy: true, maximumAge: 3000, timeout: 10000 },
    );
}

// Chat temporal cliente↔conductor (sección 10 del roadmap de mejoras): solo
// existe mientras hay una relación de viaje vigente — mismo criterio que el
// backend (Ride::chatIsOpen()), para no ofrecer escribir algo que el
// servidor de todas formas va a rechazar.
const chatOpen = computed(() => ['scheduled', 'in_progress'].includes(props.ride.status));
const chatMessages = ref([...props.messages]);
const chatBody = ref('');
const chatSending = ref(false);
const chatError = ref('');
const chatListEl = ref(null);
const chatPanelEl = ref(null);

// Bug real reportado por el usuario: el aviso de mensaje nuevo era solo
// sonido — si la persona no tenía el panel de chat a la vista (scrolleada
// más arriba, viendo el mapa), sonaba pero no había forma de saber qué
// pasó ni de dónde venía. Este toast queda visible sin importar el scroll,
// dice quién escribió y qué, y al tocarlo lleva directo al chat.
const chatToast = ref(null);
let chatToastTimer = null;

function showChatToast(message) {
    chatToast.value = message;
    clearTimeout(chatToastTimer);
    chatToastTimer = setTimeout(() => (chatToast.value = null), 6000);
}

function dismissChatToast(scrollToChat = false) {
    chatToast.value = null;
    clearTimeout(chatToastTimer);
    if (!scrollToChat) return;

    // Ni el conductor ni el cliente durante el viaje en curso (pantalla a
    // toda pantalla) tienen el panel de chat suelto en la página — para
    // ambos casos vive dentro de su propio panel flotante, no hace falta
    // scrollear a ningún lado. Solo el cliente en 'scheduled' sigue teniendo
    // el chat inline de siempre, ahí sí es un scroll normal.
    if (props.isDriver) {
        openChatFromOptions();
        showDriverOptions.value = true;
        return;
    }
    if (clientFullscreenTrip.value) {
        openClientChat();
        return;
    }
    chatPanelEl.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// Pedido explícito del usuario: "el cliente no tiene más que solo cambian
// el contenido en la pantalla, pero no algo más visual ni tono" — antes,
// cuando el conductor arrancaba/llegaba/recogía/completaba la carrera, la
// pantalla se refrescaba en silencio (los banners de más abajo ya muestran
// el estado nuevo, pero solo si la otra parte los tiene a la vista en ese
// momento). Mismo criterio que chatToast de acá arriba: un aviso fijo,
// visible sin importar el scroll, que se apaga solo.
const statusToast = ref(null);
let statusToastTimer = null;

function showStatusToast(message) {
    statusToast.value = message;
    clearTimeout(statusToastTimer);
    statusToastTimer = setTimeout(() => (statusToast.value = null), 6000);
}

// Respuestas rápidas (pedido explícito del usuario): distintas según el rol,
// un clic manda el mensaje tal cual — no hace falta escribirlo, pero el
// campo de texto libre sigue disponible para lo que no calce en ninguna.
const QUICK_REPLIES_DRIVER = ['Voy en camino.', 'Estoy cerca.', 'Estoy en el punto de recogida.', 'No logro ubicarte.', 'Hay tráfico, llegaré en unos minutos.'];
const QUICK_REPLIES_CLIENT = ['¿Vienes en camino?', '¿Ya llegaste?', 'Estoy saliendo.', 'Estoy en el punto indicado.', 'No logro ubicarte.'];

// Pedido explícito del usuario: "un chat entre el conductor y el cliente
// para confirmar mensajes de fecha y hora de recogida" — las frases de
// arriba son todas de "ya estoy en camino", no tienen sentido para una
// carrera que puede faltar días. Mientras sigue programada (sin arrancar),
// se usan estas otras, pensadas para coordinar antes del día.
const QUICK_REPLIES_DRIVER_SCHEDULED = [
    'Confirmo que voy a estar a la hora programada.',
    '¿Seguimos con la fecha y hora acordada?',
    'Voy a llegar unos minutos tarde el día de la carrera.',
];
const QUICK_REPLIES_CLIENT_SCHEDULED = [
    '¿Confirmamos que sigue siendo a la hora programada?',
    'Necesito cambiar el horario, ¿puede?',
    'Todo listo para la hora acordada.',
];
const quickReplies = computed(() => {
    if (props.ride.status === 'scheduled') {
        return props.isDriver ? QUICK_REPLIES_DRIVER_SCHEDULED : QUICK_REPLIES_CLIENT_SCHEDULED;
    }
    return props.isDriver ? QUICK_REPLIES_DRIVER : QUICK_REPLIES_CLIENT;
});

async function scrollChatToBottom() {
    await nextTick();
    if (chatListEl.value) chatListEl.value.scrollTop = chatListEl.value.scrollHeight;
}

async function sendChatMessage(text) {
    const body = (text ?? chatBody.value).trim();
    if (!body || chatSending.value) return;

    chatSending.value = true;
    chatError.value = '';

    try {
        const { data } = await window.axios.post(route('ride-messages.store', props.ride.id), { body });
        chatMessages.value.push(data);
        chatBody.value = '';
        scrollChatToBottom();
    } catch (error) {
        chatError.value = error.response?.data?.errors?.body?.[0] ?? 'No se pudo mandar el mensaje.';
    } finally {
        chatSending.value = false;
    }
}

onMounted(() => {
    scrollChatToBottom();
    waitingClockTimer = window.setInterval(() => { waitingClock.value = Date.now(); }, 1000);
    document.addEventListener('visibilitychange', handleVisibilityChange);
    // Canal específico del viaje: funciona también cuando el conductor es
    // de una cooperativa y no pertenece a la flota privada del cliente.
    rideChannel = window.Echo.private(`ride.${props.ride.id}`);
    rideChannel.listen('.driver.location.updated', (e) => {
        if (e.driver_user_id !== props.ride.driver_user_id) return;
        updateCarHeading(e.lat, e.lng);
        driverLat.value = e.lat;
        driverLng.value = e.lng;
    });

    // Bug reportado por el usuario ("sigue el problema que no llega las
    // notificaciones de los cambios"): estos 5 listeners recargaban la
    // pantalla en silencio, sin sonido ni vibración — si la otra parte no
    // tenía la vista fija en la pantalla en ese instante, se perdía el
    // cambio. toOthers() en el backend (RideController) ya asegura que esto
    // solo le suena a quien NO hizo la acción (nunca a quien la disparó),
    // así que agregar la alerta acá es seguro para ambos lados.

    // El conductor arrancó esta carrera PROGRAMADA desde otra pestaña/sesión
    // (consideración agregada al alcance) — refresca el estado si esta
    // pantalla ya estaba abierta desde antes. Pedido explícito del usuario:
    // estos 4 son avances normales de la carrera (arrancó/llegó/recogió/
    // completó), no algo que exija una respuesta — suenan con la campanita
    // de cabina en vez del tono de atención (ese queda para lo que sí
    // necesita reacción: carrera nueva, cancelación), y muestran un aviso
    // fijo (statusToast) para que se note incluso si no se tiene la
    // pantalla mirando el banner de estado en ese instante.
    rideChannel.listen('.ride.started', (e) => {
        if (e.ride_id !== props.ride.id) return;
        playCabinChime();
        showStatusToast('🚗 La carrera arrancó.');
        router.reload({ only: ['ride'] });
    });

    // El cliente canceló la carrera (pedido explícito del usuario) — si el
    // conductor ya iba en camino y tiene esta pantalla abierta, se entera al
    // toque en vez de seguir manejando hacia algo que ya no existe. Se deja
    // con el tono de atención (más notorio + vibración): a diferencia de los
    // 4 de arriba, esto sí es una excepción que corta el viaje.
    rideChannel.listen('.ride.cancelled', (e) => {
        if (e.ride_id !== props.ride.id) return;
        playAttentionAlert();
        showStatusToast('⚠️ La otra parte canceló la carrera.');
        router.reload({ only: ['ride'] });
    });

    // Bug reportado por el usuario: el conductor finalizaba la carrera y el
    // cliente se quedaba viendo la pantalla como si siguiera en curso — no
    // faltaba el evento (RideCompleted ya se transmite), faltaba este
    // listener, que sí existe para "iniciada" y "cancelada".
    rideChannel.listen('.ride.completed', (e) => {
        if (e.ride_id !== props.ride.id) return;
        playCabinChime();
        showStatusToast('✅ La carrera se completó.');
        router.reload({ only: ['ride'] });
    });

    // El conductor marcó "ya llegué" o "ya recogí al cliente" (pedido
    // explícito del usuario) — refresca para que el que tiene la pantalla
    // abierta vea el cambio de banner sin recargar a mano.
    rideChannel.listen('.ride.arrived', (e) => {
        if (e.ride_id !== props.ride.id) return;
        playCabinChime();
        showStatusToast('📍 El conductor llegó al punto de encuentro.');
        router.reload({ only: ['ride'] });
    });

    rideChannel.listen('.ride.picked_up', (e) => {
        if (e.ride_id !== props.ride.id) return;
        playCabinChime();
        showStatusToast('🚗 El conductor recogió al cliente, van en camino.');
        router.reload({ only: ['ride'] });
    });

    // El cliente propuso otro horario, o el conductor ya respondió a una
    // propuesta (pedido explícito del usuario: editar una carrera
    // programada) — refresca para que la otra parte vea el aviso sin
    // recargar a mano.
    rideChannel.listen('.ride.reschedule-proposed', (e) => {
        if (e.ride_id !== props.ride.id) return;
        playAttentionAlert();
        showStatusToast('📅 Se propuso un nuevo horario para la carrera.');
        router.reload({ only: ['ride'] });
    });
    rideChannel.listen('.ride.reschedule-responded', (e) => {
        if (e.ride_id !== props.ride.id) return;
        playAttentionAlert();
        showStatusToast(e.confirmed ? '✅ El conductor confirmó el nuevo horario.' : '⚠️ El conductor rechazó el nuevo horario.');
        router.reload({ only: ['ride'] });
    });

    // Chat (sección 10 del roadmap de mejoras): canal PROPIO de esta carrera
    // puntual, no el de flota — ahí solo escuchan las dos partes de este
    // viaje, nadie más de la flota (ver routes/channels.php: `ride.{id}`).
    // Solo tiene sentido suscribirse mientras el chat sigue abierto — ver
    // Ride::chatIsOpen().
    if (chatOpen.value) {
        rideChannel.listen('.ride.message.sent', (e) => {
            showChatToast(e);
            chatMessages.value.push(e);
            playUpdateChime();
            scrollChatToBottom();
        });
    }

    // Respaldo cuando WebSocket se corta, el teléfono cambia de red o la
    // pestaña estuvo suspendida. El evento sigue siendo inmediato; este
    // sondeo evita que el cliente quede indefinidamente con estado viejo.
    if (['scheduled', 'in_progress'].includes(props.ride.status)) {
        rideStatePoller = window.setInterval(() => {
            if (!['scheduled', 'in_progress'].includes(props.ride.status)) {
                window.clearInterval(rideStatePoller);
                rideStatePoller = null;
                return;
            }
            router.reload({ only: ['ride'], preserveScroll: true, preserveState: true });
        }, 5000);
    }
});

onBeforeUnmount(() => {
    window.Echo.leave(`ride.${props.ride.id}`);
    if (rideStatePoller) window.clearInterval(rideStatePoller);
    if (waitingClockTimer) window.clearInterval(waitingClockTimer);
    document.removeEventListener('visibilitychange', handleVisibilityChange);
    stopActiveRideLocationTracking();
});

const mapMarkers = computed(() => {
    const markers = [
        { id: 'origin', lat: Number(props.ride.origin_lat), lng: Number(props.ride.origin_lng), label: 'Origen' },
        {
            id: 'destination',
            lat: Number(props.ride.destination_lat),
            lng: Number(props.ride.destination_lng),
            label: 'Destino',
        },
    ];

    // Paradas adicionales (pedido explícito del usuario) — mismo pin ámbar
    // "base" que ya usa Ride/Request.vue, con el estado en la etiqueta.
    (props.ride.stops ?? []).forEach((stop) => {
        markers.push({
            id: 'base',
            lat: Number(stop.lat),
            lng: Number(stop.lng),
            label: `Parada ${stop.sequence}${stop.status !== 'pending' ? ` (${stop.status === 'completed' ? 'completada' : 'cancelada'})` : ''}`,
        });
    });

    // Ícono de auto (pedido explícito del usuario: "que le muestre también
    // ahí en el mapa... tal y como se muestra en la imagen que te adjunto",
    // con captura de referencia estilo Uber/DiDi: auto + línea verde trazada
    // hasta el punto de encuentro) — antes era el puntito celeste genérico de
    // seguimiento en vivo (`id: 'driver'`), acá calza mejor el mismo auto
    // verde que ya se usa para conductores en el resto de la app.
    if (driverLat.value != null) {
        markers.push({
            id: 'car',
            lat: Number(driverLat.value),
            lng: Number(driverLng.value),
            color: '#34d399',
            label: 'Conductor',
            // Rumbo real entre las dos últimas posiciones conocidas (ver
            // updateCarHeading()) — el auto gira siguiendo la ruta, no
            // siempre mirando para arriba.
            rotation: carHeading.value,
        });
    }

    return markers;
});

// En seguimiento móvil no conviene encuadrar toda la carrera mientras el
// conductor apenas va a recoger al cliente: origen + destino + conductor
// pueden abarcar varios kilómetros y alejan demasiado el mapa. Se muestra
// únicamente el tramo que importa en cada momento.
const trackingMapMarkers = computed(() => {
    const car = mapMarkers.value.find((marker) => marker.id === 'car');
    const targetId = props.ride.picked_up_at ? 'destination' : 'origin';
    const target = mapMarkers.value.find((marker) => marker.id === targetId);

    return [target, car].filter(Boolean);
});

// Quién es "la otra parte" de esta carrera, para mostrar su avatar junto a
// la reseña que le hizo a la cuenta logueada.
const counterpart = computed(() => (props.isDriver ? props.ride.client : props.ride.driver));

// Pantalla del cliente durante el viaje (pedido explícito del usuario, con
// mockup de referencia estilo Uber/DiDi): mapa a toda la pantalla, sin
// scroll, sin ninguna tarjeta de más — "el cliente [no debería] ver más
// datos o opciones". Acotado a 'in_progress' (antes de eso no hay nada que
// trazar todavía; 'completed'/'cancelled' vuelven a la pantalla normal, ahí
// sí importa ver el recibo completo y calificar con calma, sin apuro).
const clientFullscreenTrip = computed(() => !props.isDriver && props.ride.status === 'in_progress');

const tripProgressSteps = computed(() => [
    { label: 'Solicitud', complete: true },
    { label: 'Asignado', complete: true },
    { label: 'Recogida', complete: Boolean(props.ride.arrived_at) },
    { label: 'En viaje', complete: Boolean(props.ride.picked_up_at) },
    { label: 'Llegada', complete: props.ride.status === 'completed' },
]);

const currentTripProgressIndex = computed(() => {
    const firstPending = tripProgressSteps.value.findIndex((step) => !step.complete);
    return firstPending === -1 ? tripProgressSteps.value.length - 1 : firstPending;
});

// Cuánto falta para que el conductor llegue a buscarte (pedido explícito del
// usuario: "cuando el conductor sale a buscar al cliente, avisarle que ya
// van por él y en cuánto tiempo"). Solo le sirve al CLIENTE — el conductor ya
// tiene su propia navegación real por el link de Google Maps de más abajo,
// que es más precisa que esta estimación. Se recalcula solo cuando llega una
// ubicación nueva por WebSocket (driverLat/driverLng son reactivos).
const pickupEta = computed(() => {
    if (props.isDriver || props.ride.status !== 'in_progress' || props.ride.arrived_at) return null;
    return etaBetween(driverLat.value, driverLng.value, Number(props.ride.origin_lat), Number(props.ride.origin_lng));
});

// Pedido explícito del usuario: "permitir que el mapa se expanda más" — el
// mapa del conductor arranca compacto (320px) y este toggle lo agranda a
// 65vh, sin tocar FleetMap.vue (sigue siendo un simple string de altura).
const mapExpanded = ref(false);

// Pedido explícito del usuario: distancia y tiempo restantes en el mapa del
// CONDUCTOR (liveRemainingKm/liveRemainingMin vienen de refreshLiveRoute(),
// siguiendo la ruta real, no la línea recta) — se actualiza solo cada vez
// que se recalcula la ruta en vivo, mismo ritmo que la polilínea.
const driverRemainingLabel = computed(() => {
    if (!props.isDriver || liveRemainingKm.value == null) return null;
    const kmLabel = liveRemainingKm.value < 1
        ? `${Math.round(liveRemainingKm.value * 1000)} m`
        : `${liveRemainingKm.value.toFixed(1)} km`;
    const minLabel = liveRemainingMin.value != null ? `${Math.max(1, Math.round(liveRemainingMin.value))} min` : null;
    return minLabel ? `${kmLabel} · ${minLabel}` : kmLabel;
});

// Paradas adicionales (pedido explícito del usuario: "cada parada se
// calcula diferente e individual... si no llegan a una parada puedan
// pagarle cada parada y cancelar la otra o iniciar la siguiente parada").
// La próxima parada pendiente (ya vienen ordenadas por sequence desde el
// backend, ver Ride::stops()) — null cuando no hay paradas o ya se
// completaron/cancelaron todas, momento en que vuelve a aparecer el botón
// normal de "Completar carrera" de siempre. Definida ACÁ (antes de
// refreshLiveRoute()/currentNavigationTarget, más abajo) porque el watch
// inmediato de esas funciones la necesita desde el primer render.
const currentStop = computed(() => (props.ride.stops ?? []).find((stop) => stop.status === 'pending') ?? null);

// Punto al que el conductor tiene que dirigirse AHORA MISMO (pedido
// explícito del usuario: botón de navegación + ruta en vivo que tengan en
// cuenta las paradas, no solo origen/destino) — antes de recoger, el
// origen; después, la próxima parada pendiente si hay alguna, si no el
// destino final. Reusado tanto por refreshLiveRoute() (la ruta que se
// dibuja) como por el botón "Abrir en Google Maps" del mapa.
const currentNavigationTarget = computed(() => {
    if (!props.ride.picked_up_at) {
        return { lat: Number(props.ride.origin_lat), lng: Number(props.ride.origin_lng) };
    }
    if (currentStop.value) {
        return { lat: Number(currentStop.value.lat), lng: Number(currentStop.value.lng) };
    }
    return { lat: Number(props.ride.destination_lat), lng: Number(props.ride.destination_lng) };
});

// Cinco minutos de cortesía desde la llegada automática/manual. El tiempo
// base viene del servidor (`arrived_at`), por lo que cliente y conductor ven
// el mismo conteo aunque abran la pantalla en momentos distintos.
const waitingClock = ref(Date.now());
const pickupWaitCountdown = computed(() => {
    if (!props.ride.arrived_at || props.ride.picked_up_at || props.ride.status !== 'in_progress') return null;
    const elapsedSeconds = Math.max(0, Math.floor((waitingClock.value - new Date(props.ride.arrived_at).getTime()) / 1000));
    const remainingSeconds = Math.max(0, 300 - elapsedSeconds);

    return {
        remainingSeconds,
        label: remainingSeconds > 0
            ? `${String(Math.floor(remainingSeconds / 60)).padStart(2, '0')}:${String(remainingSeconds % 60).padStart(2, '0')}`
            : 'Tiempo cumplido',
    };
});

// Ruta viva: siempre parte de la posición ACTUAL del vehículo y termina en
// el siguiente objetivo (origen antes de recoger, destino después). Así la
// línea se va consumiendo y, si el conductor se desvía, Google/OSRM devuelve
// una nueva trayectoria en vez de conservar el trazado inicial congelado.
const liveRouteCoords = ref([]);
// Pedido explícito del usuario: "colocar en el mapa del conductor la
// cantidad de km que le falta y tiempo estimado... cada vez que actualice el
// recorrido" — fetchOsrmRoute() YA calculaba esto en cada refresco de la
// ruta en vivo, pero antes se tiraba (solo se guardaban las coordenadas).
const liveRemainingKm = ref(null);
const liveRemainingMin = ref(null);
let liveRouteRequestSerial = 0;
let lastLiveRouteRequestedAt = 0;
let lastLiveRouteOrigin = null;
const LIVE_ROUTE_MIN_INTERVAL_MS = 12000;
const LIVE_ROUTE_MIN_MOVEMENT_KM = 0.025;

// Pedido explícito del usuario: "que el mapa haga zoom y se centre en cada
// recalculo del recorrido" + "permitir que el conductor manipule el mapa" +
// "un botón que centre la ubicación como Google Maps". `followDriver` arranca
// en true (auto-centrado normal); se apaga solo si el conductor arrastra el
// mapa a propósito (ver @user-panned en el template, viene de
// LeafletFleetMap's `dragstart`) y el botón de recentrar lo vuelve a prender.
const followDriver = ref(true);
const fleetMapRef = ref(null);

async function refreshLiveRoute(force = false) {
    if (props.ride.status !== 'in_progress' || driverLat.value == null || driverLng.value == null) {
        liveRouteCoords.value = [];
        liveRemainingKm.value = null;
        liveRemainingMin.value = null;
        return;
    }

    const origin = { lat: Number(driverLat.value), lng: Number(driverLng.value) };
    const target = currentNavigationTarget.value;
    const movedKm = lastLiveRouteOrigin
        ? distanceKm(lastLiveRouteOrigin.lat, lastLiveRouteOrigin.lng, origin.lat, origin.lng)
        : Infinity;

    if (!force && (Date.now() - lastLiveRouteRequestedAt < LIVE_ROUTE_MIN_INTERVAL_MS || movedKm < LIVE_ROUTE_MIN_MOVEMENT_KM)) return;

    // Al estar prácticamente encima del objetivo, una polilínea de unos
    // metros agrega ruido. Se limpia hasta que cambie el siguiente tramo.
    if (distanceKm(origin.lat, origin.lng, target.lat, target.lng) < 0.03) {
        liveRouteCoords.value = [];
        liveRemainingKm.value = 0;
        liveRemainingMin.value = 0;
        return;
    }

    lastLiveRouteRequestedAt = Date.now();
    lastLiveRouteOrigin = origin;
    const serial = ++liveRouteRequestSerial;
    const routeResult = await fetchOsrmRoute(origin.lat, origin.lng, target.lat, target.lng);

    // Una respuesta lenta de una ubicación anterior nunca debe sobrescribir
    // la ruta más reciente.
    if (serial === liveRouteRequestSerial) {
        liveRouteCoords.value = routeResult.coords;
        liveRemainingKm.value = routeResult.distanceKm ?? null;
        liveRemainingMin.value = routeResult.durationMin ?? null;
        recenterOnDriver();
    }
}

// Encuadra al conductor JUNTO con el punto al que se dirige — automático en
// cada recorrido nuevo mientras followDriver esté prendido, o a pedido desde
// el botón de recentrar (que además reactiva el seguimiento). Pedido
// explícito del usuario ("es muy mínimo y no se logra detallar la ruta en
// la que voy"): centrar solo en el punto del conductor con un zoom fijo no
// mostraba hacia dónde iba — fitTo() ajusta el zoom para que los dos puntos
// entren siempre, ni muy lejos ni muy cerca.
function recenterOnDriver(force = false) {
    if (!props.isDriver) return;
    if (!force && !followDriver.value) return;
    if (driverLat.value == null || driverLng.value == null) return;
    const origin = { lat: Number(driverLat.value), lng: Number(driverLng.value) };
    fleetMapRef.value?.fitTo([origin, currentNavigationTarget.value]);
}

// Bug real reportado por el usuario ("el zoom no funciona para centrar en
// el mapa"): recenterOnDriver() se queda callado del todo si driverLat/Lng
// todavía no tienen ningún valor (recién abrió la carrera y ni el perfil
// del conductor ni ningún WebSocket mandaron una ubicación todavía) — el
// botón no hacía nada, sin ningún aviso de por qué. Ahora, si faltan,
// pide una posición fresca del GPS (mismo mecanismo que ya usa
// confirmArrived()) antes de encuadrar, en vez de rendirse en silencio.
async function recenterMapButton() {
    followDriver.value = true;
    if (driverLat.value == null || driverLng.value == null) {
        const coords = await currentCoords();
        if (coords.lat != null && coords.lng != null) {
            driverLat.value = coords.lat;
            driverLng.value = coords.lng;
        }
    }
    recenterOnDriver(true);
}

watch([driverLat, driverLng], () => refreshLiveRoute(false), { immediate: true });
watch(
    [() => props.ride.status, () => props.ride.arrived_at, () => props.ride.picked_up_at],
    () => refreshLiveRoute(true),
);

// Ruta principal de la carrera. Se mantiene separada de la aproximación al
// cliente para que, una vez marcado "Llegué", el mapa cambie naturalmente
// de conductor→origen a origen→destino sin reutilizar una línea incorrecta.
const tripRouteCoords = ref([]);
const loadTripRoute = async () => {
    const route = await fetchOsrmRoute(
        Number(props.ride.origin_lat),
        Number(props.ride.origin_lng),
        Number(props.ride.destination_lat),
        Number(props.ride.destination_lng)
    );
    tripRouteCoords.value = route.coords;
};

onMounted(loadTripRoute);

const visibleRouteCoords = computed(() => (
    props.ride.status === 'in_progress'
        ? liveRouteCoords.value
        : tripRouteCoords.value
));

// Pedido explícito del usuario: "validá que las acciones del conductor...
// estén acorde a la ubicación de origen [o] destino" — se manda la posición
// FRESCA del navegador en el momento mismo del clic (no la que ya viaja en
// vivo por WebSocket, que puede tener hasta ~15 seg. de desfase). Si el
// navegador la niega, no la soporta o tarda más de 8 seg., se manda igual
// sin coordenadas — el backend no bloquea la acción cuando faltan (ver
// RideController::assertNearRideLocation()), nunca hay que dejar a un
// conductor sin poder completar una carrera real por un permiso que puede
// rechazar.
function currentCoords() {
    return new Promise((resolve) => {
        if (!navigator.geolocation) {
            resolve({});
            return;
        }
        navigator.geolocation.getCurrentPosition(
            (position) => resolve({ lat: position.coords.latitude, lng: position.coords.longitude }),
            () => resolve({}),
            { timeout: 8000, maximumAge: 30000 }
        );
    });
}

const completing = ref(false);
const completionFeedback = ref('');

function readableDistance(km) {
    return km < 1 ? `${Math.round(km * 1000)} m` : `${km.toFixed(1)} km`;
}

// Pedido explícito del usuario: dentro de 20 m del destino completa directo,
// como siempre. Más lejos de eso, en vez de bloquear sin más (como antes con
// 1.5 km), se le pide elegir un motivo de una lista — esa info le llega
// al cliente. Mismas opciones que RideController::EARLY_COMPLETION_REASONS.
const COMPLETION_ARRIVAL_RADIUS_KM = 0.02;
const EARLY_COMPLETION_REASONS = [
    'El cliente pidió terminar el viaje antes de llegar',
    'El cliente no colocó la ubicación de destino correcta',
    'No se puede llegar hasta el punto exacto (acceso cerrado, obra, tráfico, etc.)',
    'Problema con el GPS del celular',
    'Otro motivo',
];

const showCompletionReasonForm = ref(false);
const completionReason = ref('');
const completionNote = ref('');
const completionReasonError = ref('');
let pendingCompletionCoords = {};

async function complete() {
    completing.value = true;
    completionFeedback.value = '';
    const coords = await currentCoords();

    // Feedback inmediato antes de enviar. El backend repite la validación
    // porque la posición del navegador no debe considerarse una autoridad.
    if (coords.lat != null && coords.lng != null) {
        const remainingKm = distanceKm(
            coords.lat,
            coords.lng,
            Number(props.ride.destination_lat),
            Number(props.ride.destination_lng)
        );

        if (remainingKm > COMPLETION_ARRIVAL_RADIUS_KM) {
            pendingCompletionCoords = coords;
            completionReason.value = '';
            completionNote.value = '';
            completionReasonError.value = '';
            showCompletionReasonForm.value = true;
            completing.value = false;
            return;
        }
    }

    submitCompletion(coords);
}

function submitCompletion(coords, extra = {}) {
    router.post(route('rides.complete', props.ride.id), { ...coords, ...extra }, {
        preserveScroll: true,
        onSuccess: () => {
            showCompletionReasonForm.value = false;
        },
        onError: (errors) => {
            const message = errors.completion_reason || errors.ride || 'No fue posible completar la carrera. Revise su ubicación e inténtelo nuevamente.';
            if (showCompletionReasonForm.value) {
                completionReasonError.value = message;
            } else {
                completionFeedback.value = message;
            }
        },
        onFinish: () => (completing.value = false),
    });
}

function confirmEarlyCompletion() {
    if (!completionReason.value) return;
    completing.value = true;
    submitCompletion(pendingCompletionCoords, {
        completion_reason: completionReason.value,
        completion_note: completionNote.value.trim() || null,
    });
}

const statusLabel = {
    scheduled: 'Programada',
    in_progress: 'En curso',
    completed: 'Completada',
    cancelled: 'Cancelada',
};

function startRide() {
    router.post(route('rides.start', props.ride.id), {}, { preserveScroll: true });
}

// "Ir por el pasajero" / "Ya llegué" (pedido explícito del usuario: "coloca
// los botones flotantes de ir por el pasajero y luego... que aparezca
// iniciar destino"). Bug real reportado por el usuario, con captura ("el
// tiempo de espera se activó sin que el conductor haya llegado al sitio de
// recogida"): la primera versión de esto marcaba `arrived_at` en el MISMO
// toque que abría Google Maps — pero `arrived_at` es justo lo que dispara
// el conteo de cortesía de 5 minutos que ve el cliente
// (pickupWaitCountdown, más abajo), así que ese conteo arrancaba apenas el
// conductor SALÍA para allá, no cuando de verdad llegaba — para un trayecto
// de más de 5 minutos, el cliente veía "Tiempo cumplido" con el conductor
// todavía manejando. Ahora son dos toques reales: "Ir por el pasajero" solo
// abre la navegación, sin tocar `arrived_at` — recién "Ya llegué" (un
// segundo toque, una vez ahí de verdad) lo marca.
//
// Segundo bug real, también con captura ("ya le había dado a ese botón, y
// cuando entro a la aplicación decía nuevamente ir por el pasajero"): el
// toque en sí solo vivía en este ref local — nada quedaba guardado en el
// servidor, así que recargar la página o volver a entrar perdía el estado
// y el botón volvía a mostrar "Ir por el pasajero" aunque el conductor ya
// hubiera salido. `ride.heading_to_passenger_at` (columna nueva, separada
// de `arrived_at` a propósito) es ahora la fuente de verdad; el ref local
// solo da el feedback instantáneo mientras el POST todavía está en vuelo.
const headingToPassenger = ref(false);
function goToPassenger() {
    window.open(googleNavigateUrl(props.ride.origin_lat, props.ride.origin_lng), '_blank', 'noopener');
    headingToPassenger.value = true;
    if (!props.ride.heading_to_passenger_at) {
        router.post(route('rides.heading-to-passenger', props.ride.id), {}, { preserveScroll: true });
    }
}

const markingArrived = ref(false);
async function confirmArrived() {
    markingArrived.value = true;
    const coords = await currentCoords();
    router.post(route('rides.arrived', props.ride.id), coords, {
        preserveScroll: true,
        onFinish: () => (markingArrived.value = false),
    });
}

const markingPickedUp = ref(false);
async function startToDestination() {
    window.open(googleNavigateUrl(props.ride.destination_lat, props.ride.destination_lng), '_blank', 'noopener');

    markingPickedUp.value = true;
    const coords = await currentCoords();
    router.post(route('rides.picked-up', props.ride.id), coords, {
        preserveScroll: true,
        onFinish: () => (markingPickedUp.value = false),
    });
}

// Mismo criterio de dos toques que goToPassenger()/confirmArrived(): el
// primero solo navega, el segundo (una vez ahí de verdad) abre la elección
// de seguir o cobrar y cancelar el resto.
const headingToStop = ref(false);
function goToStop(stop) {
    window.open(googleNavigateUrl(stop.lat, stop.lng), '_blank', 'noopener');
    headingToStop.value = true;
}

const showStopChoice = ref(false);
const completingStop = ref(false);
const stopFeedback = ref('');

function openStopChoice() {
    stopFeedback.value = '';
    showStopChoice.value = true;
}

async function confirmStop(cancelRest) {
    if (!currentStop.value) return;
    completingStop.value = true;
    const coords = await currentCoords();
    router.post(route('rides.stops.complete', [props.ride.id, currentStop.value.id]), { ...coords, cancel_rest: cancelRest }, {
        preserveScroll: true,
        onSuccess: () => {
            showStopChoice.value = false;
            headingToStop.value = false;
        },
        onError: (errors) => {
            stopFeedback.value = errors.ride || 'No fue posible completar la parada. Revise su ubicación e inténtelo nuevamente.';
        },
        onFinish: () => (completingStop.value = false),
    });
}

// Cancelar una carrera ya aceptada (pedido explícito del usuario): al
// principio solo podía el cliente; ahora también el conductor, pidiendo un
// motivo (lista fija según el rol, mismas opciones que
// RideController::CLIENT_CANCEL_REASONS/DRIVER_CANCEL_REASONS) y una
// observación libre, opcional.
const CLIENT_CANCEL_REASONS = [
    'Cambié de planes',
    'Encontré otro medio de transporte',
    'Pedí la carrera por error',
    'El conductor demoró demasiado',
    'Otro motivo',
];
const DRIVER_CANCEL_REASONS = [
    'Imprevisto personal',
    'Problema con el vehículo',
    'No voy a poder llegar a tiempo',
    'El cliente no responde o no aparece',
    'Motivo de seguridad',
    'Otro motivo',
];
const cancelReasons = computed(() => (props.isDriver ? DRIVER_CANCEL_REASONS : CLIENT_CANCEL_REASONS));

const showCancelForm = ref(false);
const cancelReason = ref('');
const cancelNote = ref('');
const cancellingRide = ref(false);
const cancelError = ref('');

// Acceso rápido a "Cancelar viaje" desde la tarjeta flotante sobre el mapa
// (mockup de referencia): abre el mismo formulario de motivo/nota que ya
// vive en la tarjeta de acciones, más abajo — no duplica esa lógica, solo
// hace scroll hasta ahí (mismo patrón que ya usa dismissChatToast() para el chat).
const actionsCardEl = ref(null);
function quickCancelRide() {
    cancelError.value = '';
    showCancelForm.value = true;
}

// Botón "⋯" flotante del conductor (pedido explícito del usuario: "unificalo
// un poco mejor y lo que está debajo de esto como el chat, SOS colocalo en un
// botón flotante de opciones... es para la comodidad del conductor"): junta
// navegación, mensaje/llamar, seguimiento y SOS en un solo lugar, sin repetir
// la tarjeta flotante con tantas filas de botones ni obligar a scrollear la
// pantalla buscándolos durante el viaje. Con dos "vistas" adentro del mismo
// panel (el menú, o el chat) en vez de abrir un segundo panel encima.
const showDriverOptions = ref(false);
const driverOptionsView = ref('menu'); // 'menu' | 'chat'

function openDriverOptions() {
    driverOptionsView.value = 'menu';
    showDriverOptions.value = true;
}

function openChatFromOptions() {
    driverOptionsView.value = 'chat';
    scrollChatToBottom();
}

function cancelFromOptions() {
    cancelError.value = '';
    showDriverOptions.value = false;
    showCancelForm.value = true;
}

function openDriverCancel() {
    cancelError.value = '';
    showDriverOptions.value = false;
    showCancelForm.value = true;
}

// Panel del cliente en la pantalla a toda pantalla (pedido explícito del
// usuario: "el cliente [no debería] ver más datos o opciones" — nada de
// tarjetas sueltas, solo lo del mockup + un acceso chico a mensaje/seguridad
// que no compite visualmente). Mismo patrón de "dos vistas en un panel" que
// el del conductor, con sus propias vistas ('chat' | 'safety').
const showClientOptions = ref(false);
const clientOptionsView = ref('chat');

// Cuentas bancarias del conductor (pedido explícito del usuario): el
// cliente decide cuándo verlas, no se le impone un modal automático apenas
// entra a la pantalla.
const showBankAccounts = ref(false);

function openClientChat() {
    clientOptionsView.value = 'chat';
    showClientOptions.value = true;
    scrollChatToBottom();
}

// Durante el viaje el chat vive en un panel inferior; en una carrera
// programada todavía vive dentro de la página.
function openClientMessage() {
    if (clientFullscreenTrip.value) {
        openClientChat();
        return;
    }

    nextTick(() => chatPanelEl.value?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
}

function openClientSafety() {
    clientOptionsView.value = 'safety';
    showClientOptions.value = true;
}

function submitCancelRide() {
    if (!cancelReason.value) return;

    cancellingRide.value = true;
    cancelError.value = '';
    router.post(
        route('rides.cancel', props.ride.id),
        { reason: cancelReason.value, note: cancelNote.value.trim() || null },
        {
            preserveScroll: true,
            onSuccess: () => {
                showCancelForm.value = false;
                showDriverOptions.value = false;
            },
            onError: (errors) => {
                cancelError.value = errors.ride || errors.reason || 'No se pudo cancelar la carrera. Actualizaremos su estado.';
                // Puede ocurrir si la otra parte la canceló mientras este
                // formulario estaba abierto. Refrescar muestra el estado
                // real en vez de dejar un botón que ya no puede funcionar.
                router.reload({ only: ['ride'], preserveScroll: true, preserveState: true });
            },
            onFinish: () => (cancellingRide.value = false),
        }
    );
}

// Editar una carrera programada (pedido explícito del usuario: "si es que
// se equivocaron") — mismos tres <select> explícitos que Ride/Request.vue
// (nada de reloj nativo, ver el bug real corregido ahí). No se aplica solo:
// el conductor tiene que confirmarlo o rechazarlo (ver
// RideController::propose/confirm/rejectReschedule()).
const showRescheduleForm = ref(false);
const todayDateString = new Date().toISOString().slice(0, 10);
const rescheduleDate = ref('');
const rescheduleHour = ref('');
const rescheduleMinute = ref('');
const reschedulePeriod = ref('AM');
const RESCHEDULE_HOUR_OPTIONS = Array.from({ length: 12 }, (_, i) => String(i + 1));
const RESCHEDULE_MINUTE_OPTIONS = Array.from({ length: 12 }, (_, i) => String(i * 5).padStart(2, '0'));
const rescheduleError = ref('');
const reschedulingRide = ref(false);

function submitReschedule() {
    if (!rescheduleDate.value || !rescheduleHour.value || rescheduleMinute.value === '') return;

    let hour24 = Number(rescheduleHour.value) % 12;
    if (reschedulePeriod.value === 'PM') hour24 += 12;

    reschedulingRide.value = true;
    rescheduleError.value = '';

    router.post(
        route('rides.reschedule.propose', props.ride.id),
        {
            scheduled_date: rescheduleDate.value,
            scheduled_time: `${String(hour24).padStart(2, '0')}:${rescheduleMinute.value}`,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                showRescheduleForm.value = false;
                rescheduleDate.value = '';
                rescheduleHour.value = '';
                rescheduleMinute.value = '';
            },
            onError: (errors) => {
                rescheduleError.value = errors.scheduled_time ?? errors.scheduled_date ?? 'No se pudo mandar el nuevo horario.';
            },
            onFinish: () => (reschedulingRide.value = false),
        }
    );
}

function confirmReschedule() {
    router.post(route('rides.reschedule.confirm', props.ride.id), {}, { preserveScroll: true });
}

async function rejectReschedule() {
    if (!(await confirmDialog('¿Rechazar el nuevo horario? La carrera sigue en su horario original.'))) return;

    router.post(route('rides.reschedule.reject', props.ride.id), {}, { preserveScroll: true });
}

// Bitácora automática (sección 8): cuánto duró el viaje, cuando ya terminó.
const durationLabel = computed(() => {
    if (!props.ride.started_at || !props.ride.completed_at) return null;
    const minutes = Math.round((new Date(props.ride.completed_at) - new Date(props.ride.started_at)) / 60000);
    if (minutes < 60) return `${minutes} min`;
    return `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
});

// Cuánto esperó el conductor en el punto de encuentro antes de recoger al
// cliente (pedido explícito del usuario: guardar arrived_at/picked_up_at
// "para calcular esa info" — este es el primer uso directo de esos datos).
const waitLabel = computed(() => {
    if (!props.ride.arrived_at || !props.ride.picked_up_at) return null;
    const minutes = Math.round((new Date(props.ride.picked_up_at) - new Date(props.ride.arrived_at)) / 60000);
    return minutes <= 0 ? 'menos de 1 min' : `${minutes} min`;
});

// Seguimiento en vivo compartible (sección 8): enlace de solo lectura, sin
// cuenta ni instalación, para un contacto de confianza.
const trackingUrl = ref(null);
const trackingCopied = ref(false);

async function shareTracking() {
    const { data } = await window.axios.get(route('rides.tracking-link', props.ride.id));
    trackingUrl.value = data.url;
    trackingCopied.value = false;
}

async function copyTrackingUrl() {
    await navigator.clipboard.writeText(trackingUrl.value);
    trackingCopied.value = true;
}

// Botón SOS (sección 8): visible durante el viaje, avisa por correo a los
// contactos de confianza de quien lo activa, con ubicación y datos del
// conductor/vehículo.
const sosSent = ref(false);

async function triggerSos() {
    if (!(await confirmDialog('¿Enviar una alerta de emergencia a sus contactos de confianza?', { danger: true, confirmLabel: 'Enviar alerta' }))) return;

    router.post(
        route('sos.store', props.ride.id),
        {},
        { preserveScroll: true, onSuccess: () => (sosSent.value = true) }
    );
}

// Calificar al finalizar la carrera (sección 3.6): cada parte lo hace una
// sola vez, y el comentario queda visible de inmediato en el perfil público.
// Pedido explícito del usuario: arranca en 5 estrellas (no en 0/vacío); si se
// baja, hay que elegir un motivo del catálogo administrado.
const reviewForm = useForm({
    rating: 5,
    rating_reason_id: null,
    comment: '',
});

const needsReason = computed(() => reviewForm.rating < 5);

// El motivo elegido deja de ser válido si el cliente sube la calificación de
// nuevo a 5 después de haber bajado — se limpia para no mandar un motivo que
// ya no corresponde a nada.
watch(() => reviewForm.rating, (rating) => {
    if (rating === 5) reviewForm.rating_reason_id = null;
});

function submitReview() {
    reviewForm.post(route('reviews.store', props.ride.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Carrera" />

    <!-- Aviso visible de mensaje nuevo (pedido explícito del usuario: "suena
         pero no sé qué es ni de dónde viene") — queda arriba de todo sin
         importar el scroll; tocarlo lleva directo al chat. -->
    <Teleport to="body">
        <div
            v-if="chatToast"
            class="fixed top-4 inset-x-4 sm:inset-x-auto sm:right-4 sm:w-96 z-50 cursor-pointer"
            @click="dismissChatToast(true)"
        >
            <div class="p-3 bg-arka-card border border-arka-primary/30 shadow-lg rounded-arka flex items-start gap-3">
                <span class="h-8 w-8 rounded-full bg-arka-primary/15 flex items-center justify-center shrink-0">
                    <svg class="h-4 w-4 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 5.5h16v10.5H8.5L4 20V5.5Z" />
                    </svg>
                </span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-arka-text truncate">💬 {{ chatToast.sender_name }}</p>
                    <p class="text-xs text-arka-text-muted truncate">{{ chatToast.body }}</p>
                </div>
                <button
                    type="button"
                    class="text-arka-text-muted hover:text-arka-text shrink-0"
                    @click.stop="dismissChatToast(false)"
                >
                    ✕
                </button>
            </div>
        </div>
    </Teleport>

    <!-- Aviso de cambio de estado (pedido explícito del usuario: "el cliente
         no tiene más que solo cambian el contenido en la pantalla, pero no
         algo más visual ni tono") — mismo patrón que el toast de chat de
         arriba, con su propio ícono para distinguirlo de un mensaje. -->
    <Teleport to="body">
        <div v-if="statusToast" class="fixed top-4 inset-x-4 sm:inset-x-auto sm:right-4 sm:w-96 z-50 cursor-pointer" @click="statusToast = null">
            <div class="p-3 bg-arka-card border border-arka-primary/30 shadow-lg rounded-arka flex items-center gap-3">
                <span class="h-8 w-8 rounded-full bg-arka-primary/15 flex items-center justify-center shrink-0">
                    <svg class="h-4 w-4 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-6.5 7-11.5A7 7 0 0 0 5 9.5C5 14.5 12 21 12 21Z" />
                        <circle cx="12" cy="9.5" r="2.5" />
                    </svg>
                </span>
                <p class="flex-1 text-sm font-medium text-arka-text">{{ statusToast }}</p>
                <button type="button" class="text-arka-text-muted hover:text-arka-text shrink-0" @click.stop="statusToast = null">✕</button>
            </div>
        </div>
    </Teleport>

    <AuthenticatedLayout :transparent-nav="clientFullscreenTrip" :hide-bottom-nav="clientFullscreenTrip || (isDriver && ['scheduled', 'in_progress'].includes(ride.status))">
        <!-- Pedido explícito del usuario ("esa parte de arriba podemos
             quitarla porque se entiende que va a una carrera y el nombre del
             cliente aparece abajo... más accesibilidad para un conductor"):
             se saca la barra de encabezado entera para el conductor, y
             también para el cliente mientras ve la pantalla a toda pantalla
             de acá abajo (el layout ya la esconde sola si no le pasamos este
             slot, ver AuthenticatedLayout.vue `v-if="$slots.header"`). -->
        <template v-if="!isDriver && !clientFullscreenTrip" #header>
            <h2 class="font-semibold text-xl text-arka-text leading-tight">
                Carrera con {{ ride.driver.name }}
            </h2>
        </template>

        <!-- Pantalla del cliente durante el viaje, a toda pantalla (pedido
             explícito del usuario, con mockup de referencia estilo Uber/DiDi:
             "el cliente no debería ver más datos o opciones... y que se vea
             esa trayectoria") — mapa de fondo fijo, sin scroll de página,
             sin ninguna tarjeta de más: solo lo que ya se ve acá. Mismo
             patrón `fixed inset-0` que ya usa Dashboard.vue para el mapa de
             Inicio en móvil. -->
        <template v-if="clientFullscreenTrip">
            <div class="fixed inset-0 z-0">
                <FleetMap
                    :markers="trackingMapMarkers"
                    :route="visibleRouteCoords"
                    :clickable="false"
                    :auto-fit="true"
                    :rounded="false"
                    :dark="false"
                    :fit-padding-top="150"
                    :fit-padding-bottom="145"
                    :zoom="16"
                    height="100%"
                    controls-top-offset="8.5rem"
                />
            </div>

            <!-- Tarjeta del conductor: foto, nombre, ★ y placa — nunca tapa
                 el mapa más de lo necesario, para que el auto y la
                 trayectoria (pedido explícito del usuario: "el conductor
                 nunca debería ir abajo [de otra capa]") siempre se vean. -->
            <div class="fixed inset-x-3 top-20 sm:top-3 z-10 p-3 bg-arka-card/95 backdrop-blur-sm shadow-lg rounded-arka border border-arka-text-muted/10 flex items-center gap-3">
                <UserAvatar :user="counterpart" size-class="h-12 w-12 text-sm shrink-0" />
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-arka-text flex items-center gap-1.5">
                        {{ counterpart.name }}
                        <span v-if="counterpart.review_count > 0" class="text-xs text-arka-lime shrink-0">
                            ★ {{ Number(counterpart.average_rating).toFixed(1) }}
                        </span>
                    </p>
                    <p v-if="ride.driver.driver_profile?.vehicle_plate" class="text-xs text-arka-text-muted">
                        {{ ride.driver.driver_profile.vehicle_plate }}
                    </p>
                    <p v-if="ride.driver.driver_profile?.vehicle_make" class="mt-0.5 truncate text-[11px] text-arka-text-muted/80">
                        {{ ride.driver.driver_profile.vehicle_make }} {{ ride.driver.driver_profile.vehicle_model }}
                        <span v-if="ride.driver.driver_profile.vehicle_color"> · {{ ride.driver.driver_profile.vehicle_color }}</span>
                    </p>
                </div>
                <span
                    v-if="pickupEta"
                    class="shrink-0 px-3 py-1.5 rounded-arka bg-arka-primary/15 text-center"
                >
                    <span class="block text-[10px] text-arka-primary-bright/80 leading-none">Llegando en</span>
                    <span class="block text-sm font-semibold text-arka-primary-bright leading-tight">{{ pickupEta.minutes }} min</span>
                </span>
                <span
                    v-else-if="pickupWaitCountdown"
                    class="shrink-0 rounded-arka border border-arka-warning/30 bg-arka-warning/10 px-3 py-1.5 text-center"
                >
                    <span class="block text-[10px] leading-none text-arka-warning/80">Tiempo de espera</span>
                    <span class="block text-sm font-bold leading-tight text-arka-warning">{{ pickupWaitCountdown.label }}</span>
                </span>
            </div>

            <!-- Acceso chico a mensaje/seguridad (pedido explícito del
                 usuario: nada de tarjetas de más) — un ícono discreto, no
                 compite con la tarjeta de arriba ni con el mapa. -->
            <button
                type="button"
                class="fixed right-3 top-[9.25rem] sm:top-[5.75rem] z-10 h-10 w-10 rounded-full bg-arka-card/95 backdrop-blur-sm shadow-lg flex items-center justify-center text-arka-text-muted hover:text-arka-text"
                aria-label="Seguridad y seguimiento"
                @click="openClientSafety"
            >
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m12 3 8 3.5v5.2c0 4.4-3 7.6-8 9.3-5-1.7-8-4.9-8-9.3V6.5L12 3Z" />
                </svg>
            </button>

            <!-- Progreso y acciones esenciales, siempre visibles sin cubrir
                 innecesariamente el mapa. -->
            <div class="fixed inset-x-3 bottom-3 z-10 space-y-2">
                <div class="rounded-arka border border-arka-text-muted/10 bg-arka-card/95 px-3 py-3 shadow-lg backdrop-blur-sm">
                    <div class="flex items-start">
                        <div
                            v-for="(step, index) in tripProgressSteps"
                            :key="step.label"
                            class="relative flex min-w-0 flex-1 flex-col items-center"
                        >
                            <div
                                v-if="index < tripProgressSteps.length - 1"
                                class="absolute left-1/2 top-[5px] h-0.5 w-full"
                                :class="tripProgressSteps[index + 1].complete ? 'bg-arka-primary' : 'bg-arka-text-muted/20'"
                            />
                            <span
                                class="relative z-10 h-3 w-3 rounded-full border-2"
                                :class="step.complete
                                    ? 'border-arka-primary bg-arka-primary'
                                    : index === currentTripProgressIndex
                                        ? 'border-arka-primary bg-arka-card ring-4 ring-arka-primary/15'
                                        : 'border-arka-text-muted/30 bg-arka-card'"
                            />
                            <span
                                class="mt-2 max-w-full truncate text-center text-[9px] leading-tight"
                                :class="step.complete || index === currentTripProgressIndex ? 'font-medium text-arka-text' : 'text-arka-text-muted/60'"
                            >
                                {{ step.label }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="flex-1 px-3 py-2.5 rounded-arka bg-arka-card/95 backdrop-blur-sm shadow-lg border border-arka-text-muted/10 text-arka-text text-sm font-medium"
                        @click="openClientMessage"
                    >
                        <svg class="mr-2 inline h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4.5 3.75A2.75 2.75 0 0 0 1.75 6.5v8A2.75 2.75 0 0 0 4.5 17.25h2v3a.75.75 0 0 0 1.2.6l4.8-3.6h7A2.75 2.75 0 0 0 22.25 14.5v-8a2.75 2.75 0 0 0-2.75-2.75h-15Z" /></svg>
                        Mensaje
                    </button>
                    <a
                        v-if="counterpart.phone"
                        :href="`tel:${counterpart.phone}`"
                        class="flex-1 text-center px-3 py-2.5 rounded-arka bg-arka-card/95 backdrop-blur-sm shadow-lg border border-arka-text-muted/10 text-arka-text text-sm font-medium"
                    >
                        <svg class="mr-2 inline h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6.62 2.25c.55 0 1.04.34 1.24.85l1.3 3.42a1.34 1.34 0 0 1-.33 1.45L7.2 9.5a14.1 14.1 0 0 0 7.3 7.3l1.53-1.63a1.34 1.34 0 0 1 1.45-.33l3.42 1.3c.51.2.85.69.85 1.24v2.37a2 2 0 0 1-2 2C10.09 21.75 2.25 13.91 2.25 4.25a2 2 0 0 1 2-2h2.37Z" /></svg>
                        Llamar
                    </a>
                </div>

                <DangerButton class="w-full justify-center" @click="quickCancelRide">Cancelar viaje</DangerButton>
            </div>

            <!-- Panel de mensaje/seguridad — dos vistas, mismo patrón que el
                 "⋯" del conductor. -->
            <BottomSheet :show="showClientOptions" @close="showClientOptions = false">
                <div class="p-4 pb-6">
                    <template v-if="clientOptionsView === 'chat'">
                        <h3 class="text-center text-arka-text font-medium mb-3">Mensaje con {{ counterpart.name }}</h3>

                        <div ref="chatListEl" class="max-h-[42vh] overflow-y-auto space-y-2 pe-1">
                            <p v-if="!chatMessages.length" class="text-sm text-arka-text-muted">
                                Todavía no hay mensajes — use una respuesta rápida o escriba la suya.
                            </p>
                            <div
                                v-for="message in chatMessages"
                                :key="message.id"
                                class="max-w-[80%] px-3 py-2 rounded-arka text-sm"
                                :class="message.sender_user_id === $page.props.auth.user.id
                                    ? 'ms-auto bg-arka-primary text-arka-base'
                                    : 'bg-arka-base text-arka-text'"
                            >
                                <p>{{ message.body }}</p>
                                <p
                                    class="mt-0.5 text-[10px]"
                                    :class="message.sender_user_id === $page.props.auth.user.id ? 'text-arka-base/70' : 'text-arka-text-muted'"
                                >
                                    {{ new Date(message.created_at).toLocaleTimeString('es-EC', { hour: '2-digit', minute: '2-digit' }) }}
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-1.5 mt-3">
                            <button
                                v-for="reply in quickReplies"
                                :key="reply"
                                type="button"
                                class="px-2 py-1 rounded-arka text-xs bg-arka-base text-arka-text-muted hover:text-arka-text border border-arka-text-muted/20"
                                :disabled="chatSending"
                                @click="sendChatMessage(reply)"
                            >
                                {{ reply }}
                            </button>
                        </div>

                        <form @submit.prevent="sendChatMessage()" class="flex items-center gap-2 mt-2">
                            <TextInput v-model="chatBody" type="text" class="flex-1" placeholder="Escriba un mensaje…" maxlength="500" />
                            <PrimaryButton :disabled="chatSending || !chatBody.trim()">Enviar</PrimaryButton>
                        </form>
                        <InputError :message="chatError" />
                    </template>

                    <template v-else>
                        <h3 class="text-center text-arka-text font-medium mb-4">Seguridad y seguimiento</h3>
                        <div class="space-y-1">
                            <button
                                v-if="!trackingUrl"
                                type="button"
                                class="w-full flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-base min-h-[44px] text-arka-text text-start"
                                @click="shareTracking"
                            >
                                <svg class="h-5 w-5 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="18" cy="6" r="2.5" />
                                    <circle cx="6" cy="12" r="2.5" />
                                    <circle cx="18" cy="18" r="2.5" />
                                    <path stroke-linecap="round" d="M8.2 10.8 15.8 7.2M8.2 13.2l7.6 3.6" />
                                </svg>
                                Compartir seguimiento en vivo
                            </button>
                            <div v-else class="px-3 py-2 space-y-2">
                                <p class="text-xs text-arka-text-muted">Enlace de seguimiento en vivo</p>
                                <div class="flex items-center gap-2">
                                    <input
                                        type="text"
                                        readonly
                                        :value="trackingUrl"
                                        class="flex-1 min-w-0 rounded-arka border-arka-text-muted/20 bg-arka-base text-arka-text text-sm px-3 py-1.5"
                                        @focus="$event.target.select()"
                                    />
                                    <SecondaryButton size="sm" @click="copyTrackingUrl">{{ trackingCopied ? 'Copiado' : 'Copiar' }}</SecondaryButton>
                                </div>
                            </div>

                            <button
                                v-if="!sosSent"
                                type="button"
                                class="w-full flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-danger/10 min-h-[44px] text-arka-danger text-start"
                                @click="triggerSos"
                            >
                                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4M12 16.5h.01" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.3 3.9 2.6 17.5a1.8 1.8 0 0 0 1.6 2.7h15.6a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a1.8 1.8 0 0 0-3.4 0Z" />
                                </svg>
                                SOS — Alerta de emergencia
                            </button>
                            <p v-else class="px-3 py-3 text-sm text-arka-primary-bright">Alerta enviada a sus contactos de confianza.</p>
                        </div>
                    </template>
                </div>
            </BottomSheet>

            <!-- Cuentas bancarias del conductor (pedido explícito del usuario):
                 la favorita primero (RideController::show() ya la ordena así). -->
            <BottomSheet :show="showBankAccounts" @close="showBankAccounts = false">
                <div class="p-4 space-y-4">
                    <h3 class="text-lg font-semibold text-arka-text">Cuenta para transferir</h3>
                    <p class="text-sm text-arka-text-muted">Declaradas por {{ ride.driver.name }}.</p>

                    <div
                        v-for="account in driverBankAccounts"
                        :key="account.id"
                        class="rounded-xl border p-4"
                        :class="account.is_favorite ? 'border-arka-primary bg-arka-primary/5' : 'border-arka-text-muted/15'"
                    >
                        <p class="flex items-center gap-1.5 font-semibold text-arka-text">
                            <span v-if="account.is_favorite" class="text-arka-primary" aria-label="Favorita">★</span>
                            {{ account.bank_name }}
                        </p>
                        <dl class="mt-2 space-y-1 text-sm">
                            <div class="flex justify-between gap-3"><dt class="text-arka-text-muted">Titular</dt><dd class="text-right font-medium text-arka-text">{{ account.account_holder_name }}</dd></div>
                            <div class="flex justify-between"><dt class="text-arka-text-muted">Tipo</dt><dd class="text-arka-text">{{ account.account_type === 'ahorros' ? 'Ahorros' : 'Corriente' }}</dd></div>
                            <div class="flex justify-between"><dt class="text-arka-text-muted">Número</dt><dd class="font-medium text-arka-text">{{ account.account_number }}</dd></div>
                            <div class="flex justify-between"><dt class="text-arka-text-muted">Cédula</dt><dd class="text-arka-text">{{ account.masked_identity_number }}</dd></div>
                        </dl>
                    </div>
                </div>
            </BottomSheet>

            <!-- Cancelar viaje: mismo motivo/nota de siempre, en un panel en
                 vez de la tarjeta inline (acá no hay página debajo para
                 mostrarla). -->
            <BottomSheet :show="showCancelForm" @close="showCancelForm = false">
                <div class="p-4 pb-6 space-y-3">
                    <h3 class="text-center text-arka-text font-medium">¿Cancelar el viaje?</h3>
                    <p v-if="ride.status === 'in_progress'" class="text-sm text-arka-danger">
                        Su conductor ya está en camino — se le va a avisar apenas cancele.
                    </p>
                    <p v-if="cancelError" class="rounded-xl border border-arka-danger/30 bg-arka-danger/10 px-3 py-2 text-sm text-arka-danger" role="alert">{{ cancelError }}</p>
                    <div>
                        <InputLabel value="Motivo de la cancelación" />
                        <select
                            v-model="cancelReason"
                            class="mt-1 block w-full rounded-arka border-arka-text-muted/30 bg-arka-card text-arka-text focus:border-arka-primary focus:ring-arka-primary"
                        >
                            <option value="" disabled>Elija un motivo</option>
                            <option v-for="reason in cancelReasons" :key="reason" :value="reason">{{ reason }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel value="Observación (opcional)" />
                        <TextInput
                            type="text"
                            class="mt-1 block w-full"
                            v-model="cancelNote"
                            maxlength="500"
                            placeholder="Algún detalle más, si quiere agregarlo"
                        />
                    </div>
                    <div class="flex gap-2">
                        <DangerButton class="flex-1 justify-center" :disabled="!cancelReason || cancellingRide" @click="submitCancelRide">
                            Confirmar cancelación
                        </DangerButton>
                        <SecondaryButton class="flex-1 justify-center" @click="showCancelForm = false">Volver</SecondaryButton>
                    </div>
                </div>
            </BottomSheet>
        </template>

        <!-- Menos padding arriba para el conductor (ya no hay barra de
             encabezado que compense) y más abajo mientras la barra fija del
             paso actual está en pantalla, para que no tape el final de la
             tarjeta de "Cancelar viaje". -->
        <div v-else :class="isDriver && ['scheduled', 'in_progress'].includes(ride.status) ? 'pt-4 pb-28' : 'py-12'">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Carrera PROGRAMADA todavía sin arrancar (consideración agregada al
                     alcance: "ahora mismo" vs "programación") -->
                <div v-if="ride.status === 'scheduled'" class="p-4 bg-arka-warning/15 border border-arka-warning/40 rounded-arka space-y-3">
                    <div class="flex items-center justify-between gap-3 flex-wrap">
                        <p class="font-semibold text-arka-warning">
                            📅 Programada para {{ new Date(ride.ride_request?.scheduled_at).toLocaleString('es-EC', { dateStyle: 'medium', timeStyle: 'short' }) }}
                        </p>
                        <!-- Pedido explícito del usuario: "que puedan editar una
                             carrera programada si es que se equivocaron" — solo
                             el cliente, y solo si no hay ya otro cambio esperando
                             respuesta del conductor. -->
                        <button
                            v-if="!isDriver && !ride.pending_reschedule_at"
                            type="button"
                            class="text-sm text-arka-primary hover:text-arka-primary-bright"
                            @click="showRescheduleForm = !showRescheduleForm"
                        >
                            {{ showRescheduleForm ? 'Cancelar' : 'Editar horario' }}
                        </button>
                    </div>
                    <p v-if="ride.round_trip" class="text-sm text-arka-text-muted">Incluye ida y vuelta.</p>
                    <p v-if="isDriver" class="text-sm text-arka-text-muted">
                        Todavía no cuenta como "ocupado" para su flota — arránquela cuando salga a buscar al cliente.
                    </p>

                    <!-- Formulario para proponer otro horario (mismos tres
                         <select> explícitos que al pedir la carrera, sin
                         reloj nativo). No se aplica solo: el conductor tiene
                         que confirmarlo. -->
                    <div v-if="showRescheduleForm" class="pt-2 border-t border-arka-warning/30 space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <InputLabel value="Nueva fecha" />
                                <TextInput
                                    type="date"
                                    class="mt-1 block w-full"
                                    :min="todayDateString"
                                    v-model="rescheduleDate"
                                />
                            </div>
                            <div>
                                <InputLabel value="Nueva hora" />
                                <div class="mt-1 grid grid-cols-3 gap-2">
                                    <select
                                        v-model="rescheduleHour"
                                        aria-label="Hora"
                                        class="w-full rounded-arka border-arka-text-muted/30 bg-arka-card text-arka-text shadow-sm focus:border-arka-primary focus:ring-arka-primary"
                                    >
                                        <option value="" disabled>Hora</option>
                                        <option v-for="hour in RESCHEDULE_HOUR_OPTIONS" :key="hour" :value="hour">{{ hour }}</option>
                                    </select>
                                    <select
                                        v-model="rescheduleMinute"
                                        aria-label="Minutos"
                                        class="w-full rounded-arka border-arka-text-muted/30 bg-arka-card text-arka-text shadow-sm focus:border-arka-primary focus:ring-arka-primary"
                                    >
                                        <option value="" disabled>Min.</option>
                                        <option v-for="minute in RESCHEDULE_MINUTE_OPTIONS" :key="minute" :value="minute">{{ minute }}</option>
                                    </select>
                                    <select
                                        v-model="reschedulePeriod"
                                        aria-label="A. m. o p. m."
                                        class="w-full rounded-arka border-arka-text-muted/30 bg-arka-card text-arka-text shadow-sm focus:border-arka-primary focus:ring-arka-primary"
                                    >
                                        <option value="AM">a. m.</option>
                                        <option value="PM">p. m.</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <InputError :message="rescheduleError" />
                        <PrimaryButton
                            :disabled="reschedulingRide || !rescheduleDate || !rescheduleHour || rescheduleMinute === ''"
                            @click="submitReschedule"
                        >
                            Mandar nuevo horario
                        </PrimaryButton>
                    </div>

                    <!-- Cambio de horario esperando respuesta del conductor. -->
                    <div v-if="ride.pending_reschedule_at" class="pt-2 border-t border-arka-warning/30">
                        <p class="text-sm text-arka-text">
                            <template v-if="isDriver">
                                El cliente propuso cambiar el horario a
                                <span class="font-medium">{{ new Date(ride.pending_reschedule_at).toLocaleString('es-EC', { dateStyle: 'medium', timeStyle: 'short' }) }}</span>.
                            </template>
                            <template v-else>
                                Le mandó al conductor el nuevo horario propuesto (<span class="font-medium">{{ new Date(ride.pending_reschedule_at).toLocaleString('es-EC', { dateStyle: 'medium', timeStyle: 'short' }) }}</span>) — esperando que lo confirme.
                            </template>
                        </p>
                        <div v-if="isDriver" class="mt-2 flex gap-2">
                            <PrimaryButton @click="confirmReschedule">Confirmar nuevo horario</PrimaryButton>
                            <SecondaryButton @click="rejectReschedule">Rechazar</SecondaryButton>
                        </div>
                    </div>
                </div>

                <!-- Sector de origen/destino (consideración agregada al alcance): de
                     un vistazo, sin tener que leer el mapa — ej. "Sauces 1 → Samanes 3". -->
                <div
                    v-if="ride.origin_sector?.name || ride.destination_sector?.name || ride.origin_address || ride.destination_address"
                    class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-1"
                >
                    <p v-if="ride.origin_sector?.name || ride.destination_sector?.name" class="text-arka-text font-medium">
                        {{ ride.origin_sector?.name ?? 'Origen sin sector' }} &rarr; {{ ride.destination_sector?.name ?? 'Destino sin sector' }}
                    </p>
                    <p v-if="ride.origin_address || ride.destination_address" class="text-sm text-arka-text-muted">
                        {{ ride.origin_address ?? 'Sin referencia de origen' }} &rarr;
                        {{ ride.destination_address ?? 'Sin referencia de destino' }}
                    </p>
                    <!-- Observación del cliente (pedido explícito del usuario): la
                         sigue teniendo a mano el conductor durante toda la carrera. -->
                    <p v-if="ride.notes" class="text-sm text-arka-text-muted italic pt-1">"{{ ride.notes }}"</p>
                </div>

                <!-- Mapa a todo el ancho, sin la tarjeta que lo contenía antes (rediseño
                     UX, con mockup de referencia estilo Uber/DiDi) — la tarjeta del
                     conductor/cliente de acá abajo flota encima, mismo patrón "-mt-6
                     mx-3" que ya usa Ride/Request.vue para origen/destino.
                     Pedido explícito del usuario: "permitir que el mapa se expanda más
                     y sea más claro el recorrido... que sea como Google Maps" — botón
                     de expandir/achicar arriba a la derecha (mismo lugar que los
                     controles propios de Google Maps) y la distancia/tiempo restante
                     como una pastilla flotante abajo a la izquierda, igual que la
                     tarjeta de navegación real. -->
                <div class="-mx-4 sm:mx-0 relative">
                    <FleetMap
                        ref="fleetMapRef"
                        :markers="mapMarkers"
                        :route="visibleRouteCoords"
                        :dark="false"
                        :fit-padding-top="55"
                        :fit-padding-bottom="55"
                        :zoom="15"
                        :height="mapExpanded ? '65vh' : '320px'"
                        class="transition-[height] duration-300"
                        @user-panned="followDriver = false"
                    />

                    <div class="absolute right-3 top-3 z-[400] flex flex-col gap-2">
                        <button
                            type="button"
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-white text-arka-base shadow-lg hover:bg-gray-50 transition"
                            :aria-label="mapExpanded ? 'Achicar mapa' : 'Expandir mapa'"
                            @click="mapExpanded = !mapExpanded"
                        >
                            <svg v-if="!mapExpanded" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 4H4v5M15 4h5v5M9 20H4v-5M15 20h5v-5" />
                            </svg>
                            <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" />
                            </svg>
                        </button>

                        <!-- Pedido explícito del usuario: "un botón que centre la
                             ubicación como Google Maps" — vuelve a centrar/hacer zoom
                             sobre el conductor y reactiva el seguimiento automático si
                             se había pausado al arrastrar el mapa a mano. -->
                        <button
                            v-if="isDriver"
                            type="button"
                            class="flex h-9 w-9 items-center justify-center rounded-full shadow-lg transition"
                            :class="followDriver ? 'bg-arka-primary text-arka-base' : 'bg-white text-arka-base hover:bg-gray-50'"
                            aria-label="Centrar mi ubicación"
                            title="Centrar mi ubicación"
                            @click="recenterMapButton"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="3" />
                                <path stroke-linecap="round" d="M12 2v3M12 19v3M22 12h-3M5 12H2" />
                            </svg>
                        </button>

                        <!-- Pedido explícito del usuario: "botón de navegación en el
                             mapa del conductor que lleve a Google Maps" — siempre
                             disponible, sin depender de qué botón toque en la barra
                             de abajo. Va al punto correcto según la etapa (origen,
                             próxima parada, o destino — ver currentNavigationTarget). -->
                        <a
                            v-if="isDriver"
                            :href="googleNavigateUrl(currentNavigationTarget.lat, currentNavigationTarget.lng)"
                            target="_blank"
                            rel="noopener"
                            class="flex h-9 w-9 items-center justify-center rounded-full bg-white text-arka-base shadow-lg hover:bg-gray-50 transition"
                            aria-label="Abrir en Google Maps"
                            title="Abrir en Google Maps"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7-4.6-7-10.5A7 7 0 0 1 12 4a7 7 0 0 1 7 6.5C19 16.4 12 21 12 21Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="m9.5 10.5 2-2 2 2-2 2-2-2Z" />
                            </svg>
                        </a>
                    </div>

                    <div
                        v-if="isDriver && driverRemainingLabel"
                        class="absolute left-3 bottom-3 z-[400] flex items-center gap-2 rounded-full bg-white px-4 py-2 text-arka-base shadow-lg"
                    >
                        <span class="text-arka-primary" aria-hidden="true">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><path d="M13 3h-2v10h2V3Zm4.3 2.3-1.4 1.4A6.9 6.9 0 0 1 19 12a7 7 0 1 1-11.9-5l-1.4-1.4A9 9 0 1 0 21 12a8.9 8.9 0 0 0-3.7-6.7Z"/></svg>
                        </span>
                        <span class="text-sm font-semibold">{{ driverRemainingLabel }}</span>
                    </div>
                </div>

                <!-- Tarjeta del conductor/cliente: antes esta info vivía repartida
                     entre el header (Mensaje/Llamar) y banners sueltos arriba del
                     mapa (uno por cada hito de "en camino") — ahora es una sola
                     tarjeta, visible mientras hay una relación de viaje vigente. -->
                <div
                    v-if="['scheduled', 'in_progress'].includes(ride.status)"
                    class="-mt-6 mx-3 relative z-10 p-4 bg-arka-card shadow-lg rounded-arka border border-arka-text-muted/10 space-y-3"
                >
                    <div class="flex items-center gap-3">
                        <UserAvatar :user="counterpart" size-class="h-14 w-14 text-base" />
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-arka-text flex items-center gap-1.5 flex-wrap">
                                {{ counterpart.name }}
                                <span v-if="counterpart.review_count > 0" class="text-xs text-arka-text-muted">
                                    ★ {{ Number(counterpart.average_rating).toFixed(1) }}
                                </span>
                            </p>
                            <p v-if="!isDriver && ride.driver.driver_profile?.vehicle_make" class="text-sm text-arka-text-muted truncate">
                                {{ ride.driver.driver_profile.vehicle_make }} {{ ride.driver.driver_profile.vehicle_model }}
                                {{ ride.driver.driver_profile.vehicle_color }} · {{ ride.driver.driver_profile.vehicle_plate }}
                            </p>
                        </div>
                        <!-- Píldora de ETA (pedido explícito del usuario: "cuando el
                             conductor sale a buscar al cliente, avisarle... en cuánto
                             tiempo") — mismo pickupEta ya calculado. -->
                        <span
                            v-if="pickupEta"
                            class="shrink-0 px-3 py-1.5 rounded-full bg-arka-primary/15 text-arka-primary-bright text-xs font-semibold"
                        >
                            Llega en {{ pickupEta.minutes }} min
                        </span>
                    </div>

                    <!-- Estado en vivo (solo para el cliente, mientras el viaje sigue
                         en curso) — mismo texto que antes vivía en banners separados. -->
                    <p v-if="!isDriver && ride.status === 'in_progress' && !ride.picked_up_at" class="text-sm text-arka-text-muted">
                        <template v-if="ride.arrived_at">
                            📍 Lo está esperando en el punto de encuentro.
                            <strong v-if="pickupWaitCountdown" class="ms-1 text-arka-warning">{{ pickupWaitCountdown.label }}</strong>
                        </template>
                        <template v-else-if="pickupEta">🚗 Viene en camino — está a {{ pickupEta.km.toFixed(1) }} km.</template>
                        <template v-else>🚗 Viene en camino — buscando su ubicación en vivo…</template>
                    </p>
                    <p v-if="!isDriver && ride.status === 'in_progress' && ride.picked_up_at" class="text-sm text-arka-text-muted">
                        🚙 Viaje en curso hacia el destino.
                    </p>

                    <!-- Cuentas bancarias del conductor (pedido explícito del
                         usuario): solo cuando la carrera es por transferencia y
                         todavía no lo recoge — ver RideController::show(), que ya
                         filtra esto y nunca manda la cédula completa. -->
                    <button
                        v-if="!isDriver && ride.status === 'in_progress' && !ride.picked_up_at && driverBankAccounts.length"
                        type="button"
                        class="flex w-full items-center justify-between gap-3 rounded-xl border border-arka-primary/25 bg-arka-primary/10 px-3 py-2.5 text-left"
                        @click="showBankAccounts = true"
                    >
                        <span class="text-sm font-medium text-arka-primary">💳 Cuenta para transferir</span>
                        <span class="text-xs text-arka-primary">Ver ›</span>
                    </button>
                    <div
                        v-if="isDriver && pickupWaitCountdown"
                        class="flex items-center justify-between gap-3 rounded-xl border border-arka-warning/30 bg-arka-warning/10 px-3 py-2.5"
                    >
                        <div>
                            <p class="text-xs font-semibold text-arka-warning">Esperando al pasajero</p>
                            <p class="text-[11px] text-arka-text-muted">Tiempo de cortesía: 5 minutos</p>
                        </div>
                        <span class="font-mono text-lg font-bold text-arka-warning">{{ pickupWaitCountdown.label }}</span>
                    </div>

                    <!-- Datos de la carrera (pedido explícito del usuario: "en esa
                         misma caja del cliente coloca los datos de la carrera") —
                         solo para el conductor: lo que antes vivía en una tarjeta
                         aparte, más abajo en la página, sale acá mismo junto al
                         pasajero. La tarjeta de abajo se saca para él (queda solo
                         para el cliente, sin cambios). -->
                    <div v-if="isDriver" class="grid grid-cols-2 gap-x-3 gap-y-1.5 text-sm border-t border-arka-text-muted/10 pt-3">
                        <div class="flex items-center justify-between col-span-2">
                            <span class="text-arka-text-muted">Estado</span>
                            <span class="text-arka-text font-medium">{{ statusLabel[ride.status] }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-arka-text-muted">Distancia</span>
                            <span class="text-arka-text">{{ Number(ride.distance_km).toFixed(1) }} km</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-arka-text-muted">Pago</span>
                            <span class="text-arka-text capitalize">{{ ride.payment_method ?? 'efectivo' }}</span>
                        </div>
                        <div v-if="waitLabel" class="flex items-center justify-between col-span-2">
                            <span class="text-arka-text-muted">Tiempo de espera</span>
                            <span class="text-arka-text">{{ waitLabel }}</span>
                        </div>
                        <div class="flex items-center justify-between col-span-2 pt-1.5 border-t border-arka-text-muted/10 text-base">
                            <span class="text-arka-text-muted">Total</span>
                            <span class="text-arka-primary-bright font-semibold">${{ ride.price }}</span>
                        </div>
                    </div>

                    <!-- Mensaje/Llamar/Cancelar (sección 6 y 14 del documento de
                         rediseño UX) — acá SOLO para el cliente. Pedido explícito
                         del usuario ("unificalo un poco mejor... lo que está
                         debajo, como el chat y el SOS, colocalo en un botón
                         flotante de opciones, es para la comodidad del
                         conductor"): para el conductor, esta tarjeta queda solo
                         informativa — estas mismas acciones (+ navegación, SOS,
                         seguimiento) viven en el botón "⋯" flotante de más abajo,
                         junto con el botón fijo del paso actual, para que nunca
                         tenga que scrollear buscándolos durante el viaje. -->
                    <div v-if="chatOpen && !isDriver" class="flex items-center gap-2 pt-1">
                        <button
                            type="button"
                            class="flex-1 px-3 py-2 rounded-arka bg-arka-base text-arka-text text-sm font-medium hover:bg-arka-base/70 transition"
                            @click="openClientMessage"
                        >
                            <svg class="mr-2 inline h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4.5 3.75A2.75 2.75 0 0 0 1.75 6.5v8A2.75 2.75 0 0 0 4.5 17.25h2v3a.75.75 0 0 0 1.2.6l4.8-3.6h7A2.75 2.75 0 0 0 22.25 14.5v-8a2.75 2.75 0 0 0-2.75-2.75h-15Z" /></svg>
                            Mensaje
                        </button>
                        <a
                            v-if="counterpart.phone"
                            :href="`tel:${counterpart.phone}`"
                            class="flex-1 text-center px-3 py-2 rounded-arka bg-arka-base text-arka-text text-sm font-medium hover:bg-arka-base/70 transition"
                        >
                            📞 Llamar
                        </a>
                    </div>

                    <button
                        v-if="!isDriver && ['scheduled', 'in_progress'].includes(ride.status)"
                        type="button"
                        class="w-full text-center text-sm text-arka-danger hover:opacity-80"
                        @click="quickCancelRide"
                    >
                        Cancelar viaje
                    </button>
                </div>

                <!-- Botón "⋯" flotante del conductor + barra fija del paso actual
                     (pedido explícito del usuario: "unificalo un poco mejor... el
                     botón de ya llegué debe ser flotante también, la idea es que
                     no scrollee") — teleportados al body, mismo motivo que los
                     avisos de arriba (chatToast/statusToast): un `fixed` de acá
                     adentro no se posiciona contra la ventana, sino contra este
                     contenedor. -->
                <Teleport to="body">
                    <button
                        v-if="isDriver && chatOpen"
                        type="button"
                        class="fixed right-4 bottom-24 z-30 h-12 w-12 rounded-full bg-arka-card border border-arka-text-muted/20 shadow-2xl flex items-center justify-center text-arka-text hover:bg-arka-base transition"
                        aria-label="Más opciones"
                        @click="openDriverOptions"
                    >
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
                            <circle cx="5" cy="12" r="1.7" />
                            <circle cx="12" cy="12" r="1.7" />
                            <circle cx="19" cy="12" r="1.7" />
                        </svg>
                    </button>

                    <!-- Barra fija con el botón del paso actual — nunca hace falta
                         scrollear para encontrarlo, sin importar en qué parte de la
                         pantalla esté mirando el conductor. -->
                    <div
                        v-if="isDriver && !showCancelForm && !showCompletionReasonForm && !showStopChoice && ['scheduled', 'in_progress'].includes(ride.status)"
                        class="fixed inset-x-0 bottom-0 z-20 p-3 bg-arka-card border-t border-arka-text-muted/10 shadow-2xl flex flex-wrap gap-2"
                        style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom))"
                    >
                        <PrimaryButton
                            v-if="ride.status === 'scheduled' && !ride.pending_reschedule_at"
                            class="flex-1 justify-center"
                            @click="startRide"
                        >
                            Iniciar viaje
                        </PrimaryButton>
                        <p v-else-if="ride.status === 'scheduled'" class="flex-1 text-sm text-arka-text-muted self-center">
                            Esperando que se confirme el nuevo horario.
                        </p>

                        <template v-if="ride.status === 'in_progress'">
                            <div
                                v-if="completionFeedback"
                                class="w-full flex items-start gap-2 rounded-xl border border-arka-warning/35 bg-arka-warning/10 px-3 py-2.5 text-xs leading-relaxed text-arka-warning"
                                role="alert"
                            >
                                <span class="text-base leading-none" aria-hidden="true">📍</span>
                                <span class="flex-1">{{ completionFeedback }}</span>
                                <button type="button" class="shrink-0 text-arka-text-muted hover:text-arka-text" aria-label="Cerrar aviso" @click="completionFeedback = ''">✕</button>
                            </div>

                            <!-- "Ir por el pasajero" (solo navega) y "Ya llegué"
                                 (recién ahí marca arrived_at) son dos toques
                                 reales — ver el comentario junto a goToPassenger()
                                 sobre por qué no se pueden fusionar en uno solo.
                                 Ninguno bloquea "Completar carrera" si el
                                 conductor se los saltea. -->
                            <SecondaryButton
                                v-if="!ride.arrived_at && !ride.heading_to_passenger_at && !headingToPassenger"
                                class="flex-1 justify-center"
                                @click="goToPassenger"
                            >
                                📍 Ir por el pasajero
                            </SecondaryButton>
                            <SecondaryButton
                                v-else-if="!ride.arrived_at"
                                class="flex-1 justify-center"
                                :disabled="markingArrived"
                                @click="confirmArrived"
                            >
                                ✅ {{ markingArrived ? 'Ubicando…' : 'Ya llegué' }}
                            </SecondaryButton>
                            <SecondaryButton
                                v-else-if="!ride.picked_up_at"
                                class="flex-1 justify-center"
                                :disabled="markingPickedUp"
                                @click="startToDestination"
                            >
                                🏁 {{ markingPickedUp ? 'Ubicando…' : 'Iniciar destino' }}
                            </SecondaryButton>

                            <!-- Paradas adicionales (pedido explícito del usuario):
                                 mientras quede alguna pendiente, la próxima toma el
                                 lugar de "Completar carrera" — mismo criterio de dos
                                 toques (ir/llegué) que el resto de esta barra. -->
                            <template v-else-if="currentStop">
                                <div
                                    v-if="stopFeedback"
                                    class="w-full flex items-start gap-2 rounded-xl border border-arka-warning/35 bg-arka-warning/10 px-3 py-2.5 text-xs leading-relaxed text-arka-warning"
                                    role="alert"
                                >
                                    <span class="text-base leading-none" aria-hidden="true">📍</span>
                                    <span class="flex-1">{{ stopFeedback }}</span>
                                    <button type="button" class="shrink-0 text-arka-text-muted hover:text-arka-text" aria-label="Cerrar aviso" @click="stopFeedback = ''">✕</button>
                                </div>
                                <SecondaryButton v-if="!headingToStop" class="flex-1 justify-center" @click="goToStop(currentStop)">
                                    🚩 Ir a la parada {{ currentStop.sequence }}
                                </SecondaryButton>
                                <button
                                    v-else
                                    type="button"
                                    class="flex min-h-[50px] flex-1 items-center justify-center gap-2 rounded-xl bg-arka-primary px-4 py-2.5 text-sm font-bold uppercase tracking-wider text-arka-base shadow-lg shadow-arka-primary/20 transition hover:bg-arka-primary-bright active:scale-[0.99] disabled:cursor-wait disabled:opacity-60"
                                    :disabled="completingStop"
                                    @click="openStopChoice"
                                >
                                    ✅ Llegué a la parada {{ currentStop.sequence }}
                                </button>
                            </template>

                            <!-- Pedido explícito del usuario: la carrera la finaliza
                                 ÚNICAMENTE el conductor — solo cuando ya no queda
                                 ninguna parada pendiente. -->
                            <button
                                v-else
                                type="button"
                                class="flex min-h-[50px] flex-1 items-center justify-center gap-2 rounded-xl bg-arka-primary px-4 py-2.5 text-sm font-bold uppercase tracking-wider text-arka-base shadow-lg shadow-arka-primary/20 transition hover:bg-arka-primary-bright active:scale-[0.99] disabled:cursor-wait disabled:opacity-60"
                                :disabled="completing"
                                @click="complete"
                            >
                                <span class="grid h-6 w-6 place-items-center rounded-full bg-arka-base/15 text-base" aria-hidden="true">✓</span>
                                {{ completing ? 'Comprobando ubicación…' : 'Completar carrera' }}
                            </button>

                            <button
                                type="button"
                                class="grid h-[50px] w-[50px] shrink-0 place-items-center rounded-xl border border-arka-danger/35 bg-arka-danger/10 text-arka-danger transition hover:bg-arka-danger/20"
                                aria-label="Cancelar carrera"
                                title="Cancelar carrera"
                                @click="openDriverCancel"
                            >
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M12 2.25a9.75 9.75 0 1 0 0 19.5 9.75 9.75 0 0 0 0-19.5Zm-2.47 6.22a.75.75 0 0 0-1.06 1.06L10.94 12l-2.47 2.47a.75.75 0 1 0 1.06 1.06L12 13.06l2.47 2.47a.75.75 0 1 0 1.06-1.06L13.06 12l2.47-2.47a.75.75 0 1 0-1.06-1.06L12 10.94 9.53 8.47Z" clip-rule="evenodd" />
                                </svg>
                            </button>
                        </template>
                    </div>
                </Teleport>

                <!-- Motivo para completar lejos del destino (pedido explícito
                     del usuario: "a unos 20 metros fuera... le pedirá que
                     confirme el porque esta terminando la carrera"). Mismo
                     patrón que el BottomSheet de cancelar, más abajo. -->
                <BottomSheet v-if="isDriver" :show="showCompletionReasonForm" @close="showCompletionReasonForm = false">
                    <div class="p-4 space-y-4">
                        <div>
                            <h3 class="text-lg font-semibold text-arka-text">Todavía no está en el destino</h3>
                            <p class="mt-1 text-sm text-arka-text-muted">Puede completar la carrera igual, pero indique por qué.</p>
                        </div>
                        <div>
                            <InputLabel value="Motivo" />
                            <select v-model="completionReason" class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-arka-base text-arka-text focus:border-arka-primary focus:ring-arka-primary">
                                <option value="" disabled>Seleccione un motivo</option>
                                <option v-for="reason in EARLY_COMPLETION_REASONS" :key="reason" :value="reason">{{ reason }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Observación (opcional)" />
                            <TextInput v-model="completionNote" type="text" class="mt-1 block w-full" maxlength="500" placeholder="Agregue un detalle si hace falta" />
                        </div>
                        <p v-if="completionReasonError" class="text-sm text-arka-danger">{{ completionReasonError }}</p>
                        <div class="flex gap-3">
                            <SecondaryButton type="button" class="flex-1 justify-center" @click="showCompletionReasonForm = false">Volver</SecondaryButton>
                            <PrimaryButton type="button" class="flex-1 justify-center" :disabled="!completionReason || completing" @click="confirmEarlyCompletion">
                                {{ completing ? 'Completando…' : 'Completar carrera' }}
                            </PrimaryButton>
                        </div>
                    </div>
                </BottomSheet>

                <!-- Paradas adicionales (pedido explícito del usuario: "puedan
                     pagarle cada parada y cancelar la otra o iniciar la
                     siguiente parada") — la elección real pasa acá. -->
                <BottomSheet v-if="isDriver" :show="showStopChoice" @close="showStopChoice = false">
                    <div v-if="currentStop" class="p-4 space-y-4">
                        <div>
                            <h3 class="text-lg font-semibold text-arka-text">Parada {{ currentStop.sequence }} completada</h3>
                            <p class="mt-1 text-sm text-arka-text-muted">
                                ${{ Number(currentStop.leg_price).toFixed(2) }} de este tramo. ¿Sigue el viaje o lo cierra acá?
                            </p>
                        </div>
                        <p v-if="stopFeedback" class="text-sm text-arka-danger">{{ stopFeedback }}</p>
                        <div class="flex flex-col gap-3">
                            <PrimaryButton type="button" class="justify-center" :disabled="completingStop" @click="confirmStop(false)">
                                {{ completingStop ? 'Guardando…' : 'Continuar el viaje' }}
                            </PrimaryButton>
                            <DangerButton type="button" class="justify-center" :disabled="completingStop" @click="confirmStop(true)">
                                {{ completingStop ? 'Guardando…' : 'Cobrar y cancelar el resto' }}
                            </DangerButton>
                            <SecondaryButton type="button" class="justify-center" @click="showStopChoice = false">Volver</SecondaryButton>
                        </div>
                    </div>
                </BottomSheet>

                <!-- Panel "⋯" del conductor: navegación, mensaje/chat, llamar,
                     seguimiento, SOS y cancelar, todo en un solo lugar. Dos
                     "vistas" adentro del mismo panel (menú, o chat) en vez de
                     abrir un segundo panel encima del primero. -->
                <BottomSheet v-if="isDriver" :show="showDriverOptions" @close="showDriverOptions = false">
                    <div class="p-4 pb-6">
                        <template v-if="driverOptionsView === 'menu'">
                            <h3 class="text-center text-arka-text font-medium mb-4">Opciones del viaje</h3>

                            <!-- Pedido explícito del usuario: la navegación a Google
                                 Maps ya no vive acá suelta — se fusionó con los
                                 botones "Ir por el pasajero"/"Iniciar destino" de
                                 la barra fija de abajo, un solo toque para las
                                 dos cosas en vez de tener que buscarlas separadas. -->
                            <div class="space-y-1">
                                <button
                                    type="button"
                                    class="w-full flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-base min-h-[44px] text-arka-text text-start"
                                    @click="openChatFromOptions"
                                >
                                    <svg class="h-5 w-5 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 5.5h16v10.5H8.5L4 20V5.5Z" />
                                    </svg>
                                    Mensaje
                                    <span v-if="chatMessages.length" class="ms-auto text-xs text-arka-text-muted">{{ chatMessages.length }}</span>
                                </button>

                                <a
                                    v-if="counterpart.phone"
                                    :href="`tel:${counterpart.phone}`"
                                    class="flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-base min-h-[44px] text-arka-text"
                                >
                                    <svg class="h-5 w-5 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 4.5h3.4l1.6 4-2 1.6a12 12 0 0 0 5.4 5.4l1.6-2 4 1.6v3.4a1.5 1.5 0 0 1-1.6 1.5A15.5 15.5 0 0 1 3 6.1a1.5 1.5 0 0 1 1.5-1.6Z" />
                                    </svg>
                                    Llamar
                                </a>

                                <button
                                    v-if="ride.status === 'in_progress' && !trackingUrl"
                                    type="button"
                                    class="w-full flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-base min-h-[44px] text-arka-text text-start"
                                    @click="shareTracking"
                                >
                                    <svg class="h-5 w-5 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="18" cy="6" r="2.5" />
                                        <circle cx="6" cy="12" r="2.5" />
                                        <circle cx="18" cy="18" r="2.5" />
                                        <path stroke-linecap="round" d="M8.2 10.8 15.8 7.2M8.2 13.2l7.6 3.6" />
                                    </svg>
                                    Compartir seguimiento en vivo
                                </button>
                                <div v-else-if="ride.status === 'in_progress'" class="px-3 py-2 space-y-2">
                                    <p class="text-xs text-arka-text-muted">Enlace de seguimiento en vivo</p>
                                    <div class="flex items-center gap-2">
                                        <input
                                            type="text"
                                            readonly
                                            :value="trackingUrl"
                                            class="flex-1 min-w-0 rounded-arka border-arka-text-muted/20 bg-arka-base text-arka-text text-sm px-3 py-1.5"
                                            @focus="$event.target.select()"
                                        />
                                        <SecondaryButton size="sm" @click="copyTrackingUrl">{{ trackingCopied ? 'Copiado' : 'Copiar' }}</SecondaryButton>
                                    </div>
                                </div>

                                <button
                                    v-if="ride.status === 'in_progress' && !sosSent"
                                    type="button"
                                    class="w-full flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-danger/10 min-h-[44px] text-arka-danger text-start"
                                    @click="triggerSos"
                                >
                                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4M12 16.5h.01" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.3 3.9 2.6 17.5a1.8 1.8 0 0 0 1.6 2.7h15.6a1.8 1.8 0 0 0 1.6-2.7L13.7 3.9a1.8 1.8 0 0 0-3.4 0Z" />
                                    </svg>
                                    SOS — Alerta de emergencia
                                </button>
                                <p v-else-if="ride.status === 'in_progress'" class="px-3 py-3 text-sm text-arka-primary-bright">
                                    Alerta enviada a sus contactos de confianza.
                                </p>

                                <button
                                    type="button"
                                    class="w-full flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-danger/10 min-h-[44px] text-arka-danger text-start"
                                    @click="cancelFromOptions"
                                >
                                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="8.5" stroke-linecap="round" stroke-linejoin="round" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 9l6 6M15 9l-6 6" />
                                    </svg>
                                    Cancelar viaje
                                </button>
                            </div>
                        </template>

                        <!-- Mensaje (mismo `chatMessages`/`chatBody`/`sendChatMessage` que
                             usa la página para el cliente — nada de lógica duplicada, solo
                             el marcado vive acá también, para que el conductor lo tenga
                             adentro de este mismo panel en vez de tener que scrollear). -->
                        <template v-else-if="driverOptionsView === 'chat'">
                            <div class="flex items-center gap-2 mb-3">
                                <button
                                    type="button"
                                    class="text-arka-text-muted hover:text-arka-text px-1"
                                    aria-label="Volver"
                                    @click="driverOptionsView = 'menu'"
                                >
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m15 6-6 6 6 6" />
                                    </svg>
                                </button>
                                <h3 class="flex-1 text-center text-arka-text font-medium pe-6">Mensaje con {{ counterpart.name }}</h3>
                            </div>

                            <div ref="chatListEl" class="max-h-[42vh] overflow-y-auto space-y-2 pe-1">
                                <p v-if="!chatMessages.length" class="text-sm text-arka-text-muted">
                                    Todavía no hay mensajes — use una respuesta rápida o escriba la suya.
                                </p>
                                <div
                                    v-for="message in chatMessages"
                                    :key="message.id"
                                    class="max-w-[80%] px-3 py-2 rounded-arka text-sm"
                                    :class="message.sender_user_id === $page.props.auth.user.id
                                        ? 'ms-auto bg-arka-primary text-arka-base'
                                        : 'bg-arka-base text-arka-text'"
                                >
                                    <p>{{ message.body }}</p>
                                    <p
                                        class="mt-0.5 text-[10px]"
                                        :class="message.sender_user_id === $page.props.auth.user.id ? 'text-arka-base/70' : 'text-arka-text-muted'"
                                    >
                                        {{ new Date(message.created_at).toLocaleTimeString('es-EC', { hour: '2-digit', minute: '2-digit' }) }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-1.5 mt-3">
                                <button
                                    v-for="reply in quickReplies"
                                    :key="reply"
                                    type="button"
                                    class="px-2 py-1 rounded-arka text-xs bg-arka-base text-arka-text-muted hover:text-arka-text border border-arka-text-muted/20"
                                    :disabled="chatSending"
                                    @click="sendChatMessage(reply)"
                                >
                                    {{ reply }}
                                </button>
                            </div>

                            <form @submit.prevent="sendChatMessage()" class="flex items-center gap-2 mt-2">
                                <TextInput
                                    v-model="chatBody"
                                    type="text"
                                    class="flex-1"
                                    placeholder="Escriba un mensaje…"
                                    maxlength="500"
                                />
                                <PrimaryButton :disabled="chatSending || !chatBody.trim()">Enviar</PrimaryButton>
                            </form>
                            <InputError :message="chatError" />
                        </template>

                    </div>
                </BottomSheet>

                <!-- Panel dedicado, igual al formulario "Guardar dirección".
                     No comparte estado visual con el menú de tres puntos ni
                     depende del scroll de la página. -->
                <BottomSheet v-if="isDriver" :show="showCancelForm" @close="showCancelForm = false">
                    <div class="p-4 pb-6 space-y-4">
                        <div><h3 class="text-lg font-medium text-arka-text">Cancelar carrera</h3><p class="mt-1 text-xs text-arka-text-muted">Se notificará inmediatamente al cliente.</p></div>
                        <p v-if="ride.status === 'in_progress'" class="rounded-xl bg-arka-danger/10 px-3 py-2.5 text-sm text-arka-danger">El cliente ya está esperando. Confirme solo si realmente no puede continuar.</p>
                        <p v-if="cancelError" class="rounded-xl border border-arka-danger/30 bg-arka-danger/10 px-3 py-2 text-sm text-arka-danger" role="alert">{{ cancelError }}</p>
                        <div>
                            <InputLabel value="Motivo de la cancelación" />
                            <select v-model="cancelReason" class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-arka-base text-arka-text focus:border-arka-primary focus:ring-arka-primary">
                                <option value="" disabled>Elija un motivo</option>
                                <option v-for="reason in cancelReasons" :key="reason" :value="reason">{{ reason }}</option>
                            </select>
                        </div>
                        <div><InputLabel value="Observación (opcional)" /><TextInput v-model="cancelNote" type="text" class="mt-1 block w-full" maxlength="500" placeholder="Agregue un detalle si hace falta" /></div>
                        <div class="flex gap-2">
                            <SecondaryButton type="button" class="flex-1 justify-center" @click="showCancelForm = false">Volver</SecondaryButton>
                            <DangerButton type="button" class="flex-1 justify-center" :disabled="!cancelReason || cancellingRide" @click="submitCancelRide">{{ cancellingRide ? 'Cancelando…' : 'Confirmar' }}</DangerButton>
                        </div>
                    </div>
                </BottomSheet>

                <!-- Recibo (rediseño UX, con mockup de referencia: "Llegada" muestra el
                     total primero, grande, con el detalle debajo) — mismos datos que el
                     desglose de siempre, solo reordenados; para 'scheduled'/'in_progress'
                     se mantiene el formato denso de abajo, más útil como panel de datos
                     mientras el viaje sigue activo. -->
                <div v-if="ride.status === 'completed'" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-4">
                    <div>
                        <p class="text-sm text-arka-text-muted">Total del viaje</p>
                        <!-- Paradas adicionales (pedido explícito del usuario): con
                             paradas, el total real cobrado es settled_price — puede
                             ser menor a price+stops_price si se cerró antes de tiempo
                             (ver RideController::completeStop()). Sin paradas, es
                             simplemente ride.price de siempre. -->
                        <p class="text-3xl font-semibold text-arka-primary-bright">${{ ride.settled_price ?? ride.price }}</p>
                    </div>
                    <!-- Desglose por parada, solo si hubo alguna. -->
                    <div v-if="ride.stops?.length" class="pt-3 border-t border-arka-text-muted/10 space-y-2">
                        <div v-for="stop in ride.stops" :key="stop.id" class="flex items-center justify-between text-sm">
                            <span class="text-arka-text-muted">
                                Parada {{ stop.sequence }}
                                <span v-if="stop.status === 'cancelled'" class="text-arka-danger">(cancelada)</span>
                            </span>
                            <span :class="stop.status === 'cancelled' ? 'text-arka-text-muted line-through' : 'text-arka-text'">
                                ${{ stop.leg_price }}
                            </span>
                        </div>
                        <div v-if="ride.stops.some((stop) => stop.status !== 'cancelled') && !ride.stops.every((stop) => stop.status === 'completed')" class="flex items-center justify-between text-sm">
                            <span class="text-arka-text-muted">Tramo final</span>
                            <span class="text-arka-text-muted line-through">${{ ride.price }}</span>
                        </div>
                    </div>
                    <div class="pt-3 border-t border-arka-text-muted/10 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="text-arka-text-muted">Método de pago</span>
                            <span class="text-arka-text capitalize">{{ ride.payment_method ?? 'efectivo' }}</span>
                        </div>
                        <!-- Cargo por trayecto de recogida (pedido explícito del usuario):
                             solo aparece si el conductor lo cobró — columnas propias,
                             separadas del tramo origen-destino, para trazabilidad. -->
                        <div v-if="ride.pickup_fare_charged" class="flex items-center justify-between">
                            <span class="text-arka-text-muted">Trayecto de recogida</span>
                            <span class="text-arka-text">{{ Number(ride.pickup_distance_km).toFixed(1) }} km · ${{ ride.pickup_fare }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-arka-text-muted">Trayecto origen-destino</span>
                            <span class="text-arka-text">{{ Number(ride.distance_km).toFixed(1) }} km</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-arka-text-muted">Tarifa por km</span>
                            <span class="text-arka-text">${{ ride.rate_per_km_snapshot }}</span>
                        </div>
                        <div v-if="durationLabel" class="flex items-center justify-between">
                            <span class="text-arka-text-muted">Duración</span>
                            <span class="text-arka-text">{{ durationLabel }}</span>
                        </div>
                        <div v-if="waitLabel" class="flex items-center justify-between">
                            <span class="text-arka-text-muted">Tiempo de espera</span>
                            <span class="text-arka-text">{{ waitLabel }}</span>
                        </div>
                    </div>
                </div>

                <!-- Desglose del precio mientras el viaje sigue activo (sección 5: "el
                     cálculo se muestra desglosado, no oculto") — solo para el
                     cliente: para el conductor, estos mismos datos ya salen
                     arriba, en la tarjeta junto al pasajero (pedido explícito
                     del usuario, ver más arriba). -->
                <div v-else-if="!isDriver" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-arka-text-muted">Estado</span>
                        <span class="text-arka-text font-medium">{{ statusLabel[ride.status] }}</span>
                    </div>
                    <div v-if="ride.pickup_fare_charged" class="flex items-center justify-between">
                        <span class="text-arka-text-muted">Trayecto de recogida</span>
                        <span class="text-arka-text">{{ Number(ride.pickup_distance_km).toFixed(1) }} km · ${{ ride.pickup_fare }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-arka-text-muted">Trayecto origen-destino</span>
                        <span class="text-arka-text">{{ Number(ride.distance_km).toFixed(1) }} km</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-arka-text-muted">Tarifa por km</span>
                        <span class="text-arka-text">${{ ride.rate_per_km_snapshot }}</span>
                    </div>
                    <div v-if="durationLabel" class="flex items-center justify-between">
                        <span class="text-arka-text-muted">Duración</span>
                        <span class="text-arka-text">{{ durationLabel }}</span>
                    </div>
                    <div v-if="waitLabel" class="flex items-center justify-between">
                        <span class="text-arka-text-muted">Tiempo de espera</span>
                        <span class="text-arka-text">{{ waitLabel }}</span>
                    </div>
                    <!-- Forma de pago (pedido explícito del usuario): la que el
                         cliente eligió al pedir la carrera. -->
                    <div class="flex items-center justify-between">
                        <span class="text-arka-text-muted">Forma de pago</span>
                        <span class="text-arka-text capitalize">{{ ride.payment_method ?? 'efectivo' }}</span>
                    </div>
                    <div class="flex items-center justify-between text-lg">
                        <span class="text-arka-text-muted">Total</span>
                        <span class="text-arka-primary-bright font-semibold">${{ ride.price }}</span>
                    </div>
                </div>

                <!-- Seguimiento compartible y botón SOS (sección 8) — acá solo
                     para el cliente, ver comentario de la tarjeta de arriba. -->
                <div v-if="ride.status === 'in_progress' && !isDriver" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <SecondaryButton v-if="!trackingUrl" @click="shareTracking">
                            Compartir seguimiento en vivo
                        </SecondaryButton>
                        <DangerButton v-if="!sosSent" @click="triggerSos">🆘 SOS</DangerButton>
                        <span v-else class="text-sm text-arka-primary-bright">Alerta enviada a sus contactos de confianza.</span>
                    </div>

                    <div v-if="trackingUrl" class="flex flex-wrap items-center gap-2">
                        <input
                            type="text"
                            readonly
                            :value="trackingUrl"
                            class="flex-1 min-w-0 rounded-arka border-arka-text-muted/20 bg-arka-base text-arka-text text-sm px-3 py-1.5"
                            @focus="$event.target.select()"
                        />
                        <SecondaryButton @click="copyTrackingUrl">{{ trackingCopied ? 'Copiado' : 'Copiar' }}</SecondaryButton>
                    </div>
                    <p class="text-xs text-arka-text-muted">
                        Cualquiera con este enlace puede ver la ubicación en vivo del viaje, sin necesidad de cuenta.
                    </p>
                </div>

                <!-- Chat temporal (sección 10 del roadmap de mejoras): solo
                     mientras hay una relación de viaje vigente entre estas dos
                     personas — nunca antes de que el conductor acepte, ni
                     después de que la carrera termine o se cancele. No expone
                     teléfonos: todo pasa por acá. Acá solo para el cliente — el
                     conductor tiene esta misma conversación (mismos
                     `chatMessages`/`sendChatMessage`) dentro del botón "⋯"
                     flotante de más abajo. -->
                <div v-if="chatOpen && !isDriver" ref="chatPanelEl" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <h3 class="text-sm font-medium text-arka-text-muted uppercase tracking-wide">
                        Chat con {{ isDriver ? 'el cliente' : 'el conductor' }}
                    </h3>

                    <div ref="chatListEl" class="max-h-64 overflow-y-auto space-y-2 pe-1">
                        <p v-if="!chatMessages.length" class="text-sm text-arka-text-muted">
                            Todavía no hay mensajes — use una respuesta rápida o escriba la suya.
                        </p>
                        <div
                            v-for="message in chatMessages"
                            :key="message.id"
                            class="max-w-[80%] px-3 py-2 rounded-arka text-sm"
                            :class="message.sender_user_id === $page.props.auth.user.id
                                ? 'ms-auto bg-arka-primary text-arka-base'
                                : 'bg-arka-base text-arka-text'"
                        >
                            <p>{{ message.body }}</p>
                            <p
                                class="mt-0.5 text-[10px]"
                                :class="message.sender_user_id === $page.props.auth.user.id ? 'text-arka-base/70' : 'text-arka-text-muted'"
                            >
                                {{ new Date(message.created_at).toLocaleTimeString('es-EC', { hour: '2-digit', minute: '2-digit' }) }}
                            </p>
                        </div>
                    </div>

                    <!-- Mensajes rápidos: un clic manda el texto tal cual. -->
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="reply in quickReplies"
                            :key="reply"
                            type="button"
                            class="px-2 py-1 rounded-arka text-xs bg-arka-base text-arka-text-muted hover:text-arka-text border border-arka-text-muted/20"
                            :disabled="chatSending"
                            @click="sendChatMessage(reply)"
                        >
                            {{ reply }}
                        </button>
                    </div>

                    <form @submit.prevent="sendChatMessage()" class="flex items-center gap-2">
                        <TextInput
                            v-model="chatBody"
                            type="text"
                            class="flex-1"
                            placeholder="Escriba un mensaje…"
                            maxlength="500"
                        />
                        <PrimaryButton :disabled="chatSending || !chatBody.trim()">Enviar</PrimaryButton>
                    </form>
                    <InputError :message="chatError" />
                </div>

                <div ref="actionsCardEl" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka flex flex-wrap gap-3">
                    <!-- Pedido explícito del usuario ("el botón de ya llegué debe
                         ser flotante también, la idea es que no scrollee"): para
                         el conductor, "Iniciar viaje"/"Ya llegué"/"Ya recogí al
                         cliente"/"Marcar como completada" viven en la barra fija
                         de abajo (siempre a la vista, sin scroll) — ver más abajo
                         en este mismo archivo. Acá solo queda lo que le toca al
                         cliente: esperar. -->
                    <p v-if="ride.status === 'in_progress' && !isDriver" class="text-sm text-arka-text-muted">
                        Esperando que el conductor marque la carrera como completada.
                    </p>

                    <!-- Cancelar, mientras no se completó (pedido explícito del
                         usuario: ahora también el conductor puede, no solo el
                         cliente — cada uno con su propia lista de motivos, más
                         una observación libre opcional). -->
                    <template v-if="!isDriver && ['scheduled', 'in_progress'].includes(ride.status)">
                        <DangerButton v-if="!showCancelForm" @click="showCancelForm = true">
                            Cancelar carrera
                        </DangerButton>

                        <div v-else class="w-full p-4 rounded-arka bg-arka-danger/10 border border-arka-danger/30 space-y-3">
                            <p v-if="ride.status === 'in_progress'" class="text-sm text-arka-danger">
                                {{ isDriver ? 'El cliente' : 'Su conductor' }} ya está en camino — se le va a avisar apenas cancele.
                            </p>
                            <div>
                                <InputLabel value="Motivo de la cancelación" />
                                <select
                                    v-model="cancelReason"
                                    class="mt-1 block w-full rounded-arka border-arka-text-muted/30 bg-arka-card text-arka-text focus:border-arka-primary focus:ring-arka-primary"
                                >
                                    <option value="" disabled>Elija un motivo</option>
                                    <option v-for="reason in cancelReasons" :key="reason" :value="reason">{{ reason }}</option>
                                </select>
                            </div>
                            <div>
                                <InputLabel value="Observación (opcional)" />
                                <TextInput
                                    type="text"
                                    class="mt-1 block w-full"
                                    v-model="cancelNote"
                                    maxlength="500"
                                    placeholder="Algún detalle más, si quiere agregarlo"
                                />
                            </div>
                            <div class="flex gap-2">
                                <DangerButton :disabled="!cancelReason || cancellingRide" @click="submitCancelRide">
                                    Confirmar cancelación
                                </DangerButton>
                                <SecondaryButton @click="showCancelForm = false">Volver</SecondaryButton>
                            </div>
                            </div>
                        </template>

                    <p v-if="ride.status === 'cancelled'" class="text-sm text-arka-danger">
                        Esta carrera fue cancelada{{ ride.cancelled_at ? ` el ${new Date(ride.cancelled_at).toLocaleString('es-EC', { dateStyle: 'medium', timeStyle: 'short' })}` : '' }}{{ ride.cancelled_by ? ` por ${ride.cancelled_by === 'driver' ? 'el conductor' : 'el cliente'}` : '' }}.
                        <span v-if="ride.cancellation_reason" class="block text-arka-text-muted">Motivo: {{ ride.cancellation_reason }}</span>
                        <span v-if="ride.cancellation_note" class="block text-arka-text-muted">"{{ ride.cancellation_note }}"</span>
                    </p>

                    <!-- Bug reportado por el usuario (captura: recuadro vacío en el
                         detalle): esta tarjeta de "acciones" no tenía ningún caso
                         para una carrera ya completada — ninguna de las acciones de
                         arriba aplica, así que quedaba con el fondo/sombra pero sin
                         nada adentro. Mismo criterio que el mensaje de cancelada. -->
                    <p v-if="ride.status === 'completed'" class="text-sm text-arka-primary-bright">
                        ✅ Carrera completada{{ ride.completed_at ? ` el ${new Date(ride.completed_at).toLocaleString('es-EC', { dateStyle: 'medium', timeStyle: 'short' })}` : '' }}.
                    </p>
                    <!-- Pedido explícito del usuario: si el conductor completó
                         lejos del destino, el motivo que eligió le llega acá
                         al cliente (y también al propio conductor, como
                         confirmación de lo que acaba de elegir). -->
                    <p v-if="ride.completion_reason" class="text-sm text-arka-warning">
                        📍 El conductor completó la carrera antes de llegar al destino.
                        <span class="block text-arka-text-muted">Motivo: {{ ride.completion_reason }}</span>
                        <span v-if="ride.completion_note" class="block text-arka-text-muted">"{{ ride.completion_note }}"</span>
                    </p>
                </div>

                <!-- Calificar (sección 3.6): solo cuando la carrera terminó. Pedido
                     explícito del usuario: es obligatoria, pero cliente y
                     conductor califican de forma independiente — ninguno espera
                     al otro. -->
                <div v-if="ride.status === 'completed'" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-4">
                    <h3 class="text-lg font-medium text-arka-text">
                        Calificar {{ isDriver ? 'al cliente' : 'al conductor' }}
                    </h3>

                    <form v-if="!myReview" @submit.prevent="submitReview" class="space-y-3">
                        <div class="py-1 scale-125 origin-left">
                            <RatingStars v-model="reviewForm.rating" />
                        </div>
                        <div>
                            <InputLabel for="comment" value="Comentario (opcional)" />
                            <TextInput
                                id="comment"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="reviewForm.comment"
                                placeholder="Puntualidad, trato, estado del vehículo..."
                            />
                        </div>

                        <!-- Motivo obligatorio si se baja de las 5 estrellas por
                             defecto (pedido explícito del usuario). -->
                        <div v-if="needsReason">
                            <InputLabel for="rating_reason_id" value="¿Por qué? (obligatorio)" />
                            <select
                                id="rating_reason_id"
                                v-model="reviewForm.rating_reason_id"
                                class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text"
                                required
                            >
                                <option :value="null" disabled>Elija un motivo</option>
                                <option v-for="reason in ratingReasons" :key="reason.id" :value="reason.id">{{ reason.text }}</option>
                            </select>
                            <InputError class="mt-1" :message="reviewForm.errors.rating_reason_id" />
                        </div>

                        <p v-if="reviewForm.errors.ride" class="text-sm text-arka-danger">
                            {{ reviewForm.errors.ride }}
                        </p>
                        <PrimaryButton :disabled="reviewForm.processing || reviewForm.rating < 1 || (needsReason && !reviewForm.rating_reason_id)">
                            Enviar calificación
                        </PrimaryButton>
                    </form>

                    <div v-else class="text-sm text-arka-text-muted">
                        <p class="mb-1">Ya calificó esta carrera:</p>
                        <RatingStars :rating="myReview.rating" readonly />
                        <p v-if="myReview.comment" class="mt-1 italic">"{{ myReview.comment }}"</p>
                    </div>

                    <div v-if="theirReview" class="pt-3 border-t border-arka-text-muted/10">
                        <div class="flex items-center gap-2 mb-1">
                            <UserAvatar :user="counterpart" size-class="h-6 w-6 text-[10px] shrink-0" />
                            <p class="text-sm text-arka-text-muted">
                                {{ isDriver ? 'El cliente' : 'El conductor' }} le calificó:
                            </p>
                        </div>
                        <RatingStars :rating="theirReview.rating" readonly />
                        <p v-if="theirReview.comment" class="mt-1 text-sm italic text-arka-text-muted">
                            "{{ theirReview.comment }}"
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
