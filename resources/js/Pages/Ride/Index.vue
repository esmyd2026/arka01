<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import TrustScoreBadge from '@/Components/TrustScoreBadge.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { playAttentionAlert, playCabinChime, playIncomingRideAlert, playUpdateChime } from '@/Utils/liveAlert';
import { pushIncomingRideRequest } from '@/Utils/incomingRideRequest';
import { waitingMessage as sharedWaitingMessage, secondsLeft as sharedSecondsLeft } from '@/Utils/rideWaitingMessage';

const props = defineProps({
    pendingRequestsAsClient: { type: Array, required: true },
    incomingRequestsAsDriver: { type: Array, required: true },
    pendingCooperativeInvitations: { type: Array, required: true },
    activeRides: { type: Array, required: true },
    scheduledRides: { type: Array, required: true },
    // Rediseño (pedido explícito del usuario): ahora viene paginado desde
    // RideController::index() — { data: [...], prev_page_url, next_page_url,
    // ... }, ya no un array plano con tope fijo de 20.
    rideHistory: { type: Object, required: true },
    // Alarma de "sin calificar", calculada aparte para que sea correcta sin
    // importar en qué página del historial esté el usuario.
    unratedRideIds: { type: Array, required: true },
    driverFleetIds: { type: Array, required: true },
});

// Mismo criterio de etiquetas/colores que Admin/Rides.vue — acá solo
// aplican 'completed'/'cancelled' (el historial excluye en curso y
// programadas), pero se deja igual de completo por si algún día cambia.
const HISTORY_STATUS_LABEL = {
    completed: 'Completada',
    cancelled: 'Cancelada',
};
const HISTORY_STATUS_BADGE_CLASS = {
    completed: 'bg-arka-lime/15 text-arka-lime',
    cancelled: 'bg-arka-danger/15 text-arka-danger',
};

