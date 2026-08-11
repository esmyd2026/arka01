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
import { playUpdateChime } from '@/Utils/liveAlert';

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

let fleetChannel = null;
let rideChannel = null;

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

// Respuestas rápidas (pedido explícito del usuario): distintas según el rol,
// un clic manda el mensaje tal cual — no hace falta escribirlo, pero el
// campo de texto libre sigue disponible para lo que no calce en ninguna.
const QUICK_REPLIES_DRIVER = ['Voy en camino.', 'Estoy cerca.', 'Estoy en el punto de recogida.', 'No logro ubicarte.', 'Hay tráfico, llegaré en unos minutos.'];
const QUICK_REPLIES_CLIENT = ['¿Vienes en camino?', '¿Ya llegaste?', 'Estoy saliendo.', 'Estoy en el punto indicado.', 'No logro ubicarte.'];
const quickReplies = computed(() => (props.isDriver ? QUICK_REPLIES_DRIVER : QUICK_REPLIES_CLIENT));

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
    fleetChannel = window.Echo.private(`fleet.${props.ride.fleet_id}`);
    fleetChannel.listen('.driver.location.updated', (e) => {
        if (e.driver_user_id !== props.ride.driver_user_id) return;
        driverLat.value = e.lat;
        driverLng.value = e.lng;
    });

    // El conductor arrancó esta carrera PROGRAMADA desde otra pestaña/sesión
    // (consideración agregada al alcance) — refresca el estado si esta
    // pantalla ya estaba abierta desde antes.
    fleetChannel.listen('.ride.started', (e) => {
        if (e.ride_id !== props.ride.id) return;
        router.reload({ only: ['ride'] });
    });

    // El cliente canceló la carrera (pedido explícito del usuario) — si el
    // conductor ya iba en camino y tiene esta pantalla abierta, se entera al
    // toque en vez de seguir manejando hacia algo que ya no existe.
    fleetChannel.listen('.ride.cancelled', (e) => {
        if (e.ride_id !== props.ride.id) return;
        router.reload({ only: ['ride'] });
    });

    // Bug reportado por el usuario: el conductor finalizaba la carrera y el
    // cliente se quedaba viendo la pantalla como si siguiera en curso — no
    // faltaba el evento (RideCompleted ya se transmite), faltaba este
    // listener, que sí existe para "iniciada" y "cancelada".
    fleetChannel.listen('.ride.completed', (e) => {
        if (e.ride_id !== props.ride.id) return;
        router.reload({ only: ['ride'] });
    });

    // El conductor marcó "ya llegué" o "ya recogí al cliente" (pedido
    // explícito del usuario) — refresca para que el que tiene la pantalla
    // abierta vea el cambio de banner sin recargar a mano.
    fleetChannel.listen('.ride.arrived', (e) => {
        if (e.ride_id !== props.ride.id) return;
        router.reload({ only: ['ride'] });
    });

    fleetChannel.listen('.ride.picked_up', (e) => {
        if (e.ride_id !== props.ride.id) return;
        router.reload({ only: ['ride'] });
    });

    // Chat (sección 10 del roadmap de mejoras): canal PROPIO de esta carrera
    // puntual, no el de flota — ahí solo escuchan las dos partes de este
    // viaje, nadie más de la flota (ver routes/channels.php: `ride.{id}`).
    // Solo tiene sentido suscribirse mientras el chat sigue abierto — ver
    // Ride::chatIsOpen().
    if (chatOpen.value) {
        rideChannel = window.Echo.private(`ride.${props.ride.id}`);
        rideChannel.listen('.ride.message.sent', (e) => {
            chatMessages.value.push(e);
            playUpdateChime();
            scrollChatToBottom();
        });
    }
});

onBeforeUnmount(() => {
    window.Echo.leave(`fleet.${props.ride.fleet_id}`);
    if (chatOpen.value) window.Echo.leave(`ride.${props.ride.id}`);
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

    if (driverLat.value != null) {
        markers.push({ id: 'driver', lat: Number(driverLat.value), lng: Number(driverLng.value), label: 'Conductor' });
    }

    return markers;
});

