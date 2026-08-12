<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DriverAvailabilityToggle from '@/Components/DriverAvailabilityToggle.vue';
import AdBannerSlider from '@/Components/AdBannerSlider.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { playAttentionAlert, playUpdateChime } from '@/Utils/liveAlert';
import { buildWhatsAppOptInUrl } from '@/Utils/whatsapp';

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

// Cuántos conductores se muestran de un vistazo en "Tu flota" antes de
// juntar el resto en el círculo "+N" (pedido explícito del usuario: más
// apretado que antes para que quepan más).
const FLEET_PREVIEW_LIMIT = 5;

// Mismos colores de estado que Ride/Request.vue (STATUS_STYLE): disponible en
// verde, en carrera en naranja, desconectado "quemado" (opacidad + gris).
const STATUS_RING = {
    available: 'ring-2 ring-arka-primary',
    busy: 'ring-2 ring-arka-warning',
    offline: 'ring-2 ring-arka-text-muted opacity-50 grayscale',
};
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

// Copia local de "Mi flota" (consideración agregada al alcance: que el
// estado de un conductor se actualice solo, sin recargar, cuando se
// activa/desactiva desde SU propio inicio) — se resincroniza cuando Inertia
// trae props nuevas (ej. el reload por geolocalización de acá abajo) y se
// puede mutar en vivo con lo que llega por WebSocket.
const fleetDriversLocal = ref([...(props.fleetDrivers ?? [])]);
watch(
    () => props.fleetDrivers,
    (value) => (fleetDriversLocal.value = [...(value ?? [])])
);

let fleetChannel = null;

onMounted(() => {
    // Mismo patrón que Directory/Index.vue: si el navegador comparte
    // ubicación, se recarga "Mi flota" y "Conductores cerca" ordenados por
    // cercanía.
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition((position) => {
            router.reload({
                data: { lat: position.coords.latitude, lng: position.coords.longitude },
                only: ['fleetDrivers', 'nearbyDrivers'],
                preserveScroll: true,
            });
        });
    }

    // Cuando un conductor de mi flota prende/apaga su disponibilidad (mismo
    // evento que ya escuchan Ride/Show.vue y Ride/Index.vue), se refleja acá
    // al toque — sin esto, "Mi flota" solo se hubiera actualizado recargando
    // la página entera.
    if (props.targetFleetId) {
        fleetChannel = window.Echo.private(`fleet.${props.targetFleetId}`);
        fleetChannel.listen('.driver.location.updated', (e) => {
            const driver = fleetDriversLocal.value.find((d) => d.user_id === e.driver_user_id);
            if (driver && driver.status !== 'busy') {
                driver.status = e.is_available ? 'available' : 'offline';
            }
        });

        // El conductor completó la carrera y queda libre de nuevo
        // (consideración agregada al alcance) — sin esto, seguía apareciendo
        // "en carrera" en "Mi flota" para siempre.
        fleetChannel.listen('.ride.completed', (e) => {
            playUpdateChime();
            const driver = fleetDriversLocal.value.find((d) => d.user_id === e.driver_user_id);
            if (driver) {
                driver.status = e.is_available ? 'available' : 'offline';
            }
        });
    }
});

onBeforeUnmount(() => {
    if (props.targetFleetId) {
        window.Echo.leave(`fleet.${props.targetFleetId}`);
    }
});

function addToFleet(driver) {
    router.post(
        route('fleet.invitations.store', props.targetFleetId),
        { driver_user_id: driver.user_id },
        { preserveScroll: true, onSuccess: () => (driver.already_invited = true) }
    );
}