function formatHistoryDate(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('es-EC', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

// Fecha/hora programada, legible (ej. "3 ago, 18:00") — mismo formato en
// todas las tarjetas que muestran `scheduled_at` (consideración agregada al
// alcance: "ahora mismo" vs "programación").
function formatScheduledAt(iso) {
    return new Date(iso).toLocaleString('es-EC', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

// Bug reportado por el usuario: una programada cuya hora ya pasó se quedaba
// mostrando "Iniciar viaje" para siempre, sin ningún aviso de que está
// vencida — esto es el lado visual, inmediato (no espera al comando que
// corre cada 5 min, ver App\Console\Commands\SendOverdueScheduledRideAlerts).
function isScheduledOverdue(iso) {
    return iso ? new Date(iso).getTime() < Date.now() : false;
}

function startRide(id) {
    router.post(route('rides.start', id), {}, { preserveScroll: true });
}

// Invitación de cooperativa (pedido explícito del usuario: "le deberia
// llegar en la pantalla de solicitudes al conductor tambien... como
// cuando un cliente le manda la solicitud") — mismo endpoint que ya usaba
// Cooperative/DriverInvitations.vue, solo que ahora también se puede
// responder desde acá sin tener que encontrar esa pantalla aparte.
function respondToCooperativeInvitation(id, decision) {
    router.post(route('cooperative-driver-invitations.respond', id), { decision }, { preserveScroll: true });
}

// Pedido explícito del usuario: "el cliente no tiene más que solo cambian
// el contenido en la pantalla, pero no algo más visual" — esta lista solo
// mostraba el nombre de la otra parte, sin ningún indicio de en qué punto
// va la carrera (¿ya llegó el conductor? ¿ya recogió al cliente?). Mismo
// dato que ya usa Ride/Show.vue (arrived_at/picked_up_at), acá como
// etiqueta corta.
function activeRideStatusLabel(ride) {
    if (ride.picked_up_at) return '🚗 En camino al destino';
    if (ride.arrived_at) return '📍 El conductor está esperando';
    return '🔎 El conductor va en camino';
}

const userId = usePage().props.auth.user.id;

// Pedido explícito del usuario: "Pedir una carrera" es una acción del lado
// cliente — un conductor no debería ver ese botón acá (esta pantalla es
// compartida entre los dos roles, muestra tanto lo que pedí como cliente
// como lo que me llega como conductor).
const isClient = usePage().props.auth.isClient;

// Copias locales para poder sumar/sacar en vivo sin esperar una recarga de
// página completa (sección 3.5: notificación instantánea de solicitudes).
const incoming = ref([...props.incomingRequestsAsDriver]);
const myPending = ref([...props.pendingRequestsAsClient]);
const activeImmediateRequest = computed(() => myPending.value.find((request) => !request.is_scheduled && ['pending', 'negotiating', 'waiting'].includes(request.status)) ?? null);
const otherPendingRequests = computed(() => myPending.value.filter((request) => request.id !== activeImmediateRequest.value?.id));
const reminderRideIds = ref(new Set(props.scheduledRides.filter((ride) => ride.driver_reminder_sent_at).map((ride) => ride.id)));
const upcomingReminderRide = computed(() => props.scheduledRides.find((ride) => ride.driver_user_id === userId && reminderRideIds.value.has(ride.id)) ?? null);

// Cuando el conductor acepta mi solicitud, `.ride-request.accepted` (más
// abajo) pide un `router.reload()` de `pendingRequestsAsClient` — pero sin
// esto, la copia local `myPending` de arriba nunca se enteraba de esa
// recarga y la tarjeta "Esperando respuesta" se quedaba pegada para siempre
// (consideración agregada al alcance: se reportó justo este síntoma).
watch(
    () => props.pendingRequestsAsClient,
    (value) => (myPending.value = [...value])
);
watch(
    () => props.incomingRequestsAsDriver,
    (value) => (incoming.value = [...value])
);

// Monto que cada conductor va escribiendo para contraofertar una solicitud
// puntual (sección 5) — un input por solicitud, guardado por id.
const counterAmounts = ref({});
const processingRequestId = ref(null);

// Cargo por trayecto de recogida (pedido explícito del usuario): el
// conductor decide, solicitud por solicitud, si cobra ese cargo al aceptar —
// arranca sin marcar, nunca se cobra por defecto.
const chargePickup = ref({});

const ownedChannelListeners = [];
let requestSyncTimer = null;
let requestSyncRunning = false;

// El canal personal también lo usa AuthenticatedLayout para la alarma global.
// Guardamos cada callback propio para desmontarlo sin ejecutar Echo.leave(),
// que apagaría el canal compartido y dejaría de avisar hasta recargar.
function listenOwned(channel, event, callback) {
    channel.listen(event, callback);
    ownedChannelListeners.push({ channel, event, callback });
}

// Reverb da la respuesta inmediata, pero una conexión móvil puede perder un
// evento durante un cambio de red, bloqueo de pantalla o reinicio del socket.
// Esta reconciliación consulta solo solicitudes activas: no recarga historial,
// mapas ni toda la página. Si descubre una solicitud nueva que el WebSocket no
// entregó, reproduce la misma alarma y abre el mismo modal global.
async function syncActiveRequests() {
    if (document.hidden || requestSyncRunning || processingRequestId.value) return;

    requestSyncRunning = true;

    try {
        const { data } = await window.axios.get(route('rides.sync-requests'));
        const nextIncoming = data.incoming_requests_as_driver ?? [];
        const nextPending = data.pending_requests_as_client ?? [];
        const currentIncomingIds = new Set(incoming.value.map((request) => request.id));
        const currentPendingStatuses = new Map(myPending.value.map((request) => [request.id, request.status]));

        const missedIncoming = nextIncoming.filter(
            (request) => request.status === 'pending' && !currentIncomingIds.has(request.id)
        );
        const missedCounterOffer = nextPending.find(
            (request) => request.status === 'negotiating' && currentPendingStatuses.get(request.id) !== 'negotiating'
        );

        incoming.value = nextIncoming;
        myPending.value = nextPending;

        if (missedIncoming.length > 0 && pushIncomingRideRequest(missedIncoming[0])) {
            playIncomingRideAlert();
        }

        if (missedCounterOffer) playUpdateChime();
    } catch (error) {
        // El siguiente ciclo vuelve a intentarlo. No se muestra un error cada
        // 10 segundos porque una pérdida corta de red en el celular es normal.
        console.warn('No se pudieron sincronizar las solicitudes activas.', error);
    } finally {
        requestSyncRunning = false;
    }
}

function syncWhenVisible() {
    if (!document.hidden) syncActiveRequests();
}

onMounted(() => {
    // Mi propio canal: acá llegan las solicitudes dirigidas a mí como conductor,
    // el aviso de que alguien aceptó una solicitud que yo mandé como cliente, y
    // la contraoferta de un conductor sobre una solicitud mía.
    const personal = window.Echo.private(`App.Models.User.${userId}`);
    // Sonido + vibración (pedido explícito del usuario): más confiable que la
    // notificación push del sistema operativo mientras la pestaña ya está
    // abierta y enfocada acá mismo, en "Carreras".
    // Nota: acá SOLO se usa ".ride-request.created" para actualizar MI PROPIA
    // solicitud (como cliente) cuando se le asigna un candidato — nunca como
    // aviso de "carrera entrante" para mí como conductor, eso lo sigue
    // manejando el modal global (IncomingRideRequestModal, montado en
    // AuthenticatedLayout.vue) con un aviso más fuerte. Si el conductor la
    // acepta/descarta desde ahí, el redirect de esa acción ya trae esta
    // pantalla actualizada sola. El canal de FLOTA de abajo sigue escuchando
    // este evento tal cual — ese es el aviso "a toda la flota" de una
    // solicitud PROGRAMADA, que no pasa por el modal (sin apuro de 30 seg.).
    //
    // Lista de espera (pedido explícito del usuario: "puedo dejar la carrera
    // pendiente hasta que uno se desocupe"): mi tarjeta "Esperando respuesta"
    // pasaba de "esperando, todos ocupados" a tener un candidato real sin que
    // yo tuviera que recargar la página — actualiza la tarjeta en el lugar,
    // sin el chime fuerte (ese es para conductores recibiendo algo nuevo).
    listenOwned(personal, '.ride-request.created', (e) => {
        const req = myPending.value.find((r) => r.id === e.id);
        if (req) {
            const assignedNow = e.cooperative_id && e.driver_user_id && req.driver_user_id !== e.driver_user_id;
            req.status = e.status;
            req.driver_user_id = e.driver_user_id;
            req.driver = e.driver_name ? { name: e.driver_name } : null;
            req.current_offered_price = e.current_offered_price;
            req.current_offer_expires_at = e.current_offer_expires_at;
            if (assignedNow) playAttentionAlert();
        }
    });
    // Pedido explícito del usuario: apenas el conductor acepta, el cliente
    // pasa directo al detalle de esa carrera en vez de quedarse mirando la
    // lista — ahí es donde puede seguir la ubicación en vivo y chatear.
    listenOwned(personal, '.ride-request.accepted', (e) => {
        router.visit(route('rides.show', e.ride_id));
    });
    listenOwned(personal, '.ride-request.countered', (e) => {
        playUpdateChime();
        const req = myPending.value.find((r) => r.id === e.ride_request_id);
        if (req) {
            req.status = 'negotiating';
            req.current_offered_price = e.offered_amount;
            req.negotiating_driver_name = e.driver_name;
        }
    });
    listenOwned(personal, '.ride-request.cancelled', (e) => {
        incoming.value = incoming.value.filter((r) => r.id !== e.ride_request_id);
    });
    // Despacho secuencial estilo Uber (pedido explícito del usuario): nadie
    // de la bolsa respondió a tiempo — la tarjeta "Esperando respuesta" tiene
    // que decirlo, no quedarse pegada en silencio.
    listenOwned(personal, '.ride-request.expired', (e) => {
        playUpdateChime();
        const req = myPending.value.find((r) => r.id === e.ride_request_id);
        if (req) req.status = 'expired';
    });
    // Bug reportado por el usuario: el conductor puntual al que le pedí la
    // carrera la rechazó, y no me enteraba de nada — la tarjeta se quedaba
    // pegada en "buscando" para siempre, en silencio.
    listenOwned(personal, '.ride-request.declined', (e) => {
        playUpdateChime();
        const req = myPending.value.find((r) => r.id === e.ride_request_id);
        if (req) {
            req.status = 'declined';
            req.declined_driver_name = e.driver_name;
        }
    });
    // El cliente subió su propia oferta (sin esperar a que yo contraoferte) —
    // se actualiza el monto ahí mismo en la lista, sin recargar.
    listenOwned(personal, '.ride-request.price-raised', (e) => {
        const req = incoming.value.find((r) => r.id === e.ride_request_id);
        if (req) req.current_offered_price = e.offered_amount;
    });
    // Se completó una carrera propia (la haya completado yo u la otra
    // parte): "En curso" y "Historial" tienen que moverse solos, no
    // quedarse pegados hasta que alguien recargue la página a mano.
    // Pedido explícito del usuario: este y los otros 3 de abajo
    // (arrancó/llegó/recogió/completó) son avances normales de la carrera,
    // no algo que exija una respuesta — usan la campanita de cabina en vez
    // del tono de atención (ese queda para lo que sí necesita reacción,
    // como una cancelación o una carrera nueva).
    listenOwned(personal, '.ride.completed', () => {
        if (!isClient) playCabinChime();
        router.reload({ only: ['activeRides', 'rideHistory'] });
    });
    // El cliente canceló una carrera ya aceptada (pedido explícito del
    // usuario) — mismo criterio que ".ride.completed": que "En curso"/
    // "Programados" se actualicen solos para quien no la canceló. Con el
    // tono de atención + vibración: a diferencia de los demás, esto corta
    // el viaje, no es un avance normal.
    listenOwned(personal, '.ride.cancelled', () => {
        if (!isClient) playAttentionAlert();
        router.reload({ only: ['activeRides', 'scheduledRides', 'rideHistory'] });
    });
    // El conductor arrancó una carrera que venía PROGRAMADA (consideración
    // agregada al alcance) — pasa de "Programados" a "En curso" solo, sin
    // esperar a que alguien recargue la página.
    listenOwned(personal, '.ride.started', () => {
        if (!isClient) playCabinChime();
        router.reload({ only: ['scheduledRides', 'activeRides'] });
    });
    // Pedido explícito del usuario: "el cliente no tiene más que solo
    // cambian el contenido en la pantalla, pero no algo más visual ni
    // tono" — estos dos no tenían NINGÚN aviso acá (solo existían en
    // Ride/Show.vue), así que quien se quedaba mirando esta lista en vez del
    // detalle de la carrera no se enteraba de nada cuando el conductor
    // marcaba "ya llegué" o "ya recogí al cliente". La etiqueta de estado de
    // "En curso" (activeRideStatusLabel) ya refleja esto solo con recargar
    // activeRides — acá solo faltaba el aviso.
    listenOwned(personal, '.ride.arrived', () => {
        if (!isClient) playCabinChime();
        router.reload({ only: ['activeRides'] });
    });
    listenOwned(personal, '.ride.picked_up', () => {
        if (!isClient) playCabinChime();
        router.reload({ only: ['activeRides'] });
    });
    // Cambio de horario propuesto/respondido en una carrera programada
    // (pedido explícito del usuario: editar si se equivocaron) — "Programados"
    // tiene que reflejarlo sin que nadie recargue a mano.
    listenOwned(personal, '.ride.reschedule-proposed', () => {
        playAttentionAlert();
        router.reload({ only: ['scheduledRides'] });
    });
    listenOwned(personal, '.ride.reschedule-responded', () => {
        if (!isClient) playAttentionAlert();
        router.reload({ only: ['scheduledRides'] });
    });
    // Recordatorio de 15-20 min antes de una carrera programada (pedido
    // explícito del usuario) — solo le llega al conductor (ver
    // RideReminderDue::broadcastOn()), acá solo hace falta el sonido: la
    // tarjeta ya muestra la hora, no hace falta recargar nada.
    listenOwned(personal, '.ride.reminder-due', (event) => {
        playAttentionAlert();
        reminderRideIds.value = new Set([...reminderRideIds.value, event.ride_id]);
    });
    // Una por cada flota donde soy conductor activo: acá llegan las
    // solicitudes "a toda la flota disponible".
    props.driverFleetIds.forEach((fleetId) => {
        const fleetChannel = window.Echo.private(`fleet.${fleetId}`);
        listenOwned(fleetChannel, '.ride-request.created', (e) => {
            playAttentionAlert();
            incoming.value.unshift(e);
        });
        listenOwned(fleetChannel, '.ride-request.accepted', (e) => {
            incoming.value = incoming.value.filter((r) => r.id !== e.ride_request_id);
        });
        // Otro conductor de la flota ya tomó esta solicitud contraofertando:
        // deja de estar disponible para mí.
        listenOwned(fleetChannel, '.ride-request.countered', (e) => {
            incoming.value = incoming.value.filter((r) => r.id !== e.ride_request_id);
        });
        listenOwned(fleetChannel, '.ride-request.cancelled', (e) => {
            incoming.value = incoming.value.filter((r) => r.id !== e.ride_request_id);
        });
        listenOwned(fleetChannel, '.ride-request.price-raised', (e) => {
            const req = incoming.value.find((r) => r.id === e.ride_request_id);
            if (req) req.current_offered_price = e.offered_amount;
        });
    });

    requestSyncTimer = window.setInterval(syncActiveRequests, 10000);
    document.addEventListener('visibilitychange', syncWhenVisible);
    window.addEventListener('focus', syncActiveRequests);
});

onBeforeUnmount(() => {
    ownedChannelListeners.forEach(({ channel, event, callback }) => channel.stopListening(event, callback));
    window.clearInterval(requestSyncTimer);
    document.removeEventListener('visibilitychange', syncWhenVisible);
    window.removeEventListener('focus', syncActiveRequests);
});

function acceptRequest(id) {
    if (processingRequestId.value) return;
    processingRequestId.value = id;
    router.post(route('ride-requests.accept', id), {
        charge_pickup_fee: chargePickup.value[id] ?? false,
    }, {
        preserveScroll: true,
        onFinish: () => (processingRequestId.value = null),
    });
}

function rejectRequest(id) {
    if (processingRequestId.value) return;
    processingRequestId.value = id;
    router.post(route('ride-requests.reject', id), {}, {
        preserveScroll: true,
        onSuccess: () => (incoming.value = incoming.value.filter((r) => r.id !== id)),
        onFinish: () => (processingRequestId.value = null),
    });
}

function counterRequest(id) {
    const amount = counterAmounts.value[id];
    if (!amount || processingRequestId.value) return;

    processingRequestId.value = id;

    router.post(
        route('ride-requests.counter', id),
        { offered_amount: amount },
        {
            preserveScroll: true,
            onSuccess: () => {
                // Ya usé mi única ronda de contraoferta (sección 5): ahora
                // espero la respuesta del cliente, no me queda más por hacer acá.
                incoming.value = incoming.value.filter((r) => r.id !== id);
            },
            onFinish: () => (processingRequestId.value = null),
        }
    );
}

function cancelRequest(id) {
    router.post(route('ride-requests.cancel', id), {}, { preserveScroll: true });
    myPending.value = myPending.value.filter((r) => r.id !== id);
}

// Reutiliza la ruta completa de la solicitud que terminó. `replace: true`
// evita que Atrás restaure desde el historial la tarjeta antigua de
// "Buscando su conductor". initialDestination hace que Request.vue abra
// directamente la elección de conductor, no el buscador de destino.
function retryRequest(request, category = 'fleet') {
    router.visit(route('ride-requests.create', {
        flota: request.fleet_id,
        categoria: category,
        origin_lat: request.origin_lat,
        origin_lng: request.origin_lng,
        origin_address: request.origin_address,
        origin_sector_id: request.origin_sector_id,
        destination_lat: request.destination_lat,
        destination_lng: request.destination_lng,
        destination_address: request.destination_address,
        destination_sector_id: request.destination_sector_id,
        passenger_count: request.passenger_count,
        needs_trunk: request.needs_trunk ? 1 : 0,
        payment_method: request.payment_method,
    }), {
        replace: true,
        preserveState: false,
    });
}

// --- Feedback en vivo mientras se espera (sección 9.7: "que no parezca que
// no pasó nada" — mismo espíritu que Uber mostrando "buscando conductor"). ---
const now = ref(Date.now());
let clock = null;
onMounted(() => {
    clock = setInterval(() => (now.value = Date.now()), 15000);
});
onBeforeUnmount(() => clearInterval(clock));

// Despacho secuencial estilo Uber (pedido explícito del usuario): reloj
// aparte, más fino, solo para el contéo regresivo de 30 seg. — el de arriba
// (cada 15 seg.) es demasiado tosco para eso.
const nowFast = ref(Date.now());
let fastClock = null;
onMounted(() => {
    fastClock = setInterval(() => (nowFast.value = Date.now()), 1000);
});
onBeforeUnmount(() => clearInterval(fastClock));

// Rediseño UX (pedido explícito del usuario): la lógica de estos dos
// mensajes se extrajo a Utils/rideWaitingMessage.js para reusarla también
// en Ride/Request.vue — acá solo se les pasa el reloj propio de esta
// pantalla (nowFast/now, cada una con su propio setInterval).
function secondsLeft(request) {
    return sharedSecondsLeft(request, nowFast.value);
}

function waitingMessage(request) {
    return sharedWaitingMessage(request, now.value);
}

const raisingOfferFor = ref(null);
const raiseAmounts = ref({});

function startRaiseOffer(request) {
    raisingOfferFor.value = request.id;
    raiseAmounts.value[request.id] = (Number(request.current_offered_price) + 1).toFixed(2);
}

function confirmRaiseOffer(id) {
    const amount = raiseAmounts.value[id];
    if (!amount) return;

    router.post(
        route('ride-requests.raise-offer', id),
        { offered_amount: amount },
        {
            preserveScroll: true,
            onSuccess: () => {
                raisingOfferFor.value = null;
                const req = myPending.value.find((r) => r.id === id);
                if (req) req.current_offered_price = amount;
            },
        }
    );
}
</script>

<template>
    <Head title="Carreras" />

    <AuthenticatedLayout>
        <template v-if="!activeImmediateRequest" #header>
            <div class="flex items-center justify-between flex-wrap gap-2">
                <h2 class="font-semibold text-xl text-arka-text leading-tight">Carreras</h2>
                <!-- Pedido explícito del usuario: reporte completo con
                     filtros, indicadores y medallas — ver Driver/Stats.vue. -->
                <Link
                    v-if="usePage().props.auth.isDriver"
                    :href="route('rides.stats')"
                    class="text-sm text-arka-primary hover:text-arka-primary-bright"
                >
                    Ver mis indicadores &rarr;
                </Link>
            </div>
        </template>

        <div class="overflow-x-hidden py-6 sm:py-12">
            <div class="mx-auto w-full max-w-3xl space-y-6 px-3 sm:px-6 lg:px-8">
                <div v-if="isClient && !activeImmediateRequest && !activeRides.length" class="flex justify-end">
                    <Link :href="route('ride-requests.create')">
                        <PrimaryButton>Pedir una carrera</PrimaryButton>
                    </Link>
                </div>

                <section v-if="isClient && activeImmediateRequest" class="w-full overflow-hidden rounded-3xl border border-arka-primary/30 bg-gradient-to-b from-arka-primary/15 to-arka-card shadow-2xl">
                    <div class="p-5 sm:p-7">
                        <div class="flex items-start gap-3">
                            <div class="relative grid h-12 w-12 shrink-0 place-items-center rounded-full bg-arka-primary text-arka-base shadow-lg shadow-arka-primary/25">
                                <span class="absolute inset-0 animate-ping rounded-full bg-arka-primary/30" aria-hidden="true"></span>
                                <svg class="relative h-6 w-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5.25 6.5A2.75 2.75 0 0 1 8 3.75h8a2.75 2.75 0 0 1 2.75 2.75v.75h.5A1.75 1.75 0 0 1 21 9v7.25a1.75 1.75 0 0 1-1.75 1.75h-.5v.75a1.5 1.5 0 0 1-3 0V18h-7.5v.75a1.5 1.5 0 0 1-3 0V18h-.5A1.75 1.75 0 0 1 3 16.25V9a1.75 1.75 0 0 1 1.75-1.75h.5V6.5Zm2.1.75h9.3l-.55-1.37a.75.75 0 0 0-.7-.48H8.6a.75.75 0 0 0-.7.48l-.55 1.37ZM6.5 14a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm11 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" /></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-arka-primary">Solicitud activa</p>
                                <h1 class="mt-1 text-xl font-bold leading-tight text-arka-text">Buscando su conductor</h1>
                                <p class="mt-1 text-sm text-arka-text-muted">Le avisaremos en cuanto alguien acepte.</p>
                            </div>
                        </div>

                        <div class="mt-5 h-2 overflow-hidden rounded-full bg-arka-base/80"><div class="h-full w-full animate-pulse rounded-full bg-gradient-to-r from-arka-primary/30 via-arka-primary to-arka-lime"></div></div>

                        <div class="mt-5 rounded-2xl border border-arka-text-muted/10 bg-arka-base/55 p-4">
                            <div class="flex gap-3">
                                <div class="flex shrink-0 flex-col items-center pt-1">
                                    <span class="h-3 w-3 rounded-full bg-arka-primary ring-4 ring-arka-primary/10"></span>
                                    <span class="my-1 min-h-12 w-0.5 flex-1 bg-gradient-to-b from-arka-primary to-arka-danger"></span>
                                    <span class="h-3 w-3 rotate-45 rounded-[2px] bg-arka-danger ring-4 ring-arka-danger/10"></span>
                                </div>
                                <div class="min-w-0 flex-1 space-y-4">
                                    <div><p class="text-[10px] font-semibold uppercase tracking-wider text-arka-primary">Recoger en</p><p class="mt-0.5 line-clamp-2 text-sm font-semibold leading-snug text-arka-text">{{ activeImmediateRequest.origin_address || 'Origen marcado en el mapa' }}</p></div>
                                    <div><p class="text-[10px] font-semibold uppercase tracking-wider text-arka-danger">Destino</p><p class="mt-0.5 line-clamp-2 text-sm font-semibold leading-snug text-arka-text">{{ activeImmediateRequest.destination_address || 'Destino seleccionado' }}</p></div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 flex items-center justify-between gap-3 rounded-2xl bg-arka-base/35 px-4 py-3">
                            <div class="min-w-0">
                                <p class="text-[10px] uppercase tracking-wide text-arka-text-muted">Estado</p>
                                <p class="truncate text-sm text-arka-text">{{ activeImmediateRequest.status === 'waiting' ? 'Esperando una unidad disponible' : activeImmediateRequest.driver ? `Contactando a ${activeImmediateRequest.driver.name}` : 'Buscando conductores cercanos' }}</p>
                            </div>
                            <div class="shrink-0 text-right"><p class="text-[10px] text-arka-text-muted">Oferta</p><p class="font-bold text-arka-primary">${{ Number(activeImmediateRequest.current_offered_price).toFixed(2) }}</p></div>
                        </div>

                        <button type="button" class="mx-auto mt-4 flex min-h-11 items-center gap-2 px-4 text-sm font-semibold text-arka-danger" @click="cancelRequest(activeImmediateRequest.id)">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12 2.25a9.75 9.75 0 1 0 0 19.5 9.75 9.75 0 0 0 0-19.5Zm-2.47 6.22a.75.75 0 0 0-1.06 1.06L10.94 12l-2.47 2.47a.75.75 0 1 0 1.06 1.06L12 13.06l2.47 2.47a.75.75 0 1 0 1.06-1.06L13.06 12l2.47-2.47a.75.75 0 1 0-1.06-1.06L12 10.94 9.53 8.47Z" clip-rule="evenodd" /></svg>
                            Cancelar solicitud
                        </button>
                    </div>
                </section>

                <template v-if="!activeImmediateRequest">

                <section v-if="upcomingReminderRide" class="rounded-2xl border border-arka-warning/40 bg-arka-warning/10 p-4 sm:p-5"><div class="flex items-center gap-3"><span class="text-2xl">⏰</span><div class="min-w-0 flex-1"><p class="font-bold text-arka-warning">Su carrera programada está próxima</p><p class="text-sm text-arka-text">{{ upcomingReminderRide.client.name }} · {{ formatScheduledAt(upcomingReminderRide.ride_request?.scheduled_at) }}</p><p class="mt-1 text-xs text-arka-text-muted">Revise la ruta y prepárese para salir.</p></div><Link :href="route('rides.show', upcomingReminderRide.id)" class="shrink-0 rounded-full bg-arka-warning px-4 py-2 text-xs font-bold text-arka-base">Ver viaje</Link></div></section>

                <!-- Pedido explícito del usuario: alarma visible mientras haya
                     carreras completadas sin calificar de mi parte — cliente y
                     conductor califican de forma independiente, no se espera al
                     otro para nada, pero sí se recuerda hasta que cada uno lo haga. -->
                <div v-if="unratedRideIds.length" class="p-4 rounded-arka bg-arka-warning/15 border border-arka-warning/40 flex items-center justify-between gap-3 flex-wrap">
                    <p class="text-sm text-arka-warning font-medium">
                        ⚠️ Tiene {{ unratedRideIds.length }} carrera{{ unratedRideIds.length > 1 ? 's' : '' }} completada{{ unratedRideIds.length > 1 ? 's' : '' }} sin calificar todavía.
                    </p>
                    <Link :href="route('rides.show', unratedRideIds[0])" class="text-sm text-arka-warning underline hover:text-arka-warning/80">
                        Calificar ahora &rarr;
                    </Link>
                </div>

                <!-- Carreras en curso -->
                <div v-if="activeRides.length" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-3">En curso</h3>
                    <ul class="divide-y divide-arka-text-muted/10">
                        <li v-for="ride in activeRides" :key="ride.id" class="py-3">
                            <Link :href="route('rides.show', ride.id)" class="flex items-center justify-between gap-3">
                                <span class="min-w-0">
                                    <span class="text-arka-text block truncate">
                                        {{ ride.client.id === userId ? ride.driver.name : ride.client.name }}
                                    </span>
                                    <span class="text-xs text-arka-text-muted">{{ activeRideStatusLabel(ride) }}</span>
                                </span>
                                <span class="text-arka-primary-bright text-sm shrink-0">Ver seguimiento &rarr;</span>
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- Programados (consideración agregada al alcance: "ahora mismo" vs
                     "programación") — ya aceptados, pero el conductor todavía no los
                     arrancó, así que no cuentan como "en curso". -->
                <div v-if="scheduledRides.length" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-3">Programados</h3>
                    <ul class="divide-y divide-arka-text-muted/10">
                        <li v-for="ride in scheduledRides" :key="ride.id" class="py-3 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-arka-text font-medium">
                                    {{ ride.client.id === userId ? ride.driver.name : ride.client.name }}
                                </p>
                                <p class="text-sm" :class="isScheduledOverdue(ride.ride_request?.scheduled_at) ? 'text-arka-danger' : 'text-arka-primary-bright'">
                                    {{ formatScheduledAt(ride.ride_request?.scheduled_at) }}
                                    <span v-if="ride.round_trip" class="ms-1 text-xs text-arka-text-muted">· Ida y vuelta</span>
                                </p>
                                <!-- Bug reportado por el usuario: antes esto se
                                     quedaba mostrando "Iniciar viaje" para
                                     siempre, sin avisar que ya pasó la hora. -->
                                <p v-if="isScheduledOverdue(ride.ride_request?.scheduled_at) && !ride.pending_reschedule_at" class="text-xs text-arka-danger">
                                    ⚠️ Ya pasó la hora — inícela ahora o avísele al {{ ride.client.id === userId ? 'conductor' : 'cliente' }}
                                </p>
                                <!-- Pedido explícito del usuario: si el cliente
                                     editó el horario, tiene que verse acá mismo,
                                     no solo al entrar al detalle. -->
                                <p v-if="ride.pending_reschedule_at" class="text-xs text-arka-warning">
                                    ⏳ Cambio de horario pendiente de confirmar
                                </p>
                            </div>
                            <PrimaryButton
                                v-if="ride.driver_user_id === userId && !ride.pending_reschedule_at"
                                class="shrink-0"
                                @click="startRide(ride.id)"
                            >
                                Iniciar viaje
                            </PrimaryButton>
                            <Link v-else :href="route('rides.show', ride.id)" class="text-arka-primary-bright text-sm shrink-0">
                                Ver &rarr;
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- Invitaciones de cooperativa (pedido explícito del usuario: que le
                     llegue en esta misma pantalla, como una solicitud de carrera más,
                     no solo escondida en /cooperativas/invitaciones). -->
                <div v-if="pendingCooperativeInvitations.length" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-3">Invitaciones de cooperativa</h3>
                    <ul class="space-y-4">
                        <li
                            v-for="invitation in pendingCooperativeInvitations"
                            :key="invitation.id"
                            class="overflow-hidden rounded-2xl border border-arka-text-muted/15 bg-arka-base/35 p-4 shadow-lg"
                        >
                            <div class="flex items-start gap-3">
                                <img
                                    v-if="invitation.cooperative.logo_url"
                                    :src="invitation.cooperative.logo_url"
                                    class="h-12 w-12 shrink-0 rounded-xl bg-white object-contain p-1"
                                    alt="Logo"
                                />
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-arka-text">{{ invitation.cooperative.name }}</p>
                                    <p class="text-sm text-arka-text-muted">
                                        {{ invitation.cooperative.city?.name }}
                                        <span v-if="invitation.cooperative.geographic_coverage"> · {{ invitation.cooperative.geographic_coverage }}</span>
                                    </p>
                                </div>
                            </div>
                            <p class="mt-2 text-sm text-arka-text-muted">Quiere vincularlo como conductor afiliado.</p>
                            <div class="mt-3 flex justify-end gap-2">
                                <DangerButton @click="respondToCooperativeInvitation(invitation.id, 'reject')">Rechazar</DangerButton>
                                <PrimaryButton @click="respondToCooperativeInvitation(invitation.id, 'accept')">Aceptar vínculo</PrimaryButton>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Solicitudes que me llegaron como conductor -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-3">Solicitudes para usted</h3>

                    <p v-if="!incoming.length" class="text-sm text-arka-text-muted">
                        No tiene solicitudes de carrera por ahora.
                    </p>

                    <ul v-else class="space-y-4">
                        <li v-for="r in incoming" :key="r.id" class="overflow-hidden rounded-2xl border border-arka-text-muted/15 bg-arka-base/35 shadow-lg">
                            <div class="flex items-center justify-between gap-3 border-b border-arka-text-muted/10 bg-arka-primary/10 px-4 py-3">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wider text-arka-primary">Carrera disponible</p>
                                    <p class="mt-0.5 text-2xl font-bold text-arka-primary-bright">${{ Number(r.current_offered_price).toFixed(2) }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold text-arka-text">{{ Number(r.distance_km).toFixed(1) }} km</p>
                                    <p class="text-xs capitalize text-arka-text-muted">{{ r.payment_method ?? 'efectivo' }}</p>
                                </div>
                            </div>

                            <div class="space-y-4 p-4">
                            <!-- Quién es (sección 3.6 y 8: "app segura"), antes de decidir
                                 aceptar — nombre, calificación y código de socio. -->
                            <div class="flex items-center gap-3">
                                <UserAvatar :user="{ name: r.client_name, avatar_url: r.client_avatar_url }" size-class="h-11 w-11 text-base" />
                            <p class="text-arka-text font-medium flex items-center gap-2 flex-wrap min-w-0">
                                {{ r.client_name }}
                                <span
                                    v-if="r.client_review_count > 0"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-arka-lime/15 text-arka-lime"
                                >
                                    <span class="leading-none">★</span> {{ Number(r.client_rating).toFixed(1) }}
                                </span>
                                <span v-else class="text-xs text-arka-text-muted">Sin calificaciones todavía</span>
                                <span v-if="r.client_member_code" class="text-xs text-arka-text-muted">#{{ r.client_member_code }}</span>
                                <TrustScoreBadge :trust="r.client_trust" compact />
                            </p>
                            </div>
                            <!-- Sector de origen/destino (consideración agregada al alcance): de
                                 un vistazo, sin tener que abrir el mapa — ej. "Sauces 1 → Samanes 3". -->
                            <div class="flex gap-3 rounded-arka border border-arka-text-muted/10 bg-arka-card/45 p-3">
                                <div class="flex shrink-0 flex-col items-center pt-1.5">
                                    <span class="h-2.5 w-2.5 rounded-full bg-arka-lime"></span>
                                    <span class="my-1 min-h-8 w-px flex-1 bg-arka-text-muted/30"></span>
                                    <span class="h-2.5 w-2.5 rounded-full bg-arka-danger"></span>
                                </div>
                                <div class="min-w-0 flex-1 space-y-3">
                                    <div>
                                        <p class="truncate text-sm font-semibold text-arka-text">{{ r.origin_sector?.name ?? 'Origen' }}</p>
                                        <p class="line-clamp-2 text-xs text-arka-text-muted">{{ r.origin_address ?? 'Origen sin referencia' }}</p>
                                    </div>
                                    <div>
                                        <p class="truncate text-sm font-semibold text-arka-text">{{ r.destination_sector?.name ?? 'Destino' }}</p>
                                        <p class="line-clamp-2 text-xs text-arka-text-muted">{{ r.destination_address ?? 'Destino sin referencia' }}</p>
                                    </div>
                                </div>
                            </div>
                            <p v-if="r.is_scheduled" class="mt-1 text-xs text-arka-warning font-medium">
                                📅 Programada para {{ formatScheduledAt(r.scheduled_at) }}
                                <span v-if="r.round_trip"> · Ida y vuelta</span>
                            </p>
                            <!-- Observación del cliente (pedido explícito del usuario). -->
                            <p v-if="r.notes" class="mt-1 text-sm text-arka-text-muted italic">"{{ r.notes }}"</p>

                            <!-- Despacho secuencial estilo Uber (pedido explícito del usuario):
                                 si no respondo en este tiempo, pasa al siguiente conductor de la bolsa. -->
                            <p v-if="secondsLeft(r) !== null" class="mt-1 text-xs font-medium" :class="secondsLeft(r) <= 10 ? 'text-arka-danger' : 'text-arka-warning'">
                                ⏱ Tiene {{ secondsLeft(r) }} seg. para responder antes de que pase a otro conductor
                            </p>

                            <!-- Ya usé mi contraoferta en esta: solo queda esperar (sección 5) -->
                            <p v-if="r.status === 'negotiating'" class="mt-2 text-sm text-arka-text-muted italic">
                                Ya le mandó una contraoferta. Esperando que el cliente responda.
                            </p>

                            <div v-else class="space-y-3">
                                <!-- Cargo por trayecto de recogida (pedido explícito del
                                     usuario): solo aparece cuando el cliente está lo bastante
                                     lejos como para superar el umbral configurado en
                                     /admin/tarifas — el conductor ve el desglose separado
                                     (recogida vs. origen-destino) y decide si lo cobra. -->
                                <div v-if="r.pickup_fare > 0" class="space-y-2 rounded-arka border border-arka-lime/25 bg-arka-lime/10 p-3">
                                    <p class="text-xs font-semibold uppercase tracking-wider text-arka-lime">Trayecto de recogida</p>
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-arka-text-muted">Recogida · {{ Number(r.pickup_distance_km).toFixed(1) }} km</span>
                                        <span class="text-arka-text font-medium">${{ Number(r.pickup_fare).toFixed(2) }}</span>
                                    </div>
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-arka-text-muted">Origen → destino · {{ Number(r.distance_km).toFixed(1) }} km</span>
                                        <span class="text-arka-text font-medium">${{ Number(r.current_offered_price).toFixed(2) }}</span>
                                    </div>
                                    <label class="flex items-center gap-2 pt-1">
                                        <input type="checkbox" v-model="chargePickup[r.id]" class="text-arka-primary rounded" />
                                        <span class="text-sm text-arka-text">Cobrar ${{ Number(r.pickup_fare).toFixed(2) }} de recogida al cliente</span>
                                    </label>
                                </div>

                                <PrimaryButton class="min-h-12 w-full justify-center text-sm" :disabled="processingRequestId === r.id || secondsLeft(r) === 0" @click="acceptRequest(r.id)">
                                    {{ processingRequestId === r.id ? 'Procesando…' : 'Aceptar carrera' }}
                                </PrimaryButton>

                                <div class="grid grid-cols-[1fr_auto] gap-2 items-center">
                                    <TextInput
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        class="min-w-0 w-full"
                                        v-model="counterAmounts[r.id]"
                                        placeholder="Proponer otro monto"
                                    />
                                    <SecondaryButton class="min-h-10" @click="counterRequest(r.id)">Enviar</SecondaryButton>
                                </div>

                                <div class="flex justify-center">
                                    <!-- Rechazar solo tiene sentido en una solicitud dirigida a mí;
                                         en "toda la flota" simplemente no respondo (sección 3.5) -->
                                    <button v-if="r.driver_user_id" type="button" class="min-h-10 px-4 text-sm text-arka-text-muted hover:text-arka-danger" @click="rejectRequest(r.id)">No puedo tomarla</button>
                                </div>
                            </div>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Mis solicitudes pendientes como cliente -->
                <div v-if="otherPendingRequests.length" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-3">Esperando respuesta</h3>
                    <ul class="divide-y divide-arka-text-muted/10">
                        <li v-for="r in otherPendingRequests" :key="r.id" class="py-3">
                            <div class="flex items-center justify-between">
                                <span class="text-arka-text">
                                    {{ r.driver ? r.driver.name : 'Toda la flota disponible' }}
                                </span>
                                <span class="text-sm text-arka-text-muted">
                                    ${{ Number(r.current_offered_price).toFixed(2) }}
                                </span>
                            </div>

                            <p v-if="r.origin_sector?.name || r.destination_sector?.name" class="mt-1 text-sm text-arka-text-muted">
                                {{ r.origin_sector?.name ?? 'origen sin sector' }} &rarr; {{ r.destination_sector?.name ?? 'destino sin sector' }}
                            </p>
                            <p v-if="r.is_scheduled" class="mt-1 text-xs text-arka-warning font-medium">
                                📅 Programada para {{ formatScheduledAt(r.scheduled_at) }}
                                <span v-if="r.round_trip"> · Ida y vuelta</span>
                            </p>

                            <p v-if="r.status === 'negotiating'" class="mt-1 text-sm text-arka-lime">
                                {{ r.negotiating_driver_name ?? r.negotiating_driver?.name ?? 'El conductor' }}
                                contraofertó este precio.
                            </p>

                            <!-- Despacho secuencial estilo Uber (pedido explícito del usuario):
                                 se le ofreció a cada candidato de la bolsa y ninguno respondió a
                                 tiempo — ya quedó "expired" en el backend, no queda nada por hacer
                                 salvo pedirla de nuevo. -->
                            <p v-else-if="r.status === 'expired'" class="mt-1 text-sm text-arka-danger">
                                Nadie respondió a tiempo. Pruebe de nuevo o suba su oferta para que sea más atractiva.
                            </p>

                            <!-- Bug reportado por el usuario: un conductor puntual que rechaza
                                 tenía que avisarle al cliente qué pasó, y recomendarle ampliar la
                                 búsqueda — antes se quedaba pegada en "buscando" en silencio. -->
                            <p v-else-if="r.status === 'declined'" class="mt-1 text-sm text-arka-danger">
                                {{ r.declined_driver_name ?? 'El conductor' }} rechazó su solicitud. Pruebe con toda su flota o el directorio público.
                            </p>

                            <!-- Lista de espera (pedido explícito del usuario: "puedo dejar la
                                 carrera pendiente hasta que uno se desocupe y me atienda") — todos
                                 los conductores elegibles estaban ocupados al pedirla, se activa
                                 sola apenas alguien se libere (ver ".ride-request.created" arriba)
                                 o expira a los 15 min si nadie lo hace a tiempo. -->
                            <p v-else-if="r.status === 'waiting'" class="mt-1 text-sm text-arka-warning">
                                Todos sus conductores están en otra carrera ahora mismo — le avisamos apenas se libere uno (hasta 15 minutos).
                            </p>

                            <!-- Feedback en vivo tipo "buscando conductor" (sección 9.7) — para
                                 que no parezca que no está pasando nada mientras se espera. -->
                            <p v-else-if="waitingMessage(r)" class="mt-1 text-sm text-arka-text-muted italic">
                                {{ waitingMessage(r) }}
                            </p>

                            <div v-if="r.status === 'expired'" class="mt-2 flex gap-2">
                                <PrimaryButton @click="retryRequest(r, r.dispatch_pool === 'public' ? 'public' : r.dispatch_pool === 'both' ? 'all' : 'fleet')">
                                    Pedir de nuevo
                                </PrimaryButton>
                                <SecondaryButton @click="myPending = myPending.filter((x) => x.id !== r.id)">
                                    Descartar
                                </SecondaryButton>
                            </div>
                            <div v-else-if="r.status === 'declined'" class="mt-2 flex flex-wrap gap-2">
                                <PrimaryButton @click="retryRequest(r, 'fleet')">Pedir a toda mi flota</PrimaryButton>
                                <Link v-if="route().has('directory.index')" :href="route('directory.index')">
                                    <SecondaryButton>Ver directorio público</SecondaryButton>
                                </Link>
                                <SecondaryButton @click="myPending = myPending.filter((x) => x.id !== r.id)">
                                    Descartar
                                </SecondaryButton>
                            </div>
                            <div v-else class="mt-2 flex gap-2">
                                <PrimaryButton v-if="r.status === 'negotiating'" @click="acceptRequest(r.id)">
                                    Aceptar
                                </PrimaryButton>
                                <SecondaryButton @click="cancelRequest(r.id)">
                                    {{ r.status === 'negotiating' ? 'Rechazar' : 'Cancelar' }}
                                </SecondaryButton>
                                <SecondaryButton
                                    v-if="r.status === 'pending' && raisingOfferFor !== r.id"
                                    @click="startRaiseOffer(r)"
                                >
                                    Subir oferta
                                </SecondaryButton>
                            </div>

                            <div v-if="raisingOfferFor === r.id" class="mt-2 flex gap-2 items-center">
                                <TextInput
                                    type="number"
                                    step="0.01"
                                    :min="Number(r.current_offered_price) + 0.01"
                                    class="w-32"
                                    v-model="raiseAmounts[r.id]"
                                />
                                <PrimaryButton @click="confirmRaiseOffer(r.id)">Confirmar</PrimaryButton>
                                <SecondaryButton @click="raisingOfferFor = null">Cancelar</SecondaryButton>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Historial -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <h3 class="text-lg font-medium text-arka-text">Historial</h3>
                        <span v-if="rideHistory.total" class="text-xs text-arka-text-muted">{{ rideHistory.total }} carrera{{ rideHistory.total > 1 ? 's' : '' }}</span>
                    </div>

                    <p v-if="!rideHistory.data.length" class="text-sm text-arka-text-muted">
                        Todavía no tiene carreras completadas.
                    </p>

                    <ul v-else class="divide-y divide-arka-text-muted/10">
                        <li v-for="ride in rideHistory.data" :key="ride.id">
                            <Link
                                :href="route('rides.show', ride.id)"
                                class="flex items-center gap-3 py-3 -mx-2 px-2 rounded-lg transition-colors hover:bg-arka-base/40"
                            >
                                <UserAvatar
                                    :user="ride.client.id === userId ? ride.driver : ride.client"
                                    size-class="h-10 w-10 text-sm shrink-0"
                                />
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center gap-2 flex-wrap">
                                        <span class="text-arka-text font-medium truncate">
                                            {{ ride.client.id === userId ? ride.driver.name : ride.client.name }}
                                        </span>
                                        <span
                                            class="px-1.5 py-0.5 rounded text-[11px] font-semibold shrink-0"
                                            :class="HISTORY_STATUS_BADGE_CLASS[ride.status] ?? 'bg-arka-text-muted/15 text-arka-text-muted'"
                                        >
                                            {{ HISTORY_STATUS_LABEL[ride.status] ?? ride.status }}
                                        </span>
                                        <span v-if="ride.needs_my_review" class="px-1.5 py-0.5 rounded text-[11px] font-medium bg-arka-warning/15 text-arka-warning shrink-0">
                                            Sin calificar
                                        </span>
                                    </span>
                                    <span class="block text-xs text-arka-text-muted mt-0.5">{{ formatHistoryDate(ride.occurred_at) }}</span>
                                </span>
                                <span class="text-arka-text font-semibold shrink-0">${{ ride.price.toFixed(2) }}</span>
                            </Link>
                        </li>
                    </ul>

                    <!-- Paginado (pedido explícito del usuario: "asegura que tenga
                         paginado") — mismo criterio simple de Anterior/Siguiente
                         que ya usa Admin/Rides.vue. -->
                    <div v-if="rideHistory.prev_page_url || rideHistory.next_page_url" class="flex justify-between pt-3 mt-1 border-t border-arka-text-muted/10">
                        <Link
                            v-if="rideHistory.prev_page_url"
                            :href="rideHistory.prev_page_url"
                            preserve-scroll
                            class="text-sm text-arka-primary hover:text-arka-primary-bright"
                        >
                            &larr; Anterior
                        </Link>
                        <span v-else></span>

                        <Link
                            v-if="rideHistory.next_page_url"
                            :href="rideHistory.next_page_url"
                            preserve-scroll
                            class="text-sm text-arka-primary hover:text-arka-primary-bright"
                        >
                            Siguiente &rarr;
                        </Link>
                    </div>
                </div>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
