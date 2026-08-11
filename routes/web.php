<?php

use App\Http\Controllers\Admin\AdBannerController;
use App\Http\Controllers\Admin\ClientController as AdminClientController;
use App\Http\Controllers\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Admin\DriverController as AdminDriverController;
use App\Http\Controllers\Admin\DriverTierController;
use App\Http\Controllers\Admin\DriverVerificationController;
use App\Http\Controllers\Admin\FaqController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\MetricsController;
use App\Http\Controllers\Admin\OperationsController as AdminOperationsController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\PlanPromotionController;
use App\Http\Controllers\Admin\PlatformFeedbackController as AdminPlatformFeedbackController;
use App\Http\Controllers\Admin\PricingSettingController;
use App\Http\Controllers\Admin\RatingReasonController;
use App\Http\Controllers\Admin\SosAlertController as AdminSosAlertController;
use App\Http\Controllers\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Admin\SupportTicketController;
use App\Http\Controllers\Admin\SystemController as AdminSystemController;
use App\Http\Controllers\Admin\SystemEventController;
use App\Http\Controllers\Admin\UserProfileController as AdminUserProfileController;
use App\Http\Controllers\Admin\WhatsAppSettingController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DriverDirectoryController;
use App\Http\Controllers\DriverInvitationController;
use App\Http\Controllers\DriverLocationController;
use App\Http\Controllers\DriverProfileController;
use App\Http\Controllers\DriverStatsController;
use App\Http\Controllers\ExpressApplicationController;
use App\Http\Controllers\ExpressIncidentController;
use App\Http\Controllers\ExpressRouteCompanionController;
use App\Http\Controllers\ExpressRouteController;
use App\Http\Controllers\FleetController;
use App\Http\Controllers\FleetInvitationController;
use App\Http\Controllers\FleetMemberController;
use App\Http\Controllers\MyPlanController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PlatformFeedbackController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicProfileController;
use App\Http\Controllers\PublicRideTrackingController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RideController;
use App\Http\Controllers\RideMessageController;
use App\Http\Controllers\RideRequestController;
use App\Http\Controllers\SavedRouteController;
use App\Http\Controllers\SosAlertController;
use App\Http\Controllers\SubscriptionRequestController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\TrustedContactController;
use App\Http\Controllers\VanTripController;
use App\Http\Controllers\VanTripReservationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ]);
});

