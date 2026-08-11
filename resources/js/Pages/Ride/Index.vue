<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { playAttentionAlert, playUpdateChime } from '@/Utils/liveAlert';

const props = defineProps({
    pendingRequestsAsClient: { type: Array, required: true },
    incomingRequestsAsDriver: { type: Array, required: true },
    activeRides: { type: Array, required: true },
    scheduledRides: { type: Array, required: true },
    rideHistory: { type: Array, required: true },
    driverFleetIds: { type: Array, required: true },
});

// Fecha/hora programada, legible (ej. "3 ago, 18:00") — mismo formato en
// todas las tarjetas que muestran `scheduled_at` (consideración agregada al
// alcance: "ahora mismo" vs "programación").
function formatScheduledAt(iso) {
    return new Date(iso).toLocaleString('es-EC', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

function startRide(id) {
    router.post(route('rides.start', id), {}, { preserveScroll: true });
}

const userId = usePage().props.auth.user.id;

// Pedido explícito del usuario: "Pedir una carrera" es una acción del lado
// cliente — un conductor no debería ver ese botón acá (esta pantalla es
// compartida entre los dos roles, muestra tanto lo que pedí como cliente
// como lo que me llega como conductor).
const isClient = usePage().props.auth.isClient;

// Pedido explícito del usuario ("si no han calificado que le arroje una
// alarma"): cliente y conductor califican de forma independiente, así que
// esto se calcula igual para los dos lados — cada carrera completada trae
// `needs_my_review` ya resuelto desde RideController::index().
const unratedRides = computed(() => props.rideHistory.filter((ride) => ride.needs_my_review));

// Copias locales para poder sumar/sacar en vivo sin esperar una recarga de
// página completa (sección 3.5: notificación instantánea de solicitudes).
const incoming = ref([...props.incomingRequestsAsDriver]);
const myPending = ref([...props.pendingRequestsAsClient]);

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

const channels = [];

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
    personal.listen('.ride-request.created', (e) => {
        const req = myPending.value.find((r) => r.id === e.id);
        if (req) {
            req.status = e.status;
            req.driver_user_id = e.driver_user_id;
            req.driver = e.driver_name ? { name: e.driver_name } : null;
            req.current_offered_price = e.current_offered_price;
            req.current_offer_expires_at = e.current_offer_expires_at;
        }
    });
    // Pedido explícito del usuario: apenas el conductor acepta, el cliente
    // pasa directo al detalle de esa carrera en vez de quedarse mirando la
    // lista — ahí es donde puede seguir la ubicación en vivo y chatear.
    personal.listen('.ride-request.accepted', (e) => {
        playUpdateChime();
        router.visit(route('rides.show', e.ride_id));
    });
    personal.listen('.ride-request.countered', (e) => {
        playUpdateChime();
        const req = myPending.value.find((r) => r.id === e.ride_request_id);
        if (req) {
            req.status = 'negotiating';
            req.current_offered_price = e.offered_amount;
            req.negotiating_driver_name = e.driver_name;
        }
    });
    personal.listen('.ride-request.cancelled', (e) => {
        incoming.value = incoming.value.filter((r) => r.id !== e.ride_request_id);
    });
    // Despacho secuencial estilo Uber (pedido explícito del usuario): nadie
    // de la bolsa respondió a tiempo — la tarjeta "Esperando respuesta" tiene
    // que decirlo, no quedarse pegada en silencio.
    personal.listen('.ride-request.expired', (e) => {
        playUpdateChime();
        const req = myPending.value.find((r) => r.id === e.ride_request_id);
        if (req) req.status = 'expired';
    });
    // Bug reportado por el usuario: el conductor puntual al que le pedí la
    // carrera la rechazó, y no me enteraba de nada — la tarjeta se quedaba
    // pegada en "buscando" para siempre, en silencio.
    personal.listen('.ride-request.declined', (e) => {
        playUpdateChime();
        const req = myPending.value.find((r) => r.id === e.ride_request_id);
        if (req) {
            req.status = 'declined';
            req.declined_driver_name = e.driver_name;
        }
    });
    // El cliente subió su propia oferta (sin esperar a que yo contraoferte) —
    // se actualiza el monto ahí mismo en la lista, sin recargar.
    personal.listen('.ride-request.price-raised', (e) => {
        const req = incoming.value.find((r) => r.id === e.ride_request_id);
        if (req) req.current_offered_price = e.offered_amount;
    });
    // Se completó una carrera propia (la haya completado yo u la otra
    // parte): "En curso" y "Historial" tienen que moverse solos, no
    // quedarse pegados hasta que alguien recargue la página a mano.
    // Pedido explícito del usuario ("sigue el problema que no llega las
    // notificaciones... debe existir un sonido fuerte... si es posible que
    // vibre"): estos 3 son cambios de estado de una carrera real, no
    // negociación previa — pasan de chime suave a alerta fuerte + vibración.
    personal.listen('.ride.completed', () => {
        playAttentionAlert();
        router.reload({ only: ['activeRides', 'rideHistory'] });
    });
    // El cliente canceló una carrera ya aceptada (pedido explícito del
    // usuario) — mismo criterio que ".ride.completed": que "En curso"/
    // "Programados" se actualicen solos para quien no la canceló.
    personal.listen('.ride.cancelled', () => {
        playAttentionAlert();
        router.reload({ only: ['activeRides', 'scheduledRides', 'rideHistory'] });
    });
    // El conductor arrancó una carrera que venía PROGRAMADA (consideración
    // agregada al alcance) — pasa de "Programados" a "En curso" solo, sin
    // esperar a que alguien recargue la página.
    personal.listen('.ride.started', () => {
        playAttentionAlert();
        router.reload({ only: ['scheduledRides', 'activeRides'] });
    });
    channels.push(personal);

    // Una por cada flota donde soy conductor activo: acá llegan las
    // solicitudes "a toda la flota disponible".
    props.driverFleetIds.forEach((fleetId) => {
        const fleetChannel = window.Echo.private(`fleet.${fleetId}`);
        fleetChannel.listen('.ride-request.created', (e) => {
            playAttentionAlert();
            incoming.value.unshift(e);
        });
        fleetChannel.listen('.ride-request.accepted', (e) => {
            incoming.value = incoming.value.filter((r) => r.id !== e.ride_request_id);
        });
        // Otro conductor de la flota ya tomó esta solicitud contraofertando:
        // deja de estar disponible para mí.
        fleetChannel.listen('.ride-request.countered', (e) => {
            incoming.value = incoming.value.filter((r) => r.id !== e.ride_request_id);
        });
        fleetChannel.listen('.ride-request.cancelled', (e) => {
            incoming.value = incoming.value.filter((r) => r.id !== e.ride_request_id);
        });
        fleetChannel.listen('.ride-request.price-raised', (e) => {
            const req = incoming.value.find((r) => r.id === e.ride_request_id);
            if (req) req.current_offered_price = e.offered_amount;
        });
        channels.push(fleetChannel);
    });
});

