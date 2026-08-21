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
    const targetId = props.ride.arrived_at ? 'destination' : 'origin';
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

// Trazado real (OSRM, mismo mecanismo que Ride/Request.vue) del recorrido
// del conductor hasta el punto de encuentro (pedido explícito del usuario,
// con captura de referencia estilo Uber/DiDi: "que le muestre también ahí en
// el mapa cuando tiene por llegar y su recorrido") — antes el mapa solo
// mostraba el puntito del conductor sin ninguna línea, sin forma de ver POR
// DÓNDE viene. Mismo gate que pickupEta (solo cliente, solo mientras viene en
// camino) para no pedirle una ruta a OSRM de más una vez que ya llegó.
const pickupRouteCoords = ref([]);
watch(
    [driverLat, driverLng],
    async () => {
        if (props.ride.status !== 'in_progress' || props.ride.arrived_at || driverLat.value == null) {
            pickupRouteCoords.value = [];
            return;
        }

        const route = await fetchOsrmRoute(driverLat.value, driverLng.value, Number(props.ride.origin_lat), Number(props.ride.origin_lng));
        pickupRouteCoords.value = route.coords;
    },
    { immediate: true }
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
    props.ride.status === 'in_progress' && !props.ride.arrived_at
        ? pickupRouteCoords.value
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

        if (remainingKm > 1.5) {
            completionFeedback.value = `Aún está a ${readableDistance(remainingKm)} del destino. Acérquese al punto marcado para completar la carrera.`;
            completing.value = false;
            return;
        }
    }

    router.post(route('rides.complete', props.ride.id), coords, {
        preserveScroll: true,
        onError: (errors) => {
            completionFeedback.value = errors.ride || 'No fue posible completar la carrera. Revise su ubicación e inténtelo nuevamente.';
        },
        onFinish: () => (completing.value = false),
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

// Hitos "Ir por el pasajero" / "Iniciar destino" (pedido explícito del
// usuario: "coloca los botones flotantes de ir por el pasajero y luego...
// que aparezca iniciar destino. Y le mande al mapa de google map trazado el
// destino"): antes eran CUATRO acciones separadas (Ya llegué / Ya recogí al
// cliente / dos links de Google Maps sueltos en el panel "⋯") — ahora cada
// tramo es un solo toque que navega Y avanza el estado a la vez, para que el
// conductor no tenga que ir a buscar el link de Maps por separado.
const markingArrived = ref(false);
async function goToPassenger() {
    window.open(googleNavigateUrl(props.ride.origin_lat, props.ride.origin_lng), '_blank', 'noopener');

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
                </div>
                <span
                    v-if="pickupEta"
                    class="shrink-0 px-3 py-1.5 rounded-arka bg-arka-primary/15 text-center"
                >
                    <span class="block text-[10px] text-arka-primary-bright/80 leading-none">Llegando en</span>
                    <span class="block text-sm font-semibold text-arka-primary-bright leading-tight">{{ pickupEta.minutes }} min</span>
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

            <!-- Vehículo + Mensaje/Llamar + Cancelar, flotando abajo (mismo
                 orden que el mockup). -->
            <div class="fixed inset-x-3 bottom-3 z-10 space-y-2">
                <div
                    v-if="ride.driver.driver_profile?.vehicle_make"
                    class="flex items-center gap-2.5 px-4 py-3 bg-arka-card/95 backdrop-blur-sm shadow-lg rounded-arka border border-arka-text-muted/10"
                >
                    <svg class="h-5 w-5 text-arka-text-muted shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3.5" y="6" width="17" height="12" rx="2" stroke-linecap="round" stroke-linejoin="round" />
                        <circle cx="9" cy="12" r="1.75" stroke-linecap="round" stroke-linejoin="round" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14 10.5h3M14 13.5h3" />
                    </svg>
                    <span class="text-sm text-arka-text truncate">
                        {{ ride.driver.driver_profile.vehicle_make }} {{ ride.driver.driver_profile.vehicle_model }} - {{ ride.driver.driver_profile.vehicle_color }}
                    </span>
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

                <DangerButton class="w-full justify-center" @click="showCancelForm = true">Cancelar viaje</DangerButton>
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
                     mx-3" que ya usa Ride/Request.vue para origen/destino. -->
                <div class="-mx-4 sm:mx-0">
                    <FleetMap
                        :markers="mapMarkers"
                        :route="visibleRouteCoords"
                        :dark="false"
                        :fit-padding-top="55"
                        :fit-padding-bottom="55"
                        :zoom="15"
                        height="320px"
                    />
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
                        <template v-if="ride.arrived_at">📍 Lo está esperando en el punto de encuentro.</template>
                        <template v-else-if="pickupEta">🚗 Viene en camino — está a {{ pickupEta.km.toFixed(1) }} km.</template>
                        <template v-else>🚗 Viene en camino — buscando su ubicación en vivo…</template>
                    </p>
                    <p v-if="!isDriver && ride.status === 'in_progress' && ride.picked_up_at" class="text-sm text-arka-text-muted">
                        🚙 Viaje en curso hacia el destino.
                    </p>

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
                        v-if="isDriver && !showCancelForm && ['scheduled', 'in_progress'].includes(ride.status)"
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

                            <!-- Un solo toque por tramo: navega en Google Maps Y
                                 avanza el estado a la vez (pedido explícito del
                                 usuario) — ninguno bloquea "Marcar como completada"
                                 si el conductor se los saltea. -->
                            <SecondaryButton
                                v-if="!ride.arrived_at"
                                class="flex-1 justify-center"
                                :disabled="markingArrived"
                                @click="goToPassenger"
                            >
                                📍 {{ markingArrived ? 'Ubicando…' : 'Ir por el pasajero' }}
                            </SecondaryButton>
                            <SecondaryButton
                                v-else-if="!ride.picked_up_at"
                                class="flex-1 justify-center"
                                :disabled="markingPickedUp"
                                @click="startToDestination"
                            >
                                🏁 {{ markingPickedUp ? 'Ubicando…' : 'Iniciar destino' }}
                            </SecondaryButton>

                            <!-- Pedido explícito del usuario: la carrera la finaliza
                                 ÚNICAMENTE el conductor. -->
                            <button
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
                        <p class="text-3xl font-semibold text-arka-primary-bright">${{ ride.price }}</p>
                    </div>
                    <div class="pt-3 border-t border-arka-text-muted/10 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="text-arka-text-muted">Método de pago</span>
                            <span class="text-arka-text capitalize">{{ ride.payment_method ?? 'efectivo' }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-arka-text-muted">Distancia</span>
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
                    <div class="flex items-center justify-between">
                        <span class="text-arka-text-muted">Distancia</span>
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