// Puntos indicadores del carrusel de "Conductores cerca": el ancho de tarjeta
// + separación es fijo (w-40 = 160px, gap-3 = 12px), así que alcanza con la
// posición de scroll para saber cuál está al frente, sin librerías de carrusel.
const carouselEl = ref(null);
const activeCardIndex = ref(0);
function onCarouselScroll() {
    if (!carouselEl.value) return;
    activeCardIndex.value = Math.round(carouselEl.value.scrollLeft / (160 + 12));
}

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
    setTimeout(() => {
        if (newRequestAlert.value?.clientName === e.client_name) {
            newRequestAlert.value = null;
        }
    }, 12000);
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

    <AuthenticatedLayout>
        <template #header>
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
                        @update:available="handleAvailabilityChange"
                    />
                </div>
            </div>
        </template>

        <div class="py-12">
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
                        v-if="newRequestAlert"
                        class="p-4 bg-arka-primary text-arka-base rounded-arka flex items-center justify-between gap-4"
                    >
                        <div>
                            <p class="font-semibold">{{ newRequestAlert.isScheduled ? '¡Carrera programada nueva!' : '¡Nueva solicitud de carrera!' }}</p>
                            <p class="text-sm">
                                {{ newRequestAlert.clientName }} le
                                {{ newRequestAlert.isScheduled ? 'programó una carrera' : 'ofrece' }}
                                ${{ Number(newRequestAlert.price).toFixed(2) }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            <Link :href="route('rides.index')" class="px-3 py-1.5 rounded-arka bg-arka-base text-arka-primary-bright text-sm font-medium">
                                Ver
                            </Link>
                            <button type="button" class="text-arka-base/70 hover:text-arka-base" @click="newRequestAlert = null">
                                ✕
                            </button>
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

                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
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
                            <div class="text-center">
                                <p class="text-2xl font-semibold text-arka-primary-bright">${{ driverStats.earnings_this_month.toFixed(2) }}</p>
                                <p class="text-xs text-arka-text-muted">Ganancias este mes</p>
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
                                <svg class="h-6 w-6 mx-auto text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4 4 16 8-16 8 4-8-4-8Z" />
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
                         invite_code que ya existe con QR en Mi perfil de conductor. -->
                    <div v-if="inviteCode" class="p-4 bg-arka-card shadow rounded-arka flex items-center justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-arka-text font-medium">Comparta su código</p>
                            <p class="text-xs text-arka-text-muted">Un cliente nuevo puede agregarlo a su flota con este código.</p>
                            <p class="mt-1 text-lg font-mono tracking-widest text-arka-primary-bright">{{ inviteCode }}</p>
                        </div>
                        <SecondaryButton class="shrink-0" @click="copyInviteCode">
                            {{ codeCopied ? 'Copiado' : 'Copiar' }}
                        </SecondaryButton>
                    </div>

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
                            :href="route('rides.index')"
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

                <!-- Como cliente (consideración agregada al alcance: mockup del
                     cliente provisto por el usuario) — saludo, mi flota, accesos
                     grandes, conductores cerca y próximos viajes. -->
                <template v-else-if="fleetDrivers">
                    <!-- Rediseño pedido explícito del usuario (mockup provisto): saludo +
                         insignia de rol/plan, mismo criterio que ya usa el lado conductor
                         ("Conductor ✓ · Disponible"). -->
                    <!-- Pedido explícito del usuario: el saludo ya vive en el navbar
                         (roadmap de mejoras, sección 1) y la insignia "Cliente · Plan
                         X" se sacó de acá — esa info ya se ve en Mi perfil, mostrarla
                         también en el Inicio quedaba redundante. -->
                    <AdBannerSlider :banners="adBanners" />

                    <!-- Pedido explícito del usuario: "entran y no saben qué
                         hacer" — un cliente nuevo con la flota vacía necesita
                         que le digan, antes que nada, que el primer paso es
                         buscar conductores (no que lo adivine solo entre el
                         resto de la pantalla). Desaparece solo apenas tiene
                         al menos un conductor en su flota. -->
                    <div v-if="!fleetDriversLocal.length" class="p-4 bg-arka-primary/10 border border-arka-primary/30 rounded-arka">
                        <p class="text-sm font-semibold text-arka-primary-bright">Primero arme su flota</p>
                        <p class="mt-1 text-sm text-arka-text-muted">
                            Para pedir un viaje necesita al menos un conductor de confianza. Búsquelo por código de
                            invitación o elija uno del directorio público — después ya puede pedirle una carrera.
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <Link
                                :href="route('fleet.show', targetFleetId)"
                                class="px-3 py-1.5 rounded-arka bg-arka-primary text-arka-base text-xs font-semibold uppercase tracking-wide hover:bg-arka-primary-bright"
                            >
                                Buscar por código
                            </Link>
                            <Link
                                :href="route('directory.index')"
                                class="px-3 py-1.5 rounded-arka border border-arka-primary/40 text-arka-primary-bright text-xs font-semibold uppercase tracking-wide hover:bg-arka-primary/10"
                            >
                                Ver directorio público
                            </Link>
                        </div>
                    </div>

                    <!-- "Tu flota": resumen glanceable, no la lista completa — el
                         detalle por conductor (buscar, invitar, pedir carrera directo)
                         ya vive en Fleet/Show.vue, no tiene sentido duplicarlo acá. -->
                    <Link :href="route('fleet.show', targetFleetId)" class="block p-4 bg-arka-card shadow rounded-arka hover:bg-arka-card/70">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-arka-text font-medium">Tu flota</p>
                                <p class="text-sm text-arka-text-muted">
                                    {{ fleetDriversLocal.filter((d) => d.status === 'available').length }} disponible(s) ahora
                                </p>
                            </div>
                            <svg class="h-5 w-5 text-arka-text-muted shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" />
                            </svg>
                        </div>

                        <!-- Pedido explícito del usuario (roadmap de mejoras, sección 2):
                             foto, nombre, calificación y distancia — en fila horizontal,
                             con los datos debajo de cada foto y el nombre truncado si es
                             muy largo. Ajuste posterior (también pedido): más apretada
                             para que quepan más de un vistazo, y en vez de una línea de
                             texto aparte para "los que faltan" se usa un círculo "+N" al
                             final de la propia fila (como el resto de los avatares) —
                             flex en vez de grid fijo, así una flota chica no deja
                             columnas vacías y se ve prolija igual. -->
                        <div v-if="fleetDriversLocal.length" class="mt-3 flex flex-wrap gap-x-2.5 gap-y-3">
                            <div
                                v-for="driver in fleetDriversLocal.slice(0, FLEET_PREVIEW_LIMIT)"
                                :key="driver.user_id"
                                class="flex flex-col items-center text-center w-12"
                            >
                                <div class="rounded-full" :class="STATUS_RING[driver.status]">
                                    <UserAvatar :user="driver" size-class="h-10 w-10 text-xs" />
                                </div>
                                <p class="mt-1 w-full text-[11px] text-arka-text font-medium truncate">
                                    {{ driver.name.split(' ')[0] }}
                                </p>
                                <p class="w-full text-[10px] text-arka-text-muted truncate">
                                    <span v-if="driver.review_count > 0" class="text-arka-lime">★{{ driver.average_rating.toFixed(1) }}</span>
                                    <span v-else>Sin calif.</span>
                                    <span v-if="driver.distance_km != null">· {{ driver.distance_km.toFixed(1) }}km</span>
                                </p>
                            </div>

                            <!-- Círculo punteado con "+" (pedido explícito del usuario, con
                                 captura de referencia): mismo lugar que antes, pero ahora
                                 siempre visible como llamado a la acción — si sobran
                                 conductores muestra cuántos faltan por ver, y si la flota
                                 todavía tiene lugar invita directamente a agregar más. -->
                            <div class="flex flex-col items-center text-center w-12">
                                <div
                                    class="h-10 w-10 rounded-full border-2 border-dashed flex items-center justify-center text-xs font-semibold"
                                    :class="fleetDriversLocal.length > FLEET_PREVIEW_LIMIT
                                        ? 'border-arka-text-muted/40 text-arka-text-muted'
                                        : 'border-arka-primary/60 text-arka-primary-bright'"
                                >
                                    <span v-if="fleetDriversLocal.length > FLEET_PREVIEW_LIMIT">
                                        +{{ fleetDriversLocal.length - FLEET_PREVIEW_LIMIT }}
                                    </span>
                                    <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" d="M12 5v14M5 12h14" />
                                    </svg>
                                </div>
                                <p class="mt-1 w-full text-[11px] text-arka-text-muted truncate">
                                    {{ fleetDriversLocal.length > FLEET_PREVIEW_LIMIT ? 'Ver todos' : 'Agregar' }}
                                </p>
                            </div>
                        </div>
                        <p v-else class="mt-2 text-sm text-arka-text-muted">
                            Todavía no tiene conductores — toque para armar su flota.
                        </p>
                    </Link>

                    <!-- Dos accesos grandes (pedido explícito del usuario: "Pedir
                         carrera" para ahora mismo, "Programar carrera" para elegir
                         fecha y hora) — antes eran dos ítems más del grid chico de
                         "Solicitá un viaje", ahora son la acción principal del inicio. -->
                    <div v-if="hasRoute('ride-requests.create')" class="grid grid-cols-2 gap-3">
                        <Link
                            :href="route('ride-requests.create')"
                            class="p-4 bg-arka-primary/15 border border-arka-primary/30 rounded-arka hover:bg-arka-primary/20"
                        >
                            <svg class="h-7 w-7 text-arka-primary-bright" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l2.5-6.5A2 2 0 0 1 8.35 8.2h7.3a2 2 0 0 1 1.85 1.3L20 16" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16h16v2.5a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1V17H7v1.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V16Z" />
                            </svg>
                            <p class="mt-2 font-medium text-arka-primary-bright">Pedir carrera</p>
                            <p class="text-xs text-arka-text-muted">Viaje inmediato</p>
                        </Link>
                        <Link
                            :href="route('ride-requests.create', { programar: 1 })"
                            class="p-4 bg-arka-card border border-arka-text-muted/20 rounded-arka hover:bg-arka-card/70"
                        >
                            <svg class="h-7 w-7 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3.5" y="4.5" width="17" height="16" rx="2" stroke-linecap="round" stroke-linejoin="round" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 9.5h17M8 3v3M16 3v3" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 13.5h2M8 17h2M14 13.5h2M14 17h2" />
                            </svg>
                            <p class="mt-2 font-medium text-arka-text">Programar carrera</p>
                            <p class="text-xs text-arka-text-muted">Elegí fecha y hora</p>
                        </Link>
                    </div>

                    <!-- Más opciones -->
                    <div class="space-y-2">
                        <h4 class="text-sm font-medium text-arka-text-muted uppercase tracking-wide">Más opciones</h4>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <Link
                                v-if="hasRoute('express-routes.index')"
                                :href="route('express-routes.index')"
                                class="p-3 bg-arka-card shadow rounded-arka hover:bg-arka-card/70"
                            >
                                <svg class="h-6 w-6 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3.5" y="5" width="17" height="15" rx="2" stroke-linecap="round" stroke-linejoin="round" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 10h17M8 3v4M16 3v4" />
                                </svg>
                                <p class="mt-2 text-sm text-arka-text font-medium">Expresos</p>
                                <p class="text-xs text-arka-text-muted">Rutas fijas</p>
                            </Link>
                            <!-- Bug propio de esta sesión (roadmap de mejoras, sección 3: "el
                                 cliente no debe ver opciones para publicar, debe ver el
                                 catálogo"): esta tarjeta apuntaba a `van-trips.index`, la
                                 pantalla de gestión del CONDUCTOR ("Mis rutas y turismo") — un
                                 cliente la veía siempre vacía. Acá va `van-trips.browse`, el
                                 catálogo de viajes publicados, que es lo que corresponde. -->
                            <Link
                                v-if="hasRoute('van-trips.browse')"
                                :href="route('van-trips.browse')"
                                class="p-3 bg-arka-card shadow rounded-arka hover:bg-arka-card/70"
                            >
                                <svg class="h-6 w-6 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="2.5" y="8" width="19" height="9" rx="2" stroke-linecap="round" stroke-linejoin="round" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12h19M6 17v2M18 17v2" />
                                </svg>
                                <p class="mt-2 text-sm text-arka-text font-medium">Rutas y Turismo</p>
                            </Link>
                            <Link
                                v-if="hasRoute('ride-requests.create')"
                                :href="route('ride-requests.create')"
                                class="p-3 bg-arka-card shadow rounded-arka hover:bg-arka-card/70"
                            >
                                <svg class="h-6 w-6 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m12 3 2.6 5.6 6.1.7-4.5 4.2 1.2 6-5.4-3-5.4 3 1.2-6-4.5-4.2 6.1-.7L12 3Z" />
                                </svg>
                                <p class="mt-2 text-sm text-arka-text font-medium">Mis rutas</p>
                                <p class="text-xs text-arka-text-muted">favoritas</p>
                            </Link>
                            <Link
                                v-if="hasRoute('coupons.index')"
                                :href="route('coupons.index')"
                                class="p-3 bg-arka-card shadow rounded-arka hover:bg-arka-card/70"
                            >
                                <svg class="h-6 w-6 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 8.5A2.5 2.5 0 0 1 6.5 6h11A2.5 2.5 0 0 1 20 8.5v1a2 2 0 0 0 0 4v1A2.5 2.5 0 0 1 17.5 17h-11A2.5 2.5 0 0 1 4 14.5v-1a2 2 0 0 0 0-4v-1Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.5 6.5v11" stroke-dasharray="2 2" />
                                </svg>
                                <p class="mt-2 text-sm text-arka-text font-medium">Cupones</p>
                                <p class="text-xs text-arka-text-muted">y beneficios</p>
                            </Link>
                        </div>
                    </div>

                    <!-- Conductores cerca -->
                    <div v-if="nearbyDrivers && nearbyDrivers.length" class="space-y-2">
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-medium text-arka-text-muted uppercase tracking-wide">Conductores cerca</h4>
                            <Link :href="route('directory.index')" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                                Ver todos
                            </Link>
                        </div>

                        <div ref="carouselEl" class="flex gap-3 overflow-x-auto snap-x snap-mandatory pb-1" @scroll="onCarouselScroll">
                            <div
                                v-for="driver in nearbyDrivers"
                                :key="driver.user_id"
                                class="w-40 shrink-0 snap-start p-3 bg-arka-card shadow rounded-arka"
                            >
                                <div class="flex items-center gap-2">
                                    <UserAvatar :user="driver" size-class="h-10 w-10 text-sm" />
                                    <div class="min-w-0">
                                        <Link :href="route('profiles.show', driver.user_id)" class="block text-arka-text font-medium truncate hover:text-arka-primary-bright">
                                            {{ driver.name }}
                                        </Link>
                                        <span v-if="driver.review_count > 0" class="text-xs text-arka-lime">★ {{ driver.average_rating.toFixed(1) }}</span>
                                    </div>
                                </div>
                                <p v-if="driver.distance_km != null" class="mt-2 text-xs text-arka-text-muted">
                                    A {{ driver.distance_km.toFixed(1) }} km de ti
                                </p>
                                <p class="mt-0.5 text-xs text-arka-primary-bright">● Disponible</p>
                                <Link
                                    :href="route('ride-requests.create', { flota: targetFleetId, conductor: driver.user_id })"
                                    class="mt-2 block text-xs text-arka-primary hover:text-arka-primary-bright font-medium text-center"
                                >
                                    Pedir carrera
                                </Link>
                                <PrimaryButton v-if="!driver.already_invited" class="mt-2 w-full justify-center text-xs" @click="addToFleet(driver)">
                                    Agregar
                                </PrimaryButton>
                                <p v-else class="mt-2 text-xs text-arka-text-muted">Invitación enviada</p>
                            </div>
                        </div>

                        <div class="flex justify-center gap-1.5">
                            <span
                                v-for="(driver, index) in nearbyDrivers"
                                :key="driver.user_id"
                                class="h-1.5 w-1.5 rounded-full"
                                :class="index === activeCardIndex ? 'bg-arka-primary' : 'bg-arka-text-muted/30'"
                            />
                        </div>
                    </div>

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
                            :href="route('rides.index')"
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

                    <!-- Seguridad siempre (pedido explícito del usuario, mockup provisto):
                         el botón SOS de verdad depende de una carrera en curso puntual
                         (SosAlertController::store() necesita el conductor/vehículo de
                         ESA carrera) — acá, sin una carrera activa, lleva a administrar
                         los contactos de confianza que reciben esa alerta cuando sí la
                         hay, en vez de simular un botón que no podría hacer nada. -->
                    <Link
                        v-if="hasRoute('trusted-contacts.index')"
                        :href="route('trusted-contacts.index')"
                        class="p-4 bg-arka-card shadow rounded-arka flex items-center gap-4 hover:bg-arka-card/70"
                    >
                        <div class="h-12 w-12 rounded-full bg-arka-primary/15 flex items-center justify-center shrink-0">
                            <svg class="h-6 w-6 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m12 3 8 3.5v5.2c0 4.4-3 7.6-8 9.3-5-1.7-8-4.9-8-9.3V6.5L12 3Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4" />
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-arka-text font-medium">Seguridad siempre</p>
                            <p class="text-xs text-arka-text-muted">Su seguridad es primero — mantenga al día sus contactos de confianza.</p>
                        </div>
                        <span class="shrink-0 px-3 py-1.5 rounded-full bg-arka-primary text-arka-base text-xs font-bold">SOS</span>
                    </Link>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
