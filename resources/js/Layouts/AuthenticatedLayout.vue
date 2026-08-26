<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AdminNavIcon from '@/Components/AdminNavIcon.vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import BottomSheet from '@/Components/BottomSheet.vue';
import Modal from '@/Components/Modal.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import IncomingRideRequestModal from '@/Components/IncomingRideRequestModal.vue';
import OnboardingTour from '@/Components/OnboardingTour.vue';
import PermissionsPrompt from '@/Components/PermissionsPrompt.vue';
import HelpTip from '@/Components/HelpTip.vue';
import SessionDataUsage from '@/Components/SessionDataUsage.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { pushSupported, subscribeToPush } from '@/push.js';
import { canInstallApp, installApp } from '@/pwaInstall.js';
import { playAttentionAlert, playCabinChime, playIncomingRideAlert, playUpdateChime, unlockAudioContext } from '@/Utils/liveAlert';
import { dismissIncomingRideRequest, pushIncomingRideRequest } from '@/Utils/incomingRideRequest';
import { clientOnboardingSteps, driverOnboardingSteps } from '@/Utils/onboardingSteps';
import { confirmDialog } from '@/Utils/confirmDialog';
import { resetStartupSplash } from '@/Utils/startupSplash';
import { ADMIN_NAV_GROUPS as adminNavGroups } from '@/Utils/adminNav';

// Pedido explícito del usuario (documento formal de ajuste UX): en Inicio
// del pasajero, el mapa debe llegar hasta arriba de la pantalla con la nav
// flotando ENCIMA — la primera vez que se probó esto (misma idea, sin este
// documento) se implementó con `position: absolute`, que se desplaza junto
// con el scroll de la página y por eso terminaba saliéndose de vista con
// cualquier scroll chico (bug real reportado, con captura). Esta vez es
// `fixed` de verdad: no depende de dónde esté la página al montarse ni se
// mueve con el scroll. `false` por defecto — ninguna otra pantalla cambia.
const props = defineProps({
    transparentNav: {
        type: Boolean,
        default: false,
    },
    // Pedido explícito del usuario (carrera en curso del cliente, mapa fijo a
    // toda la pantalla estilo Uber/DiDi): sin esto, la barra de navegación
    // inferior (fixed, z-30) quedaba tapando la barra de acciones fija que
    // usan Ride/Show.vue — las dos "fixed bottom-0" compitiendo por el mismo
    // lugar. `false` por defecto — ninguna otra pantalla cambia.
    hideBottomNav: {
        type: Boolean,
        default: false,
    },
});

// Menú de accesos rápidos que abre el botón central flotante de la barra
// inferior (preferencia de diseño: bottom sheet, no modal lateral).
const showingQuickActions = ref(false);

// Panel de ayuda (referencia de diseño: el ícono "?" de Google), visible en
// el header tanto en escritorio como en móvil.
const showingHelp = ref(false);

// Recorrido guiado por rol, una sola vez (pedido explícito del usuario) — ver
// Components/OnboardingTour.vue y Utils/onboardingSteps.js.
const showingOnboarding = ref(false);
const onboardingSteps = computed(() => (showDriverNav.value ? driverOnboardingSteps : clientOnboardingSteps));

// Cualquier cierre (terminarlo, "Saltar guía de uso", click afuera, Escape)
// cuenta como "ya lo vio" — no distinguimos el motivo. Volver a abrirlo a
// propósito desde Ayuda (openOnboardingAgain) no pasa por acá.
function completeOnboarding() {
    showingOnboarding.value = false;
    router.post(route('onboarding.complete'), {}, { preserveScroll: true, preserveState: true });
}

function openOnboardingAgain() {
    showingHelp.value = false;
    showingOnboarding.value = true;
}

// route().has(...) evita que la navegación se rompa si algún módulo
// (por ejemplo Flota) todavía no tiene sus rutas registradas.
const hasRoute = (name) => route().has(name);

// Respetar el rol activo (sección 3.1: cada cuenta es cliente O conductor,
// nunca las dos — ver App\Models\User::isClient()). El rol de cliente es el
// implícito por defecto (sin alta explícita); activar el de conductor lo
// reemplaza, no lo suma.
// El admin no es cliente ni conductor, solo administra la plataforma (así se
// documentó también en el usuario de demo, DemoDataSeeder.php) — no tiene
// sentido mostrarle "Mis Flotas" o "Pedir una carrera" solo porque técnicamente
// no tiene flota ni perfil de conductor armado.
const isAdmin = computed(() => usePage().props.auth.user.is_admin);
const showClientNav = computed(() => usePage().props.auth.isClient);
const showDriverNav = computed(() => !isAdmin.value && usePage().props.auth.isDriver);
const showCooperativeNav = computed(() => !isAdmin.value && usePage().props.auth.isCooperative);

// Notificaciones push (sección 9.2 y 9.5): se activan a pedido del usuario
// desde el menú de cuenta, nunca con un permiso pedido de entrada.
async function activatePushNotifications() {
    if (!pushSupported()) {
        alert('Su navegador no soporta notificaciones push.');
        return;
    }

    // Pedido explícito del usuario: mismo gesto real, mismo momento para
    // desbloquear el sonido de los demás avisos en vivo de la app (ver
    // PermissionsPrompt.vue::enableNotifications()).
    unlockAudioContext();

    const ok = await subscribeToPush(usePage().props.vapidPublicKey);
    alert(ok ? 'Notificaciones activadas.' : 'No se pudo activar — revise los permisos del navegador.');
}

// Instalar como app (pedido explícito del usuario: "un botón que guarde un
// acceso directo en su teléfono o computador") — el botón solo aparece si
// el navegador ya avisó que se puede instalar (ver pwaInstall.js); si ya
// está instalada o el navegador no lo soporta, no se muestra nada.
async function installAppNow() {
    const accepted = await installApp();
    if (accepted) alert('¡Listo! Ya quedó instalada.');
}

// "Pasarme a cliente" (pedido explícito del usuario: un botón en el perfil
// para cambiar de rol) — pausa el perfil de conductor sin borrar nada
// (DriverProfileController::deactivate()); se puede reactivar después con
// todos los datos intactos. Confirmación primero porque tiene consecuencias
// reales: deja de recibir carreras de inmediato.
async function switchToClient() {
    if (!(await confirmDialog('¿Pasar a cliente? Su perfil de conductor queda guardado — puede volver a activarlo cuando quiera.'))) return;
    router.post(route('driver.profile.deactivate'));
}