// "Ayúdanos a mejorar ARKA01" (roadmap de mejoras, sección 14): formulario
// público en el Home, sin necesidad de sesión — throttle porque no hay
// cuenta detrás que limite cuántas veces se puede mandar.
Route::post('/opiniones', [PlatformFeedbackController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('platform-feedback.store');

// Páginas legales (pedido explícito del usuario, gap identificado antes del
// despliegue): públicas, sin necesidad de sesión — hace falta poder verlas
// antes de registrarse.
Route::get('/terminos', function () {
    return Inertia::render('Legal/Terms', [
        'contactEmail' => config('mail.from.address'),
        'updatedAt' => '2026-08-09',
    ]);
})->name('legal.terms');

Route::get('/privacidad', function () {
    return Inertia::render('Legal/Privacy', [
        'contactEmail' => config('mail.from.address'),
        'updatedAt' => '2026-08-09',
    ]);
})->name('legal.privacy');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified', 'phone_verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    // Recorrido guiado por rol, una sola vez (pedido explícito del usuario).
    Route::post('/onboarding/completar', [OnboardingController::class, 'complete'])->name('onboarding.complete');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // "Convertirme en conductor" / editar mi perfil de conductor (sección 9.5-B).
    Route::get('/driver/profile', [DriverProfileController::class, 'edit'])->name('driver.profile.edit');
    Route::post('/driver/profile', [DriverProfileController::class, 'update'])->name('driver.profile.update');
    // Auditoría de seguridad: la foto de licencia vive en disco privado —
    // este endpoint la sirve solo al propio conductor o a un admin (ver
    // DriverProfileController::licensePhoto()).
    Route::get('/driver/profile/{user}/licencia', [DriverProfileController::class, 'licensePhoto'])->name('driver-profile.license-photo');

    // Mi Flota (lado cliente, sección 3.2 y 9.5-A). Desde la Fase 5 un cliente
    // puede tener más de una flota si su plan lo permite (sección 7.3, plan
    // Multi-flota), por eso "flotas" es una lista y cada una tiene su propia
    // pantalla de detalle en /flota/{fleet}.
    Route::get('/flotas', [FleetController::class, 'index'])->name('fleet.index');
    Route::post('/flotas', [FleetController::class, 'store'])->name('fleet.store');

    // OJO con el orden: "/flota/{fleet}" es una ruta comodín y Laravel matchea
    // en el orden en que se registran. Si quedara antes, un GET a
    // "/flota/solicitar" caería acá con {fleet}="solicitar" (404 al no
    // encontrar esa flota) en vez de llegar a RideRequestController@create.
    // Por eso los tramos literales (solicitar, solicitudes) van primero.
    Route::get('/flota/solicitar', [RideRequestController::class, 'create'])->name('ride-requests.create');
    // Rendimiento/seguridad en producción (pedido explícito del usuario):
    // sin esto, nada impedía inundar de solicitudes de carrera a un mismo
    // usuario autenticado.
    Route::post('/flota/solicitudes', [RideRequestController::class, 'store'])->middleware('throttle:10,1')->name('ride-requests.store');

    Route::get('/flota/{fleet}', [FleetController::class, 'show'])->name('fleet.show');
    Route::get('/flota/{fleet}/buscar-conductores', [FleetController::class, 'searchDrivers'])->name('fleet.search-drivers');
    Route::post('/flota/{fleet}/invitaciones', [FleetInvitationController::class, 'store'])->name('fleet.invitations.store');
    Route::delete('/flota/invitaciones/{invitation}', [FleetInvitationController::class, 'destroy'])->name('fleet.invitations.destroy');
    Route::delete('/flota/miembros/{member}', [FleetMemberController::class, 'destroy'])->name('fleet.members.destroy');

    // "Referí a tu conductor" (pedido explícito del usuario): mandar la
    // invitación de verdad exige sesión de cliente — ver la ruta pública
    // GET más abajo, junto al seguimiento en vivo, para solo mirar el enlace.
    Route::post('/referir/{driverProfile:invite_code}', [ReferralController::class, 'store'])->name('referrals.store');

    // Mis clientes de confianza (lado conductor, sección 3.2 y 9.5-B).
    Route::get('/mis-clientes', [DriverInvitationController::class, 'index'])->name('driver.invitations.index');
    Route::post('/mis-clientes/invitaciones/{invitation}/aceptar', [DriverInvitationController::class, 'accept'])->name('driver.invitations.accept');
    Route::post('/mis-clientes/invitaciones/{invitation}/rechazar', [DriverInvitationController::class, 'reject'])->name('driver.invitations.reject');
    Route::post('/mis-clientes/{member}/salir', [DriverInvitationController::class, 'leave'])->name('driver.fleets.leave');
    Route::post('/mis-clientes/{member}/solicitudes', [DriverInvitationController::class, 'toggleRequests'])->name('driver.fleets.toggle-requests');

    // Ubicación en vivo del conductor (sección 9.3). El frontend la llama
    // cada ~15s (DriverAvailabilityToggle.vue) — 20/min da margen de sobra
    // sin dejar la puerta abierta a inundarla (pedido explícito del usuario:
    // rendimiento/seguridad de cara a producción).
    Route::post('/driver/location', [DriverLocationController::class, 'update'])->middleware('throttle:20,1')->name('driver.location.update');

    // Resto de la negociación de precio de una solicitud (sección 3.5 y 5).
    Route::post('/solicitudes/{rideRequest}/aceptar', [RideRequestController::class, 'accept'])->name('ride-requests.accept');
    Route::post('/solicitudes/{rideRequest}/contraofertar', [RideRequestController::class, 'counter'])->name('ride-requests.counter');
    Route::post('/solicitudes/{rideRequest}/subir-oferta', [RideRequestController::class, 'raiseOffer'])->name('ride-requests.raise-offer');
    Route::post('/solicitudes/{rideRequest}/rechazar', [RideRequestController::class, 'reject'])->name('ride-requests.reject');
    Route::post('/solicitudes/{rideRequest}/cancelar', [RideRequestController::class, 'cancel'])->name('ride-requests.cancel');

    // Carreras (sección 3.5, 8 y 9.5).
    Route::get('/carreras', [RideController::class, 'index'])->name('rides.index');
    // OJO con el orden (mismo caso que "/flota/solicitar"): "/carreras/{ride}"
    // es comodín, así que el tramo literal "indicadores" tiene que ir antes,
    // si no Laravel lo toma como {ride}="indicadores" y tira 404.
    Route::get('/carreras/indicadores', [DriverStatsController::class, 'index'])->name('rides.stats');
    Route::get('/carreras/{ride}', [RideController::class, 'show'])->name('rides.show');
    Route::post('/carreras/{ride}/arrancar', [RideController::class, 'start'])->name('rides.start');
    Route::post('/carreras/{ride}/llegue', [RideController::class, 'arrived'])->name('rides.arrived');
    Route::post('/carreras/{ride}/recogido', [RideController::class, 'pickedUp'])->name('rides.picked-up');
    Route::post('/carreras/{ride}/cancelar', [RideController::class, 'cancel'])->name('rides.cancel');
    Route::post('/carreras/{ride}/completar', [RideController::class, 'complete'])->name('rides.complete');
    // Chat temporal cliente↔conductor (sección 10 del roadmap de mejoras).
    Route::post('/carreras/{ride}/mensajes', [RideMessageController::class, 'store'])->name('ride-messages.store');
    Route::post('/carreras/{ride}/calificar', [ReviewController::class, 'store'])->name('reviews.store');
    Route::get('/carreras/{ride}/seguimiento', [RideController::class, 'trackingLink'])->name('rides.tracking-link');
    // Auditoría de seguridad (pedido explícito del usuario): sin límite,
    // una cuenta comprometida o un script podían spamear alertas SOS falsas
    // — cada una manda correo a los contactos de confianza. 5/min alcanza de
    // sobra para una emergencia real y frena el abuso.
    Route::post('/carreras/{ride}/sos', [SosAlertController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('sos.store');

    // Perfil público y directorio de conductores (sección 3.4 y 3.6).
    Route::get('/perfil/{user}', [PublicProfileController::class, 'show'])->name('profiles.show');
    Route::get('/conductores', [DriverDirectoryController::class, 'index'])->name('directory.index');

    // "Mi plan" (sección 7 y 7.5): catálogo, plan vigente y cupo usado, para
    // cada rol por separado — un mismo usuario puede tener un plan de
    // conductor y otro de cliente al mismo tiempo (sección 3.1).
    Route::get('/mi-plan/conductor', [MyPlanController::class, 'driver'])->name('driver.plan.edit');
    Route::get('/mi-plan/cliente', [MyPlanController::class, 'client'])->name('client.plan.edit');

    // Elegir un plan + subir comprobante de pago (consideración agregada al
    // alcance) — sigue sin haber pasarela de pago, esto solo junta el pedido
    // para que un admin lo revise y active la suscripción real.
    Route::post('/mi-plan/pedidos', [SubscriptionRequestController::class, 'store'])->name('subscription-requests.store');
    // Auditoría de seguridad: sin límite, se podía spamear la subida de
    // comprobantes (cada uno queda en disco).
    Route::post('/mi-plan/pedidos/{subscriptionRequest}/comprobante', [SubscriptionRequestController::class, 'uploadProof'])
        ->middleware('throttle:6,1')
        ->name('subscription-requests.upload-proof');
    Route::delete('/mi-plan/pedidos/{subscriptionRequest}', [SubscriptionRequestController::class, 'cancel'])->name('subscription-requests.cancel');
    // Auditoría de seguridad: el comprobante vive en disco privado — este
    // endpoint lo sirve solo a quien lo subió o a un admin (ver
    // SubscriptionRequestController::paymentProof()).
    Route::get('/mi-plan/pedidos/{subscriptionRequest}/comprobante-archivo', [SubscriptionRequestController::class, 'paymentProof'])->name('subscription-requests.payment-proof');

    // Expresos (sección 4): rutas fijas y recurrentes. Lado cliente: publicar,
    // administrar postulaciones y ver el historial de carreras generadas.
    Route::get('/expresos', [ExpressRouteController::class, 'index'])->name('express-routes.index');
    Route::post('/expresos', [ExpressRouteController::class, 'store'])->name('express-routes.store');
    Route::get('/expresos/{route}', [ExpressRouteController::class, 'show'])->name('express-routes.show');
    Route::patch('/expresos/{route}', [ExpressRouteController::class, 'update'])->name('express-routes.update');
    Route::post('/expresos/{route}/pausar', [ExpressRouteController::class, 'pause'])->name('express-routes.pause');
    Route::post('/expresos/{route}/reanudar', [ExpressRouteController::class, 'resume'])->name('express-routes.resume');
    Route::post('/expresos/{route}/cancelar', [ExpressRouteController::class, 'cancel'])->name('express-routes.cancel');
    Route::post('/expresos/{route}/incidentes', [ExpressIncidentController::class, 'store'])->name('express-incidents.store');

    // Lado conductor: ofertas de Expreso abiertas de sus clientes, postularse
    // y administrar su propia postulación.
    Route::get('/expresos-disponibles', [ExpressRouteController::class, 'available'])->name('express-routes.available');
    Route::post('/expresos/{route}/postulaciones', [ExpressApplicationController::class, 'store'])->name('express-applications.store');
    Route::post('/postulaciones/{application}/aceptar', [ExpressApplicationController::class, 'accept'])->name('express-applications.accept');
    Route::post('/postulaciones/{application}/rechazar', [ExpressApplicationController::class, 'reject'])->name('express-applications.reject');
    Route::post('/postulaciones/{application}/retirar', [ExpressApplicationController::class, 'withdraw'])->name('express-applications.withdraw');

    // Compartir un Expreso con otros clientes de ruta parecida (pedido
    // explícito del usuario): buscar Expresos abiertos a compartir cerca de
    // la propia ruta, pedir sumarse, y que el dueño acepte/rechace.
    Route::get('/expresos-compartidos', [ExpressRouteCompanionController::class, 'discover'])->name('express-companions.discover');
    Route::post('/expresos/{route}/compartidos', [ExpressRouteCompanionController::class, 'store'])->name('express-companions.store');
    Route::post('/compartidos/{companion}/aceptar', [ExpressRouteCompanionController::class, 'accept'])->name('express-companions.accept');
    Route::post('/compartidos/{companion}/rechazar', [ExpressRouteCompanionController::class, 'reject'])->name('express-companions.reject');
    Route::post('/compartidos/{companion}/salir', [ExpressRouteCompanionController::class, 'leave'])->name('express-companions.leave');

    // Nuevo servicio para conductores tipo VAN/buseta/microbús/turístico
    // (pedido explícito del usuario): viajes programados puntuales, con
    // reserva de asientos — gateado por plan (van_trips_enabled).
    Route::get('/van', [VanTripController::class, 'index'])->name('van-trips.index');
    Route::post('/van', [VanTripController::class, 'store'])->name('van-trips.store');
    Route::get('/van/explorar', [VanTripController::class, 'browse'])->name('van-trips.browse');
    Route::get('/van/{vanTrip}', [VanTripController::class, 'show'])->name('van-trips.show');
    Route::post('/van/{vanTrip}/cancelar', [VanTripController::class, 'cancel'])->name('van-trips.cancel');
    Route::post('/van/{vanTrip}/reservas', [VanTripReservationController::class, 'store'])->name('van-trip-reservations.store');
    Route::post('/reservas-van/{reservation}/cancelar', [VanTripReservationController::class, 'cancel'])->name('van-trip-reservations.cancel');

    // Notificaciones push del navegador (sección 9.2 y 9.5): guarda la
    // suscripción del dispositivo para poder avisarle aunque no tenga la app abierta.
    Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store'])->name('push-subscriptions.store');
    Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');

    // Centro de cupones y beneficios (pedido explícito del usuario): cada
    // cuenta ve las promociones de su propio rol (cliente o conductor, nunca
    // las dos — sección 3.1), administradas desde /admin/cupones.
    Route::get('/cupones', [CouponController::class, 'index'])->name('coupons.index');

    // Centro de Ayuda / Soporte (roadmap de mejoras, secciones 11 y 12).
    Route::get('/soporte', [SupportController::class, 'index'])->name('support.index');
    Route::post('/soporte/mensajes', [SupportController::class, 'storeMessage'])->name('support.messages.store');

    // Contactos de confianza (sección 8): a quién avisar desde el botón SOS.
    Route::get('/contactos-de-confianza', [TrustedContactController::class, 'index'])->name('trusted-contacts.index');
    Route::post('/contactos-de-confianza', [TrustedContactController::class, 'store'])->name('trusted-contacts.store');
    Route::delete('/contactos-de-confianza/{contact}', [TrustedContactController::class, 'destroy'])->name('trusted-contacts.destroy');

    // "Mis rutas" (pedido explícito del usuario): origen+destino guardados
    // para pedir la próxima carrera sin escribir ni marcar nada de nuevo.
    Route::post('/mis-rutas', [SavedRouteController::class, 'store'])->name('saved-routes.store');
    Route::delete('/mis-rutas/{savedRoute}', [SavedRouteController::class, 'destroy'])->name('saved-routes.destroy');
});

// Panel admin (sección 9.5-C): activación manual de suscripciones (7.5),
// mantenimiento del catálogo de planes y de las tarifas, e indicadores
// básicos — todo acotado a usuarios con is_admin.
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    // Perfil completo de un usuario (pedido explícito del usuario): toda la
    // información relevante de un conductor o cliente en una sola pantalla,
    // sin tener que navegar entre suscripciones/verificaciones/flotas.
    Route::get('/usuarios/{user}', [AdminUserProfileController::class, 'show'])->name('users.show');
    Route::post('/usuarios/{user}/reactivar', [AdminUserProfileController::class, 'unlock'])->name('users.unlock');

    Route::get('/suscripciones', [AdminSubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('/suscripciones', [AdminSubscriptionController::class, 'store'])->name('subscriptions.store');
    Route::post('/suscripciones/{subscription}/expirar', [AdminSubscriptionController::class, 'expire'])->name('subscriptions.expire');

    // Comprobantes de pago de pedidos de plan (consideración agregada al
    // alcance): aprobar activa la suscripción real, rechazar deja que el
    // usuario suba uno nuevo.
    Route::post('/pedidos-plan/{subscriptionRequest}/aprobar', [AdminSubscriptionController::class, 'approveRequest'])->name('subscription-requests.approve');
    Route::post('/pedidos-plan/{subscriptionRequest}/rechazar', [AdminSubscriptionController::class, 'rejectRequest'])->name('subscription-requests.reject');

    Route::get('/metricas', [MetricsController::class, 'index'])->name('metrics.index');

    // Mantenimiento del catálogo de planes (secciones 7.2 y 7.3): nada de
    // esto queda quemado en código, se administra por completo desde acá.
    Route::get('/planes', [PlanController::class, 'index'])->name('plans.index');
    Route::post('/planes', [PlanController::class, 'store'])->name('plans.store');
    Route::patch('/planes/{plan}', [PlanController::class, 'update'])->name('plans.update');
    Route::delete('/planes/{plan}', [PlanController::class, 'destroy'])->name('plans.destroy');

    // Promociones de precio por tiempo limitado en los planes (pedido
    // explícito del usuario): "regalar o promocionar" un plan entre tal
    // fecha y tal fecha — ver Admin\PlanPromotionController.
    Route::get('/promociones', [PlanPromotionController::class, 'index'])->name('plan-promotions.index');
    Route::post('/promociones', [PlanPromotionController::class, 'store'])->name('plan-promotions.store');
    Route::patch('/promociones/{planPromotion}', [PlanPromotionController::class, 'update'])->name('plan-promotions.update');
    Route::delete('/promociones/{planPromotion}', [PlanPromotionController::class, 'destroy'])->name('plan-promotions.destroy');

    // Mantenimiento de las medallas del conductor (pedido explícito del
    // usuario): a partir de cuántos puntos aplica cada una, y si aparece en
    // el directorio público — ver App\Models\DriverTier.
    Route::get('/medallas', [DriverTierController::class, 'index'])->name('driver-tiers.index');
    Route::post('/medallas', [DriverTierController::class, 'store'])->name('driver-tiers.store');
    Route::patch('/medallas/{driverTier}', [DriverTierController::class, 'update'])->name('driver-tiers.update');
    Route::delete('/medallas/{driverTier}', [DriverTierController::class, 'destroy'])->name('driver-tiers.destroy');

    // Mantenimiento del cálculo de precio sugerido (sección 5): recargo y horario nocturno.
    Route::get('/tarifas', [PricingSettingController::class, 'edit'])->name('pricing.edit');
    Route::patch('/tarifas', [PricingSettingController::class, 'update'])->name('pricing.update');

    // Catálogo de "Motivos de Calificación" (pedido explícito del usuario):
    // administrable desde acá, sin tocar código — ver RatingReasonController.
    Route::get('/motivos-calificacion', [RatingReasonController::class, 'index'])->name('rating-reasons.index');
    Route::post('/motivos-calificacion', [RatingReasonController::class, 'store'])->name('rating-reasons.store');
    Route::patch('/motivos-calificacion/{ratingReason}', [RatingReasonController::class, 'update'])->name('rating-reasons.update');

    // Preguntas frecuentes del Centro de Ayuda (roadmap de mejoras, sección 11).
    Route::get('/preguntas-frecuentes', [FaqController::class, 'index'])->name('faqs.index');
    Route::post('/preguntas-frecuentes', [FaqController::class, 'store'])->name('faqs.store');
    Route::patch('/preguntas-frecuentes/{faq}', [FaqController::class, 'update'])->name('faqs.update');
    Route::delete('/preguntas-frecuentes/{faq}', [FaqController::class, 'destroy'])->name('faqs.destroy');

    // Soporte (roadmap de mejoras, sección 12): tickets de "Hablar con soporte".
    Route::get('/soporte', [SupportTicketController::class, 'index'])->name('support-tickets.index');
    Route::get('/soporte/{supportTicket}', [SupportTicketController::class, 'show'])->name('support-tickets.show');
    Route::post('/soporte/{supportTicket}/mensajes', [SupportTicketController::class, 'reply'])->name('support-tickets.reply');
    Route::patch('/soporte/{supportTicket}/estado', [SupportTicketController::class, 'updateStatus'])->name('support-tickets.update-status');

    // Opiniones del Home público (roadmap de mejoras, sección 14).
    Route::get('/opiniones', [AdminPlatformFeedbackController::class, 'index'])->name('platform-feedback.index');
    Route::patch('/opiniones/{platformFeedback}', [AdminPlatformFeedbackController::class, 'update'])->name('platform-feedback.update');

    // Módulo de publicidad y promociones (pedido explícito del usuario):
    // banners tipo slider, vendibles a negocios aliados — ver AdBannerController.
    Route::get('/banners', [AdBannerController::class, 'index'])->name('ad-banners.index');
    Route::post('/banners', [AdBannerController::class, 'store'])->name('ad-banners.store');
    Route::post('/banners/{adBanner}', [AdBannerController::class, 'update'])->name('ad-banners.update');
    Route::delete('/banners/{adBanner}', [AdBannerController::class, 'destroy'])->name('ad-banners.destroy');

    // Centro de cupones y beneficios (pedido explícito del usuario): promos
    // de comercios aliados, separadas por audiencia cliente/conductor.
    Route::get('/cupones', [AdminCouponController::class, 'index'])->name('coupons.index');
    Route::post('/cupones', [AdminCouponController::class, 'store'])->name('coupons.store');
    Route::post('/cupones/{coupon}', [AdminCouponController::class, 'update'])->name('coupons.update');
    Route::delete('/cupones/{coupon}', [AdminCouponController::class, 'destroy'])->name('coupons.destroy');

    // Alertas SOS (sección 8): auditoría, no acción — un admin solo mira el registro.
    Route::get('/alertas-sos', [AdminSosAlertController::class, 'index'])->name('sos-alerts.index');

    // Panel de conductores (pedido explícito del usuario): quién está
    // disponible ahora mismo con su ubicación, y bloquear/deshabilitar/
    // desconectar a un conductor puntual.
    Route::get('/conductores', [AdminDriverController::class, 'index'])->name('drivers.index');
    Route::post('/conductores/{driverProfile}/suspender', [AdminDriverController::class, 'suspend'])->name('drivers.suspend');
    Route::post('/conductores/{driverProfile}/reactivar', [AdminDriverController::class, 'reactivate'])->name('drivers.reactivate');

    // Panel de clientes registrados (pedido explícito del usuario): mismo
    // criterio que el de conductores, del otro lado — ver Admin\ClientController.
    Route::get('/clientes', [AdminClientController::class, 'index'])->name('clients.index');

    // Centro de operaciones (pedido explícito del usuario): concentración de
    // solicitudes activas, conectados, demanda por horario/zona, y avisar a
    // los conductores cercanos dónde conviene estar.
    Route::get('/operaciones', [AdminOperationsController::class, 'index'])->name('operations.index');
    Route::post('/operaciones/avisar-demanda', [AdminOperationsController::class, 'notifyNearby'])->name('operations.notify-demand');

    // Verificación de identidad de conductores (sección 8 y 9.5-C).
    Route::get('/verificaciones', [DriverVerificationController::class, 'index'])->name('driver-verifications.index');
    Route::post('/verificaciones/{driverProfile}/aprobar', [DriverVerificationController::class, 'approve'])->name('driver-verifications.approve');
    Route::post('/verificaciones/{driverProfile}/rechazar', [DriverVerificationController::class, 'reject'])->name('driver-verifications.reject');

    // Catálogo de "zonas del Ecuador" (ciudades y sectores/barrios,
    // consideración agregada al alcance): de acá sale la lista que se usa al
    // pedir una carrera para indicar origen/destino sin abrir el mapa.
    Route::get('/zonas', [LocationController::class, 'index'])->name('locations.index');
    Route::post('/zonas/ciudades', [LocationController::class, 'storeCity'])->name('cities.store');
    Route::patch('/zonas/ciudades/{city}', [LocationController::class, 'updateCity'])->name('cities.update');
    Route::delete('/zonas/ciudades/{city}', [LocationController::class, 'destroyCity'])->name('cities.destroy');
    Route::post('/zonas/ciudades/{city}/sectores', [LocationController::class, 'storeSector'])->name('sectors.store');
    Route::patch('/zonas/sectores/{sector}', [LocationController::class, 'updateSector'])->name('sectors.update');
    Route::delete('/zonas/sectores/{sector}', [LocationController::class, 'destroySector'])->name('sectors.destroy');

    // "Zona de peligro" (pedido explícito del usuario): borrar toda la data
    // de prueba (@arka01.test) y dejar el sistema reiniciado — ver Admin\SystemController.
    Route::get('/sistema', [AdminSystemController::class, 'index'])->name('system.index');
    Route::post('/sistema/borrar-demo', [AdminSystemController::class, 'resetDemo'])->name('system.reset-demo');

    // Configuración → Integraciones → WhatsApp (roadmap de mejoras, sección
    // 8): evita tener que tocar el .env para cambiar el token.
    Route::get('/integraciones/whatsapp', [WhatsAppSettingController::class, 'edit'])->name('integrations.whatsapp.edit');
    Route::patch('/integraciones/whatsapp', [WhatsAppSettingController::class, 'update'])->name('integrations.whatsapp.update');

    // Monitoreo (roadmap de mejoras, sección 9): errores/eventos críticos
    // sin tener que entrar a storage/logs.
    Route::get('/monitoreo', [SystemEventController::class, 'index'])->name('monitoring.index');
    Route::post('/monitoreo/{systemEvent}/resolver', [SystemEventController::class, 'markResolved'])->name('monitoring.resolve');
});

// Seguimiento en vivo compartible (sección 8): páginas públicas, sin login,
// protegidas por firma temporal en vez de autenticación (middleware 'signed').
Route::middleware('signed')->group(function () {
    Route::get('/seguimiento/{ride}', [PublicRideTrackingController::class, 'show'])->name('public.rides.track');
    Route::get('/seguimiento/{ride}/estado', [PublicRideTrackingController::class, 'status'])->name('public.rides.track.status');
});

// "Referí a tu conductor" (pedido explícito del usuario): landing pública,
// sin login — cualquiera puede ver a quién le están recomendando antes de
// decidir crear una cuenta. El `invite_code` ya es un identificador propio
// (aleatorio, de 8 caracteres) que cumple el mismo papel que una firma.
Route::get('/referir/{driverProfile:invite_code}', [ReferralController::class, 'show'])->name('referrals.show');

require __DIR__.'/auth.php';
