<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import BottomSheet from '@/Components/BottomSheet.vue';
import Modal from '@/Components/Modal.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import IncomingRideRequestModal from '@/Components/IncomingRideRequestModal.vue';
import OnboardingTour from '@/Components/OnboardingTour.vue';
import HelpTip from '@/Components/HelpTip.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { pushSupported, subscribeToPush } from '@/push.js';
import { playIncomingRideAlert } from '@/Utils/liveAlert';
import { dismissIncomingRideRequest, pushIncomingRideRequest } from '@/Utils/incomingRideRequest';
import { clientOnboardingSteps, driverOnboardingSteps } from '@/Utils/onboardingSteps';

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

// Notificaciones push (sección 9.2 y 9.5): se activan a pedido del usuario
// desde el menú de cuenta, nunca con un permiso pedido de entrada.
async function activatePushNotifications() {
    if (!pushSupported()) {
        alert('Su navegador no soporta notificaciones push.');
        return;
    }

    const ok = await subscribeToPush(usePage().props.vapidPublicKey);
    alert(ok ? 'Notificaciones activadas.' : 'No se pudo activar — revise los permisos del navegador.');
}

// Mismo set de accesos rápidos que el bottom sheet de móvil, para que en
// escritorio también estén a un clic desde el ícono de grilla del header
// (referencia de diseño: el selector de apps "de los puntos" de Google).
// "Mi perfil de conductor" no lleva clientOnly/driverOnly: es la puerta de
// entrada para activar el rol de conductor, tiene que verse para cualquiera
// que todavía pueda hacerlo — pero un cliente que ya tiene flota propia no
// puede (sección 3.1: cada cuenta es cliente o conductor, nunca las dos), así
// que a ese sí se le oculta (ver `canBecomeOrIsDriver`).
// El admin tiene su propio acceso directo y prominente (pastilla de escritorio,
// tab de móvil) así que no repetimos "Panel admin" acá también.
const canBecomeOrIsDriver = computed(() => showDriverNav.value || !usePage().props.auth.hasFleet);
// `help` (pedido explícito del usuario: ícono "?" contextual en cada módulo,
// alternativa que él mismo ofreció al ver que la guía de bienvenida siempre
// aparece en el mismo lugar) — qué hace cada uno y con qué otro se relaciona,
// en una frase. Mismo texto se repite a mano en el bottom sheet de abajo,
// que ya no reutiliza este array (divergencia que ya existía antes de esto).
const quickLinks = computed(() =>
    [
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
            route: 'trusted-contacts.index',
            label: 'Contactos de confianza',
            help: 'A quién avisa el botón SOS si lo activa durante un viaje.',
        },
        {
            route: 'coupons.index',
            label: 'Cupones y beneficios',
            help: 'Promos de comercios aliados, separadas para clientes y para conductores.',
        },
        {
            route: 'van-trips.index',
            label: 'Mis viajes VAN',
            driverOnly: true,
            help: 'Publique salidas programadas tipo VAN/turismo, que los clientes reservan por asiento.',
        },
        {
            route: 'van-trips.browse',
            label: 'Viajes VAN / turismo',
            clientOnly: true,
            help: 'Explore y reserve un asiento en las salidas programadas que publican los conductores.',
        },
    ].filter(
        (item) =>
            hasRoute(item.route) &&
            (!item.clientOnly || showClientNav.value) &&
            (!item.driverOnly || showDriverNav.value) &&
            (!item.hideIfCommittedClient || canBecomeOrIsDriver.value)
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

onMounted(() => {
    if (!showDriverNav.value) return;

    const userId = usePage().props.auth.user.id;
    incomingRideChannel = window.Echo.private(`App.Models.User.${userId}`);
    incomingRideChannel.listen('.ride-request.created', (e) => {
        playIncomingRideAlert();
        pushIncomingRideRequest(e);
    });
    // Bug real reportado con capturas (dos conductores viendo el mismo modal
    // a la vez, uno con "0 seg." pegado para siempre): cuando el despacho
    // secuencial pasa al siguiente candidato (se acabaron los 30 seg., o el
    // conductor la descartó desde OTRA pestaña/dispositivo), el backend
    // avisa con este mismo evento que ya usaba Ride/Index.vue — acá faltaba
    // escucharlo, así que el modal se quedaba pegado y encima dejaba
    // aceptar/descartar una solicitud que ya no era suya (403 del servidor).
    incomingRideChannel.listen('.ride-request.cancelled', (e) => {
        dismissIncomingRideRequest(e.ride_request_id);
    });
});

onBeforeUnmount(() => {
    if (incomingRideChannel) {
        incomingRideChannel.stopListening('.ride-request.created');
        incomingRideChannel.stopListening('.ride-request.cancelled');
    }
});
</script>

<template>
    <div class="min-h-screen bg-arka-base">
        <nav class="bg-arka-card border-b border-arka-text-muted/10">
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
                                v-if="hasRoute('rides.index') && !isAdmin"
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
                             si no, iniciales — nunca una imagen rota (sección 13: avatar por defecto). -->
                        <div class="ms-1 relative">
                            <Dropdown align="right" width="56">
                                <template #trigger>
                                    <button
                                        type="button"
                                        class="hover:opacity-90 transition"
                                        :title="$page.props.auth.user.name"
                                    >
                                        <UserAvatar :user="$page.props.auth.user" />
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

                                        <!-- Plan vigente de cada rol activo, de un vistazo
                                             (consideración agregada al alcance). -->
                                        <div
                                            v-if="$page.props.auth.plans?.driver || $page.props.auth.plans?.client"
                                            class="mt-2 text-xs text-arka-text-muted space-y-0.5"
                                        >
                                            <p v-if="$page.props.auth.plans.driver">
                                                Plan conductor: <span class="text-arka-text">{{ $page.props.auth.plans.driver }}</span>
                                            </p>
                                            <p v-if="$page.props.auth.plans.client">
                                                Plan cliente: <span class="text-arka-text">{{ $page.props.auth.plans.client }}</span>
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
                                    <DropdownLink :href="route('profile.edit')"> Mi perfil </DropdownLink>
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
                                    <DropdownLink :href="route('logout')" method="post" as="button">
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
                <Link
                    v-if="hasRoute('rides.index') && !isAdmin"
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
                    :href="route('profile.edit')"
                    class="flex-1 flex flex-col items-center justify-center gap-1 py-2 min-h-[44px]"
                    :class="route().current('profile.*') ? 'text-arka-primary' : 'text-arka-text-muted'"
                >
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="8" r="3.5" stroke-linecap="round" stroke-linejoin="round" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 20a7.5 7.5 0 0 1 15 0" />
                    </svg>
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

        <!-- Bottom sheet de accesos rápidos (sección 9.9/9.10: "que la web se sienta app de verdad") -->
        <BottomSheet :show="showingQuickActions" @close="showingQuickActions = false">
            <div class="p-4 pb-6">
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