// Mismo set de accesos rápidos que el bottom sheet de móvil, para que en
// escritorio también estén a un clic desde el ícono de grilla del header
// (referencia de diseño: el selector de apps "de los puntos" de Google).
// "Mi perfil de conductor" no lleva clientOnly/driverOnly: es la puerta de
// entrada para activar el rol de conductor (o para editarlo, si ya lo es) —
// se muestra para cualquier cuenta que no sea admin. Pedido explícito del
// usuario ("pasarme a conductor, fácil"): antes se ocultaba si el cliente ya
// tenía una flota propia (sección 3.1, versión vieja de la regla) — ya no,
// ahora activarse como conductor pausa la flota en vez de bloquearse (ver
// DriverProfileController::deactivate()/reactivate()).
// El admin tiene su propio acceso directo y prominente (pastilla de escritorio,
// tab de móvil) así que no repetimos "Panel admin" acá también.
const canBecomeOrIsDriver = computed(() => !isAdmin.value);
// `help` (pedido explícito del usuario: ícono "?" contextual en cada módulo,
// alternativa que él mismo ofreció al ver que la guía de bienvenida siempre
// aparece en el mismo lugar) — qué hace cada uno y con qué otro se relaciona,
// en una frase. Mismo texto se repite a mano en el bottom sheet de abajo,
// que ya no reutiliza este array (divergencia que ya existía antes de esto).
const quickLinks = computed(() =>
    [
        {
            route: 'cooperative.dashboard',
            label: 'Panel de cooperativa',
            cooperativeOnly: true,
            help: 'Revise solicitudes, conductores vinculados y el estado operativo de su cooperativa.',
        },
        {
            route: 'cooperative.drivers.index',
            label: 'Conductores de la cooperativa',
            cooperativeOnly: true,
            help: 'Invite, suspenda o retire conductores. Cada conductor debe aceptar su vínculo.',
        },
        {
            route: 'cooperatives.index',
            label: 'Cooperativas verificadas',
            clientOnly: true,
            help: 'Busque organizaciones aprobadas y agréguelas a su red de confianza.',
        },
        {
            route: 'ride-requests.create',
            label: 'Pedir una carrera',
            clientOnly: true,
            help: 'Pida un viaje ahora o programado, a toda su flota o a un conductor puntual. Arme primero Mis Flotas para tener a quién pedirle.',
        },
        {
            route: 'driver.profile.edit',
            label: 'Mi perfil de conductor',
            hideIfCommittedClient: true,
            help: 'Complete los datos de su vehículo para activar el perfil y empezar a recibir carreras.',
        },
        {
            route: 'driver.invitations.index',
            label: 'Mis clientes de confianza',
            driverOnly: true,
            help: 'Acepte invitaciones de flota y administre a sus clientes. De ahí le llegan las solicitudes que ve en Carreras.',
        },
        {
            route: 'directory.index',
            label: 'Directorio de conductores',
            clientOnly: true,
            help: 'Conductores públicos verificados, para cuando nadie de su flota está disponible. Desde acá también puede invitarlos a Mis Flotas.',
        },
        {
            route: 'express-routes.index',
            label: 'Mis Expresos',
            clientOnly: true,
            help: 'Ruta fija y recurrente (ej. su viaje diario al trabajo) en vez de pedir Pedir una carrera cada vez.',
        },
        {
            route: 'express-routes.available',
            label: 'Expresos disponibles',
            driverOnly: true,
            help: 'Postúlese a rutas fijas que publican sus clientes — solo ve las de las flotas de Mis clientes de confianza.',
        },
        {
            route: 'driver.plan.edit',
            label: 'Mi plan de conductor',
            driverOnly: true,
            help: 'Su plan vigente y sus beneficios — algunos, como el directorio público, también dependen de su medalla por puntos.',
        },
        {
            route: 'client.plan.edit',
            label: 'Mi plan de cliente',
            clientOnly: true,
            help: 'Su plan vigente y sus beneficios como cliente.',
        },
        {
            route: 'cooperative.plan.edit',
            label: 'Mi plan de cooperativa',
            cooperativeOnly: true,
            help: 'Su plan vigente, cuántas unidades puede afiliar, y el descuento que reciben sus conductores en su propio plan mientras estén afiliados.',
        },
        {
            route: 'trusted-contacts.index',
            label: 'Contactos de confianza',
            help: 'A quién avisa el botón SOS si lo activa durante un viaje.',
        },
        {
            route: 'support.index',
            label: 'Centro de ayuda',
            help: 'Preguntas frecuentes según su rol, y "Hablar con soporte" si no encuentra lo que necesita.',
        },
        {
            route: 'coupons.index',
            label: 'Cupones y beneficios',
            help: 'Promos de comercios aliados, separadas para clientes y para conductores.',
        },
        {
            route: 'van-trips.index',
            label: 'Mis rutas y turismo',
            driverOnly: true,
            help: 'Publique salidas programadas de ruta fija, que los clientes reservan por asiento.',
        },
        {
            route: 'van-trips.browse',
            label: 'Rutas y Turismo',
            clientOnly: true,
            help: 'Explore y reserve un asiento en las salidas programadas que publican los conductores.',
        },
        {
            route: 'survey.show',
            label: 'Encuesta',
            help: 'Cuentanos tu experiencia con Arka01 — menos de 2 minutos, sin necesidad de cuenta.',
        },
    ].filter(
        (item) =>
            hasRoute(item.route) &&
            (!item.clientOnly || showClientNav.value) &&
            (!item.driverOnly || showDriverNav.value) &&
            (!item.cooperativeOnly || showCooperativeNav.value) &&
            (!showCooperativeNav.value || item.cooperativeOnly) &&
            (!item.hideIfCommittedClient || canBecomeOrIsDriver.value) &&
            // Pedido explícito del usuario: "permiteme en el modulo de
            // sistema de habilitar o no estas opciones del menu" — ver
            // Admin\SystemController::updateQuickLinks(), compartido en
            // cualquier pantalla vía HandleInertiaRequests::share().
            !(usePage().props.disabledQuickLinks ?? []).includes(item.route)
    )
);

// Se abre solo la primera vez que corresponde (pedido explícito del usuario)
// — no para el admin, cuya nav es otra por completo. `onboarding_completed_at`
// viaja ya en `auth.user` sin nada extra (HandleInertiaRequests comparte el
// modelo completo).
onMounted(() => {
    if ((showClientNav.value || showDriverNav.value) && !usePage().props.auth.user.onboarding_completed_at) {
        showingOnboarding.value = true;
    }
});

// Carrera entrante (pedido explícito del usuario): tiene que llegarle al
// conductor sin importar en qué pantalla esté, con un aviso más fuerte que
// el resto de las notificaciones y ocupando media pantalla — por eso se
// suscribe acá, en el layout que envuelve TODAS las pantallas autenticadas,
// en vez de duplicarlo en cada página. Ride/Index.vue y Dashboard.vue ya NO
// escuchan este evento en su canal personal (ver sus propios comentarios)
// para no sonar/mostrar todo dos veces.
let incomingRideChannel = null;
let clientRideChannel = null;
let adminChannel = null;
let clientRideAlertTimer = null;
const clientRideAlert = ref(null);

// Pedido explícito del usuario ("ayudame a ver la trazabilidad en el panel
// administrativo... una alerta") — canal `admins` (routes/channels.php),
// para que cualquier admin conectado se entere de un ticket nuevo aunque
// esté en otra pantalla, no solo el que tenga /admin/soporte abierto.
let adminSupportAlertTimer = null;
const adminSupportAlert = ref(null);

function showAdminSupportAlert(message, ticketId) {
    playUpdateChime();
    adminSupportAlert.value = { message, ticketId };
    clearTimeout(adminSupportAlertTimer);
    adminSupportAlertTimer = setTimeout(() => (adminSupportAlert.value = null), 12000);
}

function showClientRideAlert(message, rideId, sound = 'update') {
    // En Carreras y en su detalle ya existen avisos específicos con más
    // contexto. El aviso global cubre Dashboard, Flotas, Perfil y cualquier
    // otra pantalla sin duplicar sonido cuando el cliente ya está mirando.
    if (route().current('rides.show')) return;

    if (sound === 'attention') playAttentionAlert();
    else if (sound === 'cabin') playCabinChime();
    else playUpdateChime();

    clientRideAlert.value = { message, rideId };
    clearTimeout(clientRideAlertTimer);
    clientRideAlertTimer = setTimeout(() => (clientRideAlert.value = null), 9000);
}