onBeforeUnmount(() => {
    channels.forEach((channel) => channel.stopListening('.ride-request.created'));
    window.Echo.leave(`App.Models.User.${userId}`);
    props.driverFleetIds.forEach((fleetId) => window.Echo.leave(`fleet.${fleetId}`));
});

function acceptRequest(id) {
    router.post(route('ride-requests.accept', id));
}

function rejectRequest(id) {
    router.post(route('ride-requests.reject', id), {}, { preserveScroll: true });
    incoming.value = incoming.value.filter((r) => r.id !== id);
}

function counterRequest(id) {
    const amount = counterAmounts.value[id];
    if (!amount) return;

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
        }
    );
}

function cancelRequest(id) {
    router.post(route('ride-requests.cancel', id), {}, { preserveScroll: true });
    myPending.value = myPending.value.filter((r) => r.id !== id);
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

function secondsLeft(request) {
    if (!request.current_offer_expires_at) return null;
    return Math.max(0, Math.round((new Date(request.current_offer_expires_at).getTime() - nowFast.value) / 1000));
}

function waitingMinutes(request) {
    if (!request.requested_at) return 0;
    return Math.floor((now.value - new Date(request.requested_at).getTime()) / 60000);
}

// Fix reportado por el usuario: acá decía siempre "buscando entre tus
// conductores disponibles" (plural) — confuso para una solicitud dirigida a
// UN conductor puntual, que no está "buscando entre" nadie más.
function waitingMessage(request) {
    const minutes = waitingMinutes(request);
    if (request.status === 'negotiating') return null;

    if (request.driver) {
        if (minutes < 3) return `Esperando que ${request.driver.name} responda…`;
        return `${request.driver.name} todavía no respondió. Si quiere, suba su oferta o cancele y pruebe con otro.`;
    }

    if (minutes < 1) return 'Buscando entre sus conductores disponibles…';
    if (minutes < 3) return 'Avisamos a sus conductores — puede tardar un minuto en aparecer alguien.';
    return 'Todavía nadie respondió. Si quiere, suba su oferta para que sea más atractiva.';
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
        <template #header>
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

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div v-if="isClient" class="flex justify-end">
                    <Link :href="route('ride-requests.create')">
                        <PrimaryButton>Pedir una carrera</PrimaryButton>
                    </Link>
                </div>

                <!-- Pedido explícito del usuario: alarma visible mientras haya
                     carreras completadas sin calificar de mi parte — cliente y
                     conductor califican de forma independiente, no se espera al
                     otro para nada, pero sí se recuerda hasta que cada uno lo haga. -->
                <div v-if="unratedRides.length" class="p-4 rounded-arka bg-arka-warning/15 border border-arka-warning/40 flex items-center justify-between gap-3 flex-wrap">
                    <p class="text-sm text-arka-warning font-medium">
                        ⚠️ Tiene {{ unratedRides.length }} carrera{{ unratedRides.length > 1 ? 's' : '' }} completada{{ unratedRides.length > 1 ? 's' : '' }} sin calificar todavía.
                    </p>
                    <Link :href="route('rides.show', unratedRides[0].id)" class="text-sm text-arka-warning underline hover:text-arka-warning/80">
                        Calificar ahora &rarr;
                    </Link>
                </div>

                <!-- Carreras en curso -->
                <div v-if="activeRides.length" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-3">En curso</h3>
                    <ul class="divide-y divide-arka-text-muted/10">
                        <li v-for="ride in activeRides" :key="ride.id" class="py-3">
                            <Link :href="route('rides.show', ride.id)" class="flex items-center justify-between">
                                <span class="text-arka-text">
                                    {{ ride.client.id === userId ? ride.driver.name : ride.client.name }}
                                </span>
                                <span class="text-arka-primary-bright text-sm">Ver seguimiento &rarr;</span>
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
                                <p class="text-sm text-arka-primary-bright">
                                    {{ formatScheduledAt(ride.ride_request?.scheduled_at) }}
                                    <span v-if="ride.round_trip" class="ms-1 text-xs text-arka-text-muted">· Ida y vuelta</span>
                                </p>
                            </div>
                            <PrimaryButton v-if="ride.driver_user_id === userId" class="shrink-0" @click="startRide(ride.id)">
                                Iniciar viaje
                            </PrimaryButton>
                            <Link v-else :href="route('rides.show', ride.id)" class="text-arka-primary-bright text-sm shrink-0">
                                Ver &rarr;
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- Solicitudes que me llegaron como conductor -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-3">Solicitudes para usted</h3>

                    <p v-if="!incoming.length" class="text-sm text-arka-text-muted">
                        No tiene solicitudes de carrera por ahora.
                    </p>

                    <ul v-else class="divide-y divide-arka-text-muted/10">
                        <li v-for="r in incoming" :key="r.id" class="py-3">
                            <!-- Quién es (sección 3.6 y 8: "app segura"), antes de decidir
                                 aceptar — nombre, calificación y código de socio. -->
                            <p class="text-arka-text font-medium flex items-center gap-2 flex-wrap">
                                {{ r.client_name }}
                                <span
                                    v-if="r.client_review_count > 0"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-arka-lime/15 text-arka-lime"
                                >
                                    <span class="leading-none">★</span> {{ Number(r.client_rating).toFixed(1) }}
                                </span>
                                <span v-else class="text-xs text-arka-text-muted">Sin calificaciones todavía</span>
                                <span v-if="r.client_member_code" class="text-xs text-arka-text-muted">#{{ r.client_member_code }}</span>
                            </p>
                            <!-- Sector de origen/destino (consideración agregada al alcance): de
                                 un vistazo, sin tener que abrir el mapa — ej. "Sauces 1 → Samanes 3". -->
                            <p
                                v-if="r.origin_sector?.name || r.destination_sector?.name"
                                class="text-sm text-arka-text font-medium"
                            >
                                {{ r.origin_sector?.name ?? 'origen sin sector' }} &rarr;
                                {{ r.destination_sector?.name ?? 'destino sin sector' }}
                            </p>
                            <p class="text-sm text-arka-text-muted">
                                {{ r.origin_address ?? 'Origen sin referencia' }} &rarr;
                                {{ r.destination_address ?? 'Destino sin referencia' }}
                                · {{ Number(r.distance_km).toFixed(1) }} km
                            </p>
                            <p class="text-sm text-arka-primary-bright font-medium mt-1">
                                Precio ofrecido: ${{ Number(r.current_offered_price).toFixed(2) }}
                            </p>
                            <!-- Forma de pago (pedido explícito del usuario): que el
                                 conductor la vea antes de aceptar. -->
                            <p class="text-sm text-arka-text-muted">
                                Paga con <span class="capitalize text-arka-text">{{ r.payment_method ?? 'efectivo' }}</span>
                            </p>
                            <p v-if="r.is_scheduled" class="mt-1 text-xs text-arka-warning font-medium">
                                📅 Programada para {{ formatScheduledAt(r.scheduled_at) }}
                                <span v-if="r.round_trip"> · Ida y vuelta</span>
                            </p>

                            <!-- Despacho secuencial estilo Uber (pedido explícito del usuario):
                                 si no respondo en este tiempo, pasa al siguiente conductor de la bolsa. -->
                            <p v-if="secondsLeft(r) !== null" class="mt-1 text-xs font-medium" :class="secondsLeft(r) <= 10 ? 'text-arka-danger' : 'text-arka-warning'">
                                ⏱ Tiene {{ secondsLeft(r) }} seg. para responder antes de que pase a otro conductor
                            </p>

                            <!-- Ya usé mi contraoferta en esta: solo queda esperar (sección 5) -->
                            <p v-if="r.status === 'negotiating'" class="mt-2 text-sm text-arka-text-muted italic">
                                Ya le mandó una contraoferta. Esperando que el cliente responda.
                            </p>

                            <div v-else class="mt-2 space-y-2">
                                <div class="flex gap-2">
                                    <PrimaryButton @click="acceptRequest(r.id)">Aceptar</PrimaryButton>
                                    <!-- Rechazar solo tiene sentido en una solicitud dirigida a mí;
                                         en "toda la flota" simplemente no respondo (sección 3.5) -->
                                    <SecondaryButton v-if="r.driver_user_id" @click="rejectRequest(r.id)">
                                        Rechazar
                                    </SecondaryButton>
                                </div>

                                <div class="flex gap-2 items-center">
                                    <TextInput
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        class="w-32"
                                        v-model="counterAmounts[r.id]"
                                        placeholder="Otro monto"
                                    />
                                    <SecondaryButton @click="counterRequest(r.id)">Contraofertar</SecondaryButton>
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Mis solicitudes pendientes como cliente -->
                <div v-if="myPending.length" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-3">Esperando respuesta</h3>
                    <ul class="divide-y divide-arka-text-muted/10">
                        <li v-for="r in myPending" :key="r.id" class="py-3">
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
                                <Link :href="route('ride-requests.create')">
                                    <PrimaryButton>Pedir de nuevo</PrimaryButton>
                                </Link>
                                <SecondaryButton @click="myPending = myPending.filter((x) => x.id !== r.id)">
                                    Descartar
                                </SecondaryButton>
                            </div>
                            <div v-else-if="r.status === 'declined'" class="mt-2 flex flex-wrap gap-2">
                                <Link :href="route('ride-requests.create')">
                                    <PrimaryButton>Pedir a toda mi flota</PrimaryButton>
                                </Link>
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
                    <h3 class="text-lg font-medium text-arka-text mb-3">Historial</h3>

                    <p v-if="!rideHistory.length" class="text-sm text-arka-text-muted">
                        Todavía no tiene carreras completadas.
                    </p>

                    <ul v-else class="divide-y divide-arka-text-muted/10">
                        <li v-for="ride in rideHistory" :key="ride.id" class="py-3">
                            <Link :href="route('rides.show', ride.id)" class="flex items-center justify-between gap-2">
                                <span class="text-arka-text">
                                    {{ ride.client.id === userId ? ride.driver.name : ride.client.name }}
                                </span>
                                <span class="text-sm text-arka-text-muted flex items-center gap-2 shrink-0">
                                    <span v-if="ride.needs_my_review" class="px-1.5 py-0.5 rounded text-[11px] font-medium bg-arka-warning/15 text-arka-warning">
                                        Sin calificar
                                    </span>
                                    ${{ ride.price }} · {{ ride.status }}
                                </span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