// Quién es "la otra parte" de esta carrera, para mostrar su avatar junto a
// la reseña que le hizo a la cuenta logueada.
const counterpart = computed(() => (props.isDriver ? props.ride.client : props.ride.driver));

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

function complete() {
    router.post(route('rides.complete', props.ride.id), {}, { preserveScroll: true });
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

// Hitos de "ya llegué" / "ya recogí al cliente" (pedido explícito del
// usuario): el conductor los marca de a uno, en orden — cada botón
// desaparece apenas se marca, sin bloquear "Marcar como completada" si se
// los saltea.
function markArrived() {
    router.post(route('rides.arrived', props.ride.id), {}, { preserveScroll: true });
}

function markPickedUp() {
    router.post(route('rides.picked-up', props.ride.id), {}, { preserveScroll: true });
}

// Cancelar una carrera ya aceptada (pedido explícito del usuario): solo el
// cliente puede, mientras el conductor no la completó — importante avisar
// bien si ya está "en camino", por eso el mensaje distingue ambos casos.
async function cancelRide() {
    const message = props.ride.status === 'in_progress'
        ? '¿Seguro que quiere cancelar? Su conductor ya está en camino y se le va a avisar.'
        : '¿Seguro que quiere cancelar esta carrera programada?';

    if (!(await confirmDialog(message, { danger: true, confirmLabel: 'Cancelar carrera' }))) return;

    router.post(route('rides.cancel', props.ride.id), {}, { preserveScroll: true });
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

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-arka-text leading-tight">
                Carrera con {{ isDriver ? ride.client.name : ride.driver.name }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- "Ya van por vos" + ETA (pedido explícito del usuario) — solo para
                     el cliente, mientras la carrera sigue en curso. Tres hitos
                     dentro del mismo 'in_progress': en camino, esperando en el
                     punto de encuentro (pedido explícito del usuario: que se
                     entere apenas el conductor marque "ya llegué"), y viaje en
                     curso una vez que lo recogió. -->
                <div v-if="!isDriver && ride.status === 'in_progress' && !ride.picked_up_at" class="p-4 bg-arka-primary/10 rounded-arka">
                    <template v-if="ride.arrived_at">
                        <p class="font-semibold text-arka-primary-bright">📍 Su conductor lo está esperando</p>
                        <p class="text-sm text-arka-text-muted">Llegó al punto de encuentro, acérquese cuando pueda.</p>
                    </template>
                    <template v-else>
                        <p class="font-semibold text-arka-primary-bright">🚗 Su conductor ya va en camino</p>
                        <p v-if="pickupEta" class="text-sm text-arka-text-muted">
                            Está a {{ pickupEta.km.toFixed(1) }} km, llega en unos {{ pickupEta.minutes }} min.
                        </p>
                        <p v-else class="text-sm text-arka-text-muted">Buscando su ubicación en vivo…</p>
                    </template>
                </div>

                <div v-if="!isDriver && ride.status === 'in_progress' && ride.picked_up_at" class="p-4 bg-arka-primary/10 rounded-arka">
                    <p class="font-semibold text-arka-primary-bright">🚙 Viaje en curso hacia el destino</p>
                </div>

                <!-- Carrera PROGRAMADA todavía sin arrancar (consideración agregada al
                     alcance: "ahora mismo" vs "programación") -->
                <div v-if="ride.status === 'scheduled'" class="p-4 bg-arka-warning/15 border border-arka-warning/40 rounded-arka">
                    <p class="font-semibold text-arka-warning">
                        📅 Programada para {{ new Date(ride.ride_request?.scheduled_at).toLocaleString('es-EC', { dateStyle: 'medium', timeStyle: 'short' }) }}
                    </p>
                    <p v-if="ride.round_trip" class="text-sm text-arka-text-muted">Incluye ida y vuelta.</p>
                    <p v-if="isDriver" class="mt-2 text-sm text-arka-text-muted">
                        Todavía no cuenta como "ocupado" para su flota — arránquela cuando salga a buscar al cliente.
                    </p>
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
                </div>

                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <FleetMap :markers="mapMarkers" height="360px" />

                    <!-- Navegación real (sección 8 y 9.3): abre la app/sitio de Google
                         Maps de verdad para ir manejando — no hace falta pagar ninguna
                         API para esto, es solo un link. Solo le sirve al conductor
                         mientras el viaje sigue en curso. -->
                    <div v-if="isDriver && ride.status === 'in_progress'" class="flex flex-wrap gap-2">
                        <a
                            :href="googleNavigateUrl(ride.origin_lat, ride.origin_lng)"
                            target="_blank"
                            rel="noopener"
                            class="px-4 py-2 rounded-arka bg-arka-base text-arka-text text-sm font-medium hover:bg-arka-base/70 transition"
                        >
                            📍 Ir a buscar al cliente
                        </a>
                        <a
                            :href="googleNavigateUrl(ride.destination_lat, ride.destination_lng)"
                            target="_blank"
                            rel="noopener"
                            class="px-4 py-2 rounded-arka bg-arka-base text-arka-text text-sm font-medium hover:bg-arka-base/70 transition"
                        >
                            🏁 Llevar al destino
                        </a>
                    </div>
                </div>

                <!-- Desglose del precio, siempre visible (sección 5: "el cálculo se
                     muestra desglosado, no oculto") -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-2">
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

                <!-- Seguimiento compartible y botón SOS (sección 8) -->
                <div v-if="ride.status === 'in_progress'" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
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
                     teléfonos: todo pasa por acá. -->
                <div v-if="chatOpen" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
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

                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka flex flex-wrap gap-3">
                    <PrimaryButton v-if="ride.status === 'scheduled' && isDriver" @click="startRide">
                        Iniciar viaje
                    </PrimaryButton>

                    <!-- Hitos "ya llegué" / "ya recogí al cliente" (pedido
                         explícito del usuario): uno a la vez, en orden — ninguno
                         de los dos bloquea "Marcar como completada" si el
                         conductor se los saltea. -->
                    <SecondaryButton v-if="ride.status === 'in_progress' && isDriver && !ride.arrived_at" @click="markArrived">
                        📍 Ya llegué
                    </SecondaryButton>
                    <SecondaryButton v-if="ride.status === 'in_progress' && isDriver && ride.arrived_at && !ride.picked_up_at" @click="markPickedUp">
                        🧍 Ya recogí al cliente
                    </SecondaryButton>

                    <!-- Pedido explícito del usuario: la carrera la finaliza
                         ÚNICAMENTE el conductor, ya no cualquiera de las dos partes. -->
                    <PrimaryButton v-if="ride.status === 'in_progress' && isDriver" @click="complete">
                        Marcar como completada
                    </PrimaryButton>
                    <p v-if="ride.status === 'in_progress' && !isDriver" class="text-sm text-arka-text-muted">
                        Esperando que el conductor marque la carrera como completada.
                    </p>

                    <!-- Solo el cliente puede cancelar, y solo antes de que se complete
                         (pedido explícito del usuario). -->
                    <DangerButton v-if="!isDriver && ['scheduled', 'in_progress'].includes(ride.status)" @click="cancelRide">
                        Cancelar carrera
                    </DangerButton>

                    <p v-if="ride.status === 'cancelled'" class="text-sm text-arka-danger">
                        Esta carrera fue cancelada{{ ride.cancelled_at ? ` el ${new Date(ride.cancelled_at).toLocaleString('es-EC', { dateStyle: 'medium', timeStyle: 'short' })}` : '' }}.
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
                        <RatingStars v-model="reviewForm.rating" />
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