onMounted(() => {
    const userId = usePage().props.auth.user.id;

    if (isAdmin.value) {
        adminChannel = window.Echo.private('admins');
        adminChannel.listen('.support.ticket.escalated', (e) => {
            if (route().current('admin.support-tickets.show', e.ticket_id)) return;
            showAdminSupportAlert(`🆘 ${e.user_name} pidió hablar con soporte.`, e.ticket_id);
        });
    }

    if (showDriverNav.value) {
        incomingRideChannel = window.Echo.private(`App.Models.User.${userId}`);
        incomingRideChannel.listen('.ride-request.created', (e) => {
            playIncomingRideAlert();
            pushIncomingRideRequest(e);
        });
        incomingRideChannel.listen('.ride-request.cancelled', (e) => {
            dismissIncomingRideRequest(e.ride_request_id);
        });
    }

    if (showClientNav.value) {
        clientRideChannel = window.Echo.private(`App.Models.User.${userId}`);
        clientRideChannel.listen('.ride-request.created', (e) => {
            if (e.cooperative_id && e.driver_name && !route().current('rides.index')) {
                showClientRideAlert(`🚕 ${e.cooperative_name || 'La cooperativa'} asignó a ${e.driver_name}.`, null, 'attention');
            }
        });
        clientRideChannel.listen('.ride-request.accepted', (e) => showClientRideAlert(`🚗 ${e.driver_name} aceptó su carrera.`, e.ride_id));
        clientRideChannel.listen('.ride.started', (e) => showClientRideAlert('🚗 Su conductor ya va en camino.', e.ride_id, 'cabin'));
        clientRideChannel.listen('.ride.arrived', (e) => showClientRideAlert('📍 Su conductor llegó y lo está esperando.', e.ride_id, 'cabin'));
        clientRideChannel.listen('.ride.picked_up', (e) => showClientRideAlert('▶️ Su viaje comenzó hacia el destino.', e.ride_id, 'cabin'));
        clientRideChannel.listen('.ride.completed', (e) => showClientRideAlert('✅ La carrera se completó. Revise el pago y la calificación.', e.ride_id, 'cabin'));
        clientRideChannel.listen('.ride.cancelled', (e) => showClientRideAlert('⚠️ El conductor canceló la carrera.', e.ride_id, 'attention'));
        clientRideChannel.listen('.ride.reschedule-responded', (e) => showClientRideAlert(
            e.confirmed ? '📅 El conductor confirmó el nuevo horario.' : '⚠️ El conductor rechazó el nuevo horario.',
            e.ride_id,
            'attention'
        ));
    }
});

onBeforeUnmount(() => {
    if (incomingRideChannel) {
        incomingRideChannel.stopListening('.ride-request.created');
        incomingRideChannel.stopListening('.ride-request.cancelled');
    }
    if (clientRideChannel) {
        window.Echo.leave(`App.Models.User.${usePage().props.auth.user.id}`);
    }
    if (adminChannel) {
        window.Echo.leave('admins');
    }
    clearTimeout(clientRideAlertTimer);
    clearTimeout(adminSupportAlertTimer);
});
</script>

<template>
    <div class="arka-app-background min-h-screen">
        <button
            v-if="clientRideAlert"
            type="button"
            class="fixed top-4 left-1/2 -translate-x-1/2 z-[1700] w-[calc(100%-2rem)] max-w-md p-4 rounded-arka bg-arka-card border border-arka-primary/50 shadow-2xl text-left"
            @click="router.visit(clientRideAlert.rideId ? route('rides.show', clientRideAlert.rideId) : route('rides.index'))"
        >
            <span class="block text-sm font-semibold text-arka-text">Cambio en su carrera</span>
            <span class="block mt-1 text-sm text-arka-text-muted">{{ clientRideAlert.message }}</span>
            <span class="block mt-2 text-xs font-medium text-arka-primary">Tocar para abrir carreras</span>
        </button>
        <button
            v-if="adminSupportAlert"
            type="button"
            class="fixed top-4 left-1/2 -translate-x-1/2 z-[1700] w-[calc(100%-2rem)] max-w-md p-4 rounded-arka bg-arka-card border border-red-500/50 shadow-2xl text-left"
            @click="router.visit(route('admin.support-tickets.show', adminSupportAlert.ticketId))"
        >
            <span class="block text-sm font-semibold text-arka-text">Soporte</span>
            <span class="block mt-1 text-sm text-arka-text-muted">{{ adminSupportAlert.message }}</span>
            <span class="block mt-2 text-xs font-medium text-red-400">Tocar para atender</span>
        </button>
        <!-- `transparentNav`: flota fija (no `absolute`, ver comentario junto
             a la prop) sobre el mapa de Inicio del pasajero, SOLO en móvil —
             de `sm:` para arriba vuelve a ser la barra sólida de siempre. -->
        <nav
            :class="
                transparentNav
                    ? 'fixed top-0 inset-x-0 z-50 bg-arka-base/65 backdrop-blur-md border-b border-white/5 sm:static sm:bg-arka-card sm:backdrop-blur-none sm:border-arka-text-muted/10'
                    : 'bg-arka-card border-b border-arka-text-muted/10'
            "
        >
            <!-- Primary Navigation Menu -->
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Header en 3 zonas (logo / nav centrada / cuenta), no el típico
                     "todo pegado a la izquierda con hueco muerto a la derecha". -->
                <div class="grid grid-cols-[auto_1fr_auto] items-center h-16 gap-3">
                    <!-- Logo: el mismo click a Inicio, así que la nav de al lado no
                         necesita repetir un ítem "Inicio" (antes duplicaba función y
                         ocupaba espacio de más). -->
                    <Link :href="route('dashboard')" class="shrink-0 flex items-center" title="Inicio">
                        <ApplicationLogo />
                    </Link>

                    <!-- Navegación de escritorio: agrupada en una sola "pastilla" con
                         ícono + texto (coherente con el lenguaje visual del resto de la
                         app), centrada para aprovechar el ancho del header. -->
                    <div class="hidden sm:flex justify-center">
                        <div class="flex items-center gap-1 bg-arka-base/60 rounded-full p-1">
                            <Link
                                v-if="hasRoute('cooperative.dashboard') && showCooperativeNav"
                                :href="route('cooperative.dashboard')"
                                class="flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition"
                                :class="route().current('cooperative.*') ? 'bg-arka-primary/15 text-arka-primary-bright' : 'text-arka-text-muted hover:text-arka-text'"
                            >
                                Cooperativa
                            </Link>
                            <Link
                                v-if="hasRoute('fleet.index') && showClientNav"
                                :href="route('fleet.index')"
                                class="flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition"
                                :class="route().current('fleet.*') ? 'bg-arka-primary/15 text-arka-primary-bright' : 'text-arka-text-muted hover:text-arka-text'"
                            >
                                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="8" r="3.25" stroke-linecap="round" stroke-linejoin="round" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 20a7.5 7.5 0 0 1 15 0" />
                                    <circle cx="5.5" cy="12.5" r="2" stroke-linecap="round" stroke-linejoin="round" />
                                    <circle cx="18.5" cy="12.5" r="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                Mis Flotas
                            </Link>
                            <Link
                                v-if="hasRoute('driver.invitations.index') && showDriverNav"
                                :href="route('driver.invitations.index')"
                                class="flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition"
                                :class="route().current('driver.*') ? 'bg-arka-primary/15 text-arka-primary-bright' : 'text-arka-text-muted hover:text-arka-text'"
                            >
                                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="9" cy="9" r="3" stroke-linecap="round" stroke-linejoin="round" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 19a5.5 5.5 0 0 1 11 0" />
                                    <circle cx="17" cy="9" r="2.4" stroke-linecap="round" stroke-linejoin="round" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.5 13.5c2.4 0 4.5 1.9 5 5" />
                                </svg>
                                Mis clientes
                            </Link>
                            <Link
                                v-if="hasRoute('rides.index') && !isAdmin && !showCooperativeNav"
                                :href="route('rides.index')"
                                class="flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition"
                                :class="
                                    route().current('rides.*') || route().current('ride-requests.*')
                                        ? 'bg-arka-primary/15 text-arka-primary-bright'
                                        : 'text-arka-text-muted hover:text-arka-text'
                                "
                            >
                                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M4 16l2.5-6.5A2 2 0 0 1 8.35 8.2h7.3a2 2 0 0 1 1.85 1.3L20 16"
                                    />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16h16v2.5a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1V17H7v1.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V16Z" />
                                    <circle cx="7.5" cy="16" r="1" />
                                    <circle cx="16.5" cy="16" r="1" />
                                </svg>
                                Carreras
                            </Link>

                            <!-- Acceso destacado del admin (sección 9.5-C): reemplaza toda la
                                 nav de cliente/conductor, que no le sirve de nada a una cuenta
                                 que "solo administra". Antes quedaba escondido en la grilla de
                                 puntos, mezclado con accesos que no eran suyos. -->
                            <Link
                                v-if="isAdmin && hasRoute('admin.subscriptions.index')"
                                :href="route('admin.subscriptions.index')"
                                class="flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition"
                                :class="
                                    route().current('admin.*')
                                        ? 'bg-arka-warning/15 text-arka-warning'
                                        : 'text-arka-text-muted hover:text-arka-text'
                                "
                            >
                                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m12 3 8 3.5v5.2c0 4.4-3 7.6-8 9.3-5-1.7-8-4.9-8-9.3V6.5L12 3Z" />
                                </svg>
                                Panel admin
                            </Link>
                        </div>
                    </div>

                    <!-- Íconos de cuenta (referencia de diseño: header de Google) — visibles
                         en escritorio Y en móvil, donde reemplazan al viejo menú de
                         hamburguesa: búsqueda, ayuda, accesos rápidos y avatar de cuenta. -->
                    <div class="flex items-center justify-end gap-0.5">
                        <!-- Mantiene el histórico local del conductor sin
                             mostrar ningún indicador ni llamar al servidor. -->
                        <SessionDataUsage
                            v-if="showDriverNav"
                            :visible="false"
                            :user-id="$page.props.auth.user.id"
                        />

                        <!-- Buscar: acceso directo al directorio de conductores (herramienta
                             de cliente — buscar a quién invitar a la flota). -->
                        <Link
                            v-if="hasRoute('directory.index') && showClientNav"
                            :href="route('directory.index')"
                            class="p-2 rounded-full text-arka-text-muted hover:text-arka-text hover:bg-arka-base transition"
                            title="Buscar conductores"
                        >
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="11" cy="11" r="7" stroke-linecap="round" stroke-linejoin="round" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="m20 20-3.5-3.5" />
                            </svg>
                        </Link>

                        <!-- Ayuda: panel con lo básico para orientarse. -->
                        <button
                            type="button"
                            @click="showingHelp = true"
                            class="p-2 rounded-full text-arka-text-muted hover:text-arka-text hover:bg-arka-base transition"
                            title="Ayuda"
                        >
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="9" stroke-linecap="round" stroke-linejoin="round" />
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M9.5 9.2a2.5 2.5 0 1 1 3.5 2.3c-.8.4-1 .9-1 1.6M12 16.5h.01"
                                />
                            </svg>
                        </button>

                        <!-- Accesos rápidos en grilla — solo en escritorio: en móvil esta
                             misma lista ya la abre el botón central (FAB) de la barra
                             inferior, así que repetirla acá arriba era la misma opción
                             dos veces (confuso, no menos clics). -->
                        <div class="hidden sm:block">
                            <Dropdown align="right" width="72">
                                <template #trigger>
                                    <button
                                        type="button"
                                        class="p-2 rounded-full text-arka-text-muted hover:text-arka-text hover:bg-arka-base transition"
                                        title="Accesos rápidos"
                                    >
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
                                            <circle cx="5" cy="5" r="1.6" />
                                            <circle cx="12" cy="5" r="1.6" />
                                            <circle cx="19" cy="5" r="1.6" />
                                            <circle cx="5" cy="12" r="1.6" />
                                            <circle cx="12" cy="12" r="1.6" />
                                            <circle cx="19" cy="12" r="1.6" />
                                            <circle cx="5" cy="19" r="1.6" />
                                            <circle cx="12" cy="19" r="1.6" />
                                            <circle cx="19" cy="19" r="1.6" />
                                        </svg>
                                    </button>
                                </template>

                                <template #content>
                                    <div class="p-4">
                                        <p class="text-xs text-arka-text-muted mb-3">Accesos rápidos</p>
                                        <div class="grid grid-cols-3 gap-3">
                                            <div v-for="item in quickLinks" :key="item.route" class="relative">
                                                <Link
                                                    :href="route(item.route)"
                                                    class="flex flex-col items-center gap-1.5 p-2 rounded-arka hover:bg-arka-base text-center"
                                                >
                                                    <span
                                                        class="h-9 w-9 rounded-full bg-arka-primary/15 text-arka-primary-bright flex items-center justify-center text-sm font-semibold"
                                                    >
                                                        {{ item.label[0] }}
                                                    </span>
                                                    <span class="text-[11px] leading-tight text-arka-text">{{ item.label }}</span>
                                                </Link>
                                                <!-- Ícono "?" contextual (pedido explícito del usuario) —
                                                     posición absoluta arriba a la derecha, afuera del <Link>
                                                     para que tocarlo no navegue. -->
                                                <div class="absolute -top-1 -right-1">
                                                    <HelpTip :text="item.help" />
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </Dropdown>
                        </div>

                        <!-- Cuenta: avatar circular (referencia de diseño: ícono de cuenta de Google).
                             Si el usuario tiene foto (ej. entró con Google), se muestra esa foto;
                             si no (o si falla al cargar), un ícono según el rol — nunca una imagen
                             rota (sección 13: avatar por defecto, ver Components/UserAvatar.vue). -->
                        <div class="ms-1 relative">
                            <Dropdown align="right" width="56">
                                <template #trigger>
                                    <button
                                        type="button"
                                        class="relative hover:opacity-90 transition"
                                        :title="$page.props.auth.user.name"
                                    >
                                        <UserAvatar :user="$page.props.auth.user" />
                                        <!-- Pedido explícito del usuario: "un puntitto
                                             rojo con un uno para que vaya y actualice
                                             su numero" — le falta algún dato al perfil
                                             de cliente (ver HandleInertiaRequests). -->
                                        <span
                                            v-if="$page.props.auth.isProfileIncomplete"
                                            class="absolute -top-0.5 -right-0.5 h-3 w-3 rounded-full bg-arka-danger ring-2 ring-arka-card"
                                            aria-hidden="true"
                                        ></span>
                                    </button>
                                </template>

                                <template #content>
                                    <div class="px-4 py-3 border-b border-arka-text-muted/10">
                                        <p class="text-sm text-arka-text font-medium truncate">{{ $page.props.auth.user.name }}</p>
                                        <p class="text-xs text-arka-text-muted truncate mb-2">{{ $page.props.auth.user.email }}</p>

                                        <!-- Marca de qué es esta cuenta + puntuación, de un vistazo
                                             (mismo criterio que el perfil público, Profile/Show.vue). -->
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <span
                                                v-if="showCooperativeNav"
                                                class="px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-arka-primary/15 text-arka-primary-bright"
                                            >
                                                Cooperativa
                                            </span>
                                            <span
                                                v-if="isAdmin"
                                                class="px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-arka-warning/15 text-arka-warning"
                                            >
                                                Administrador
                                            </span>
                                            <span
                                                v-if="!isAdmin && showClientNav"
                                                class="px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-arka-primary/15 text-arka-primary-bright"
                                            >
                                                Cliente
                                            </span>
                                            <span
                                                v-if="!isAdmin && showDriverNav"
                                                class="px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-arka-primary/15 text-arka-primary-bright"
                                            >
                                                Conductor
                                            </span>
                                            <span
                                                v-if="$page.props.auth.reviewCount > 0"
                                                class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-arka-lime/15 text-arka-lime"
                                            >
                                                <span class="leading-none">★</span> {{ $page.props.auth.averageRating.toFixed(1) }}
                                            </span>
                                        </div>

                                        <!-- Pedido explícito del usuario: "eso es para que el
                                             sepa que pertenece a una cooperativa, colocalo alli
                                             como una etiqueta mas con su enlace... debajo de la
                                             que dice conductor" — antes vivía como un link suelto
                                             en Inicio (Dashboard.vue), ahora acá se ve en
                                             cualquier pantalla. -->
                                        <Link
                                            v-if="$page.props.auth.cooperative"
                                            :href="route('cooperatives.show', $page.props.auth.cooperative.id)"
                                            class="mt-1.5 inline-flex items-center gap-1.5 rounded-full border border-arka-primary/25 bg-arka-primary/10 px-2.5 py-0.5 text-[11px] font-medium text-arka-primary-bright hover:border-arka-primary/60 hover:bg-arka-primary/15"
                                        >
                                            <span aria-hidden="true">◉</span>
                                            Cooperativa: {{ $page.props.auth.cooperative.name }}
                                            <span aria-hidden="true">→</span>
                                        </Link>

                                        <!-- Plan vigente de cada rol activo, de un vistazo
                                             (consideración agregada al alcance). -->
                                        <div
                                            v-if="$page.props.auth.plans?.driver || $page.props.auth.plans?.client || $page.props.auth.plans?.cooperative"
                                            class="mt-2 text-xs text-arka-text-muted space-y-0.5"
                                        >
                                            <p v-if="$page.props.auth.plans.driver">
                                                Plan conductor: <span class="text-arka-text">{{ $page.props.auth.plans.driver }}</span>
                                            </p>
                                            <p v-if="$page.props.auth.plans.client">
                                                Plan cliente: <span class="text-arka-text">{{ $page.props.auth.plans.client }}</span>
                                            </p>
                                            <p v-if="$page.props.auth.plans.cooperative">
                                                Plan cooperativa: <span class="text-arka-text">{{ $page.props.auth.plans.cooperative }}</span>
                                            </p>
                                            <!-- Pedido explícito del usuario: que el conductor sepa
                                                 también en cuál está, no solo los clientes que lo ven
                                                 desde afuera. -->
                                            <p v-if="showDriverNav && $page.props.auth.hasVerifiedBadge !== null">
                                                <span v-if="$page.props.auth.hasVerifiedBadge" class="text-arka-primary-bright">✓ Insignia de verificado activa</span>
                                                <span v-else>
                                                    Sin insignia de verificado —
                                                    <Link :href="route('driver.profile.edit')" class="underline hover:text-arka-primary-bright">
                                                        ver por qué
                                                    </Link>
                                                </span>
                                            </p>
                                        </div>
                                    </div>
                                    <!-- Solo lo estrictamente de cuenta acá — Mi perfil de
                                         conductor, planes, directorio y contactos ya están en
                                         "Accesos rápidos" (FAB / grilla de puntos); repetirlos
                                         acá era la misma opción en dos menús distintos. -->
                                    <DropdownLink :href="showCooperativeNav ? route('cooperative.profile.edit') : route('profile.edit')"> Mi perfil </DropdownLink>
                                    <DropdownLink :href="route('profile.edit') + '#suscripcion'"> Ver mi suscripción </DropdownLink>
                                    <DropdownLink
                                        v-if="hasRoute('profiles.show')"
                                        :href="route('profiles.show', $page.props.auth.user.id)"
                                    >
                                        Ver mi perfil público
                                    </DropdownLink>
                                    <button
                                        type="button"
                                        @click="activatePushNotifications"
                                        class="block w-full px-4 py-2 text-start text-sm leading-5 text-arka-text hover:bg-arka-base focus:outline-none focus:bg-arka-base transition duration-150 ease-in-out"
                                    >
                                        Activar notificaciones
                                    </button>
                                    <button
                                        v-if="canInstallApp"
                                        type="button"
                                        @click="installAppNow"
                                        class="block w-full px-4 py-2 text-start text-sm leading-5 text-arka-text hover:bg-arka-base focus:outline-none focus:bg-arka-base transition duration-150 ease-in-out"
                                    >
                                        Instalar app
                                    </button>

                                    <!-- Cambiar de rol (pedido explícito del usuario): "fácil",
                                         un botón acá mismo — de cliente pasa al formulario que ya
                                         pide los requisitos (vehículo, licencia); de conductor,
                                         pausa el perfil sin borrar nada, listo para reactivar. -->
                                    <DropdownLink v-if="!isAdmin && !showDriverNav && !showCooperativeNav" :href="route('driver.profile.edit')">
                                        Pasarme a conductor
                                    </DropdownLink>
                                    <button
                                        v-if="!isAdmin && showDriverNav"
                                        type="button"
                                        @click="switchToClient"
                                        class="block w-full px-4 py-2 text-start text-sm leading-5 text-arka-text hover:bg-arka-base focus:outline-none focus:bg-arka-base transition duration-150 ease-in-out"
                                    >
                                        Pasarme a cliente
                                    </button>

                                    <DropdownLink :href="route('logout')" method="post" as="button" @click="resetStartupSplash">
                                        Cerrar sesión
                                    </DropdownLink>
                                </template>
                            </Dropdown>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Mensajes flash (ej. "no podés tener flota siendo conductor") — antes
             se flasheaban con ->with('status', ...) pero ninguna pantalla los
             mostraba. Vive acá para que cubra cualquier redirect de la app. -->
        <div
            v-if="$page.props.flash?.status"
            class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-4"
        >
            <div class="p-3 rounded-arka bg-arka-primary/10 text-arka-primary-bright text-sm">
                {{ $page.props.flash.status }}
            </div>
        </div>

        <!-- Pedido explícito del usuario: recordatorio de ubicación +
             notificaciones para cliente y conductor por igual, dentro de la
             app logueada — a propósito no vive en Welcome.vue. -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-4">
            <PermissionsPrompt />
        </div>

        <!-- Page Heading -->
        <header class="bg-arka-card shadow" v-if="$slots.header">
            <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                <slot name="header" />
            </div>
        </header>

        <!-- Page Content -->
        <!-- pb-20 en móvil deja espacio para que la navegación inferior no tape contenido -->
        <main class="pb-20 sm:pb-0">
            <slot />
        </main>

        <!-- Navegación inferior tipo app, solo en móvil (sección 9.9: "Inicio · Flota · Carreras · Perfil"),
             con un botón central flotante para accesos rápidos (preferencia de diseño del usuario). -->
        <nav
            v-if="!hideBottomNav"
            class="sm:hidden fixed bottom-0 inset-x-0 z-30 bg-arka-card border-t border-arka-text-muted/10 flex items-stretch"
            style="padding-bottom: env(safe-area-inset-bottom)"
        >
            <!-- Tabs a la izquierda del botón central. La cantidad de tabs visibles
                 varía según el rol (cliente ve "Flotas", conductor no, admin ve
                 "Admin" en vez de "Carreras") — agruparlos en su propia mitad
                 flex, en vez de que el botón central sea "un tab más" del mismo
                 flex, es lo que garantiza que quede centrado siempre, sin
                 importar cuántos tabs haya de cada lado. -->
            <div class="flex-1 flex items-stretch">
                <Link
                    :href="route('dashboard')"
                    class="flex-1 flex flex-col items-center justify-center gap-1 py-2 min-h-[44px]"
                    :class="route().current('dashboard') ? 'text-arka-primary' : 'text-arka-text-muted'"
                >
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 11.5 12 4l9 7.5" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 10v9a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-9" />
                    </svg>
                    <span class="text-xs font-medium">Inicio</span>
                </Link>

                <!-- Pedido explícito del usuario: "solicitudes lo coloques
                     alado de donde esta el icono de inicio", SOLO para el
                     conductor — el tab "Carreras" de siempre (más abajo, a
                     la derecha) sigue igual para todos, sin tocarlo. Este es
                     uno NUEVO, no un reemplazo. -->
                <Link
                    v-if="hasRoute('rides.index') && showDriverNav"
                    :href="route('rides.index')"
                    class="flex-1 flex flex-col items-center justify-center gap-1 py-2 min-h-[44px]"
                    :class="
                        route().current('rides.*') || route().current('ride-requests.*')
                            ? 'text-arka-primary'
                            : 'text-arka-text-muted'
                    "
                >
                    <span class="relative">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M4 16l2.5-6.5A2 2 0 0 1 8.35 8.2h7.3a2 2 0 0 1 1.85 1.3L20 16"
                            />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16h16v2.5a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1V17H7v1.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V16Z" />
                            <circle cx="7.5" cy="16" r="1" />
                            <circle cx="16.5" cy="16" r="1" />
                        </svg>
                        <span
                            v-if="$page.props.auth.pendingRideRequestsCount > 0"
                            class="absolute -top-1 -right-2 h-4 w-4 rounded-full bg-arka-primary text-arka-base text-[10px] font-bold flex items-center justify-center"
                        >
                            {{ $page.props.auth.pendingRideRequestsCount }}
                        </span>
                    </span>
                    <span class="text-xs font-medium">Solicitudes</span>
                </Link>

                <Link
                    v-if="hasRoute('fleet.index') && showClientNav"
                    :href="route('fleet.index')"
                    class="flex-1 flex flex-col items-center justify-center gap-1 py-2 min-h-[44px]"
                    :class="route().current('fleet.*') ? 'text-arka-primary' : 'text-arka-text-muted'"
                >
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="8" r="3.25" stroke-linecap="round" stroke-linejoin="round" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 20a7.5 7.5 0 0 1 15 0" />
                        <circle cx="5.5" cy="12.5" r="2" stroke-linecap="round" stroke-linejoin="round" />
                        <circle cx="18.5" cy="12.5" r="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span class="text-xs font-medium">Flotas</span>
                </Link>
            </div>

            <!-- Espacio reservado para que los tabs de los costados no queden
                 pegados debajo del botón flotante (el botón en sí va aparte, con
                 posición absoluta — ver más abajo). -->
            <div class="w-16 shrink-0" aria-hidden="true"></div>

            <!-- Tabs a la derecha del botón central. -->
            <div class="flex-1 flex items-stretch">
                <!-- Pedido explícito del usuario: esto es el tab de
                     siempre, tal cual estaba antes — no se toca para el
                     cliente ("solo te pedi que agregaras en el conductor").
                     El conductor ahora lo ve doble (acá y como "Solicitudes"
                     a la izquierda) a propósito, para que ambos lados del
                     "+" queden simétricos con 2 íconos cada uno. -->
                <Link
                    v-if="hasRoute('rides.index') && !isAdmin && !showCooperativeNav"
                    :href="route('rides.index')"
                    class="flex-1 flex flex-col items-center justify-center gap-1 py-2 min-h-[44px]"
                    :class="
                        route().current('rides.*') || route().current('ride-requests.*')
                            ? 'text-arka-primary'
                            : 'text-arka-text-muted'
                    "
                >
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M4 16l2.5-6.5A2 2 0 0 1 8.35 8.2h7.3a2 2 0 0 1 1.85 1.3L20 16"
                        />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16h16v2.5a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1V17H7v1.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V16Z" />
                        <circle cx="7.5" cy="16" r="1" />
                        <circle cx="16.5" cy="16" r="1" />
                    </svg>
                    <span class="text-xs font-medium">Carreras</span>
                </Link>

                <!-- Tab dedicado del admin en móvil: mismo criterio que la pastilla de
                     escritorio, reemplaza a Flotas/Carreras que no le sirven de nada. -->
                <Link
                    v-if="isAdmin && hasRoute('admin.subscriptions.index')"
                    :href="route('admin.subscriptions.index')"
                    class="flex-1 flex flex-col items-center justify-center gap-1 py-2 min-h-[44px]"
                    :class="route().current('admin.*') ? 'text-arka-warning' : 'text-arka-text-muted'"
                >
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m12 3 8 3.5v5.2c0 4.4-3 7.6-8 9.3-5-1.7-8-4.9-8-9.3V6.5L12 3Z" />
                    </svg>
                    <span class="text-xs font-medium">Admin</span>
                </Link>

                <Link
                    :href="showCooperativeNav ? route('cooperative.profile.edit') : route('profile.edit')"
                    class="relative flex-1 flex flex-col items-center justify-center gap-1 py-2 min-h-[44px]"
                    :class="route().current('profile.*') ? 'text-arka-primary' : 'text-arka-text-muted'"
                >
                    <span class="relative">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="8" r="3.5" stroke-linecap="round" stroke-linejoin="round" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 20a7.5 7.5 0 0 1 15 0" />
                        </svg>
                        <span
                            v-if="$page.props.auth.isProfileIncomplete"
                            class="absolute -top-0.5 -right-0.5 h-2.5 w-2.5 rounded-full bg-arka-danger ring-2 ring-arka-card"
                            aria-hidden="true"
                        ></span>
                    </span>
                    <span class="text-xs font-medium">Perfil</span>
                </Link>
            </div>

            <!-- Botón central flotante: abre el bottom sheet de accesos rápidos.
                 Posición absoluta centrada en la barra entera (no en el flex de los
                 tabs), así queda perfectamente al medio sin importar cuántos tabs
                 haya de cada lado. -->
            <div class="absolute left-1/2 -translate-x-1/2 bottom-2 flex justify-center pointer-events-none">
                <button
                    type="button"
                    @click="showingQuickActions = true"
                    class="-mt-6 h-14 w-14 rounded-full bg-arka-primary text-arka-base shadow-lg shadow-arka-primary/30 flex items-center justify-center active:scale-95 transition-transform pointer-events-auto"
                    aria-label="Accesos rápidos"
                >
                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
                    </svg>
                </button>
            </div>
        </nav>

        <!-- Bottom sheet de accesos rápidos (sección 9.9/9.10: "que la web se sienta app de verdad").
             Pedido explícito del usuario (con captura de la nav admin: "en
             movil que salga desde abajo en el signo ese +"): para un admin
             este mismo cajón mostraba `quickLinks`, que es 100% de
             cliente/conductor/cooperativa — ni un solo ítem de admin, así
             que el botón "+" no servía de nada en esa cuenta. Ahora, si es
             admin, muestra las secciones agrupadas de Utils/adminNav.js
             (misma agrupación que el dropdown de escritorio y las tarjetas
             del Inicio) en vez del contenido de siempre. -->
        <BottomSheet :show="showingQuickActions" @close="showingQuickActions = false">
            <div v-if="isAdmin" class="p-4 pb-6">
                <h3 class="text-center text-arka-text font-medium mb-4">Panel admin</h3>

                <!-- Pedido explícito del usuario: "que tenga su scroll claro
                     porque son muchos, pero divilo en secciones". -->
                <div class="max-h-[65vh] space-y-5 overflow-y-auto pr-1">
                    <div v-for="group in adminNavGroups" :key="group.key">
                        <p class="mb-1.5 flex items-center gap-1.5 px-3 text-xs font-semibold uppercase tracking-wide text-arka-text-muted">
                            <span class="h-3.5 w-3.5 shrink-0"><AdminNavIcon :icon="group.icon" /></span>
                            {{ group.label }}
                        </p>
                        <Link
                            v-for="item in group.items"
                            :key="item.route"
                            :href="route(item.route)"
                            @click="showingQuickActions = false"
                            class="block px-3 py-2.5 rounded-arka text-sm min-h-[44px] flex items-center"
                            :class="route().current(item.match) ? 'text-arka-primary-bright bg-arka-base' : 'text-arka-text hover:bg-arka-base'"
                        >
                            {{ item.label }}
                        </Link>
                    </div>
                </div>
            </div>

            <div v-else class="p-4 pb-6">
                <h3 class="text-center text-arka-text font-medium mb-4">Accesos rápidos</h3>

                <div class="space-y-1">
                    <div v-if="hasRoute('ride-requests.create') && showClientNav" class="flex items-center gap-1">
                        <Link
                            :href="route('ride-requests.create')"
                            @click="showingQuickActions = false"
                            class="flex-1 flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-base min-h-[44px]"
                        >
                            <svg class="h-6 w-6 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l2.5-6.5A2 2 0 0 1 8.35 8.2h7.3a2 2 0 0 1 1.85 1.3L20 16" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16h16v2.5a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1V17H7v1.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V16Z" />
                            </svg>
                            <span class="text-arka-text">Pedir una carrera</span>
                        </Link>
                        <HelpTip text="Pida un viaje ahora o programado, a toda su flota o a un conductor puntual. Arme primero Mis Flotas para tener a quién pedirle." />
                    </div>

                    <div v-if="canBecomeOrIsDriver" class="flex items-center gap-1">
                        <Link
                            :href="route('driver.profile.edit')"
                            @click="showingQuickActions = false"
                            class="flex-1 flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-base min-h-[44px]"
                        >
                            <svg class="h-6 w-6 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3.5" y="6" width="17" height="12" rx="2" stroke-linecap="round" stroke-linejoin="round" />
                                <circle cx="9" cy="12" r="1.75" stroke-linecap="round" stroke-linejoin="round" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14 10.5h3M14 13.5h3" />
                            </svg>
                            <span class="text-arka-text">Mi perfil de conductor</span>
                        </Link>
                        <HelpTip text="Complete los datos de su vehículo para activar el perfil y empezar a recibir carreras." />
                    </div>

                    <div v-if="hasRoute('driver.invitations.index') && showDriverNav" class="flex items-center gap-1">
                        <Link
                            :href="route('driver.invitations.index')"
                            @click="showingQuickActions = false"
                            class="flex-1 flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-base min-h-[44px]"
                        >
                            <svg class="h-6 w-6 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="9" cy="9" r="3" stroke-linecap="round" stroke-linejoin="round" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 19a5.5 5.5 0 0 1 11 0" />
                                <circle cx="17" cy="9" r="2.4" stroke-linecap="round" stroke-linejoin="round" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.5 13.5c2.4 0 4.5 1.9 5 5" />
                            </svg>
                            <span class="text-arka-text">Mis clientes de confianza</span>
                        </Link>
                        <HelpTip text="Acepte invitaciones de flota y administre a sus clientes. De ahí le llegan las solicitudes que ve en Carreras." />
                    </div>

                    <div v-if="hasRoute('directory.index') && showClientNav" class="flex items-center gap-1">
                        <Link
                            :href="route('directory.index')"
                            @click="showingQuickActions = false"
                            class="flex-1 flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-base min-h-[44px]"
                        >
                            <svg class="h-6 w-6 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="8.5" stroke-linecap="round" stroke-linejoin="round" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 12h17M12 3.5c2.5 2.3 3.8 5.3 3.8 8.5s-1.3 6.2-3.8 8.5c-2.5-2.3-3.8-5.3-3.8-8.5S9.5 5.8 12 3.5Z" />
                            </svg>
                            <span class="text-arka-text">Directorio de conductores</span>
                        </Link>
                        <HelpTip text="Conductores públicos verificados, para cuando nadie de su flota está disponible. Desde acá también puede invitarlos a Mis Flotas." />
                    </div>

                    <!-- Expresos (sección 4): rutas fijas y recurrentes. -->
                    <div v-if="hasRoute('express-routes.index') && showClientNav" class="flex items-center gap-1">
                        <Link
                            :href="route('express-routes.index')"
                            @click="showingQuickActions = false"
                            class="flex-1 flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-base min-h-[44px]"
                        >
                            <svg class="h-6 w-6 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3.5" y="5" width="17" height="15" rx="2" stroke-linecap="round" stroke-linejoin="round" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 10h17M8 3v4M16 3v4" />
                            </svg>
                            <span class="text-arka-text">Mis Expresos</span>
                        </Link>
                        <HelpTip text="Ruta fija y recurrente (ej. su viaje diario al trabajo) en vez de pedir Pedir una carrera cada vez." />
                    </div>

                    <div v-if="hasRoute('express-routes.available') && showDriverNav" class="flex items-center gap-1">
                        <Link
                            :href="route('express-routes.available')"
                            @click="showingQuickActions = false"
                            class="flex-1 flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-base min-h-[44px]"
                        >
                            <svg class="h-6 w-6 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3.5" y="5" width="17" height="15" rx="2" stroke-linecap="round" stroke-linejoin="round" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 10h17M8 3v4M16 3v4" />
                            </svg>
                            <span class="text-arka-text">Expresos disponibles</span>
                        </Link>
                        <HelpTip text="Postúlese a rutas fijas que publican sus clientes — solo ve las de las flotas de Mis clientes de confianza." />
                    </div>

                    <!-- Mi plan (secciones 7, 7.2 y 7.3): un usuario puede tener un plan
                         de conductor y otro de cliente al mismo tiempo (sección 3.1). -->
                    <div v-if="hasRoute('driver.plan.edit') && showDriverNav" class="flex items-center gap-1">
                        <Link
                            :href="route('driver.plan.edit')"
                            @click="showingQuickActions = false"
                            class="flex-1 flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-base min-h-[44px]"
                        >
                            <svg class="h-6 w-6 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 8.5 12 4l7 4.5v7L12 20l-7-4.5v-7Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 12v8M5 8.5 12 12l7-3.5" />
                            </svg>
                            <span class="text-arka-text">Mi plan de conductor</span>
                        </Link>
                        <HelpTip text="Su plan vigente y sus beneficios — algunos, como el directorio público, también dependen de su medalla por puntos." />
                    </div>

                    <div v-if="hasRoute('client.plan.edit') && showClientNav" class="flex items-center gap-1">
                        <Link
                            :href="route('client.plan.edit')"
                            @click="showingQuickActions = false"
                            class="flex-1 flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-base min-h-[44px]"
                        >
                            <svg class="h-6 w-6 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 8.5 12 4l7 4.5v7L12 20l-7-4.5v-7Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 12v8M5 8.5 12 12l7-3.5" />
                            </svg>
                            <span class="text-arka-text">Mi plan de cliente</span>
                        </Link>
                        <HelpTip text="Su plan vigente y sus beneficios como cliente." />
                    </div>

                    <!-- Contactos de confianza (sección 8): a quién avisa el botón SOS. -->
                    <div v-if="hasRoute('trusted-contacts.index')" class="flex items-center gap-1">
                        <Link
                            :href="route('trusted-contacts.index')"
                            @click="showingQuickActions = false"
                            class="flex-1 flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-base min-h-[44px]"
                        >
                            <svg class="h-6 w-6 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7-4.5-9.5-9A5.5 5.5 0 0 1 12 6a5.5 5.5 0 0 1 9.5 6c-2.5 4.5-9.5 9-9.5 9Z" />
                            </svg>
                            <span class="text-arka-text">Contactos de confianza</span>
                        </Link>
                        <HelpTip text="A quién avisa el botón SOS si lo activa durante un viaje." />
                    </div>

                    <!-- Rutas y Turismo y Cupones (rediseño UX): ya vivían en el menú
                         de escritorio (`quickLinks`) pero faltaban acá, en el cajón
                         móvil — quedaron descubiertos al mover contenido de Inicio
                         al menú. -->
                    <div v-if="hasRoute('van-trips.browse') && showClientNav" class="flex items-center gap-1">
                        <Link
                            :href="route('van-trips.browse')"
                            @click="showingQuickActions = false"
                            class="flex-1 flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-base min-h-[44px]"
                        >
                            <svg class="h-6 w-6 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 19c2-4 3-6 3-9a5 5 0 0 1 10 0c0 3 1 5 3 9" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 19h6M12 6v.01" />
                            </svg>
                            <span class="text-arka-text">Rutas y Turismo</span>
                        </Link>
                        <HelpTip text="Explore y reserve un asiento en las salidas programadas que publican los conductores." />
                    </div>

                    <div v-if="hasRoute('van-trips.index') && showDriverNav" class="flex items-center gap-1">
                        <Link
                            :href="route('van-trips.index')"
                            @click="showingQuickActions = false"
                            class="flex-1 flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-base min-h-[44px]"
                        >
                            <svg class="h-6 w-6 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 19c2-4 3-6 3-9a5 5 0 0 1 10 0c0 3 1 5 3 9" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 19h6M12 6v.01" />
                            </svg>
                            <span class="text-arka-text">Mis rutas y turismo</span>
                        </Link>
                        <HelpTip text="Publique salidas programadas de ruta fija, que los clientes reservan por asiento." />
                    </div>

                    <div v-if="hasRoute('coupons.index')" class="flex items-center gap-1">
                        <Link
                            :href="route('coupons.index')"
                            @click="showingQuickActions = false"
                            class="flex-1 flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-base min-h-[44px]"
                        >
                            <svg class="h-6 w-6 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4 12 7-7h7a2 2 0 0 1 2 2v7l-7 7a2 2 0 0 1-3 0l-6-6a2 2 0 0 1 0-3Z" />
                                <circle cx="14.5" cy="9.5" r="1.2" />
                            </svg>
                            <span class="text-arka-text">Cupones y beneficios</span>
                        </Link>
                        <HelpTip text="Promos de comercios aliados, separadas para clientes y para conductores." />
                    </div>

                    <!-- Encuesta corta de conductor/pasajero (pedido explícito del
                         usuario: "un botón que me ayuda ir a una encuesta... bien
                         ubicada") — sin restricción de rol, sirve para los dos. -->
                    <div v-if="hasRoute('survey.show')" class="flex items-center gap-1">
                        <Link
                            :href="route('survey.show')"
                            @click="showingQuickActions = false"
                            class="flex-1 flex items-center gap-3 px-3 py-3 rounded-arka hover:bg-arka-base min-h-[44px]"
                        >
                            <svg class="h-6 w-6 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 11.5h6M9 15h4M6.5 4.5h11a2 2 0 0 1 2 2V19l-3.5-2H6.5a2 2 0 0 1-2-2V6.5a2 2 0 0 1 2-2Z" />
                            </svg>
                            <span class="text-arka-text">Encuesta</span>
                        </Link>
                        <HelpTip text="Cuentanos tu experiencia con Arka01 — menos de 2 minutos, sin necesidad de cuenta." />
                    </div>
                </div>
            </div>
        </BottomSheet>

        <!-- Panel de ayuda (escritorio, ícono "?" del header). -->
        <Modal :show="showingHelp" max-width="md" @close="showingHelp = false">
            <div class="p-6 space-y-4">
                <h3 class="text-lg font-medium text-arka-text">¿Necesita ayuda?</h3>
                <ul class="text-sm text-arka-text-muted space-y-2 list-disc list-inside">
                    <li>Arme su flota de confianza desde <strong class="text-arka-text">Mis Flotas</strong> antes de pedir una carrera.</li>
                    <li>Si nadie de su flota está disponible, mire el <strong class="text-arka-text">Directorio de conductores</strong>.</li>
                    <li>El precio de cada carrera se calcula y se muestra siempre desglosado, nunca oculto.</li>
                    <li>Durante un viaje en curso puede compartir el seguimiento en vivo o usar el botón SOS desde la carrera.</li>
                </ul>
                <div class="flex items-center justify-between">
                    <!-- Volver a ver el recorrido guiado a propósito (pedido
                         explícito del usuario): no toca `onboarding_completed_at`,
                         solo lo vuelve a mostrar. -->
                    <button
                        type="button"
                        class="text-sm text-arka-primary hover:text-arka-primary-bright"
                        @click="openOnboardingAgain"
                    >
                        Ver guía de nuevo
                    </button>
                    <button
                        type="button"
                        class="px-4 py-2 rounded-arka bg-arka-primary text-arka-base text-sm font-medium"
                        @click="showingHelp = false"
                    >
                        Entendido
                    </button>
                </div>
            </div>
        </Modal>

        <OnboardingTour :show="showingOnboarding" :steps="onboardingSteps" @close="completeOnboarding" />

        <IncomingRideRequestModal v-if="showDriverNav" />
    </div>
</template>
