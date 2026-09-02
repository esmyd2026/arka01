<?php

use App\Http\Controllers\Admin\AdBannerController;
use App\Http\Controllers\Admin\ChatbotIntentController;
use App\Http\Controllers\Admin\ChatbotSettingController;
use App\Http\Controllers\Admin\ChatbotUnrecognizedController;
use App\Http\Controllers\Admin\ClientController as AdminClientController;
use App\Http\Controllers\Admin\CooperativeController as AdminCooperativeController;
use App\Http\Controllers\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Admin\DriverController as AdminDriverController;
use App\Http\Controllers\Admin\DriverTierController;
use App\Http\Controllers\Admin\DriverVerificationController;
use App\Http\Controllers\Admin\FaqController;
use App\Http\Controllers\Admin\LiveOperationsController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\MetricsController;
use App\Http\Controllers\Admin\OperationsController as AdminOperationsController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\PlanCouponController;
use App\Http\Controllers\Admin\PlanPromotionController;
use App\Http\Controllers\Admin\PlatformFeedbackController as AdminPlatformFeedbackController;
use App\Http\Controllers\Admin\PricingSettingController;
use App\Http\Controllers\Admin\RatingReasonController;
use App\Http\Controllers\Admin\ReferralController as AdminReferralController;
use App\Http\Controllers\Admin\RideController as AdminRideController;
use App\Http\Controllers\Admin\SiteSettingController;
use App\Http\Controllers\Admin\SosAlertController as AdminSosAlertController;
use App\Http\Controllers\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Admin\SupportTicketController;
use App\Http\Controllers\Admin\SurveyMetricsController;
use App\Http\Controllers\Admin\SystemController as AdminSystemController;
use App\Http\Controllers\Admin\SystemEventController;
use App\Http\Controllers\Admin\UserLocationsController;
use App\Http\Controllers\Admin\UserProfileController as AdminUserProfileController;
use App\Http\Controllers\Admin\WhatsAppInboxController;
use App\Http\Controllers\Admin\WhatsAppSettingController;
use App\Http\Controllers\CooperativeBankAccountController;
use App\Http\Controllers\CooperativeClientController;
use App\Http\Controllers\CooperativeDashboardController;
use App\Http\Controllers\CooperativeDirectoryController;
use App\Http\Controllers\CooperativeDriverController;
use App\Http\Controllers\CooperativeProfileController;
use App\Http\Controllers\CooperativeRideAssignmentController;
use App\Http\Controllers\CooperativeWalletController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DriverBankAccountController;
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
use App\Http\Controllers\GuestRideController;
use App\Http\Controllers\LandingCtaEventController;
use App\Http\Controllers\MapRouteController;
use App\Http\Controllers\MyPlanController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PlatformFeedbackController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicProfileController;
use App\Http\Controllers\PublicRideTrackingController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\RadioChannelController;
use App\Http\Controllers\RadioSessionController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RideController;
use App\Http\Controllers\RideMessageController;
use App\Http\Controllers\RidePaymentController;
use App\Http\Controllers\RideRequestController;
use App\Http\Controllers\SavedRouteController;
use App\Http\Controllers\SosAlertController;
use App\Http\Controllers\SubscriptionRequestController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\SurveyController;
use App\Http\Controllers\TrustCircleController;
use App\Http\Controllers\TrustedContactController;
use App\Http\Controllers\VanTripController;
use App\Http\Controllers\VanTripReservationController;
use App\Http\Controllers\WhatsAppLocationPickerController;
use App\Models\Cooperative;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
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
    $ctaToken = Str::random(48);
    session([
        'landing_cta_token' => $ctaToken,
        'landing_cta_issued_at' => now()->timestamp,
        'landing_cta_recorded_events' => [],
    ]);

    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        // Pedido explícito del usuario: imagen de fondo del hero,
        // configurable desde /admin/sitio — ver App\Models\SiteSetting.
        'heroBackgroundUrl' => SiteSetting::current()->hero_background_url,
        'ctaInteractionToken' => $ctaToken,
        'guestCooperatives' => Cooperative::query()
            ->where('status', 'approved')
            ->whereNull('suspended_at')
            ->whereNotNull('stand_lat')
            ->whereNotNull('stand_lng')
            ->withCount('activeDriverMemberships')
            ->orderBy('name')
            ->get(['id', 'name', 'logo_path', 'stand_lat', 'stand_lng']),
    ]);
});

Route::post('/interacciones/portada', [LandingCtaEventController::class, 'store'])
    ->middleware('throttle:12,1,landing-cta.store')
    ->name('landing-cta.store');

Route::post('/viajar-como-invitado', [GuestRideController::class, 'store'])
    ->middleware(['guest', 'throttle:4,1,guest-rides.store'])
    ->name('guest-rides.store');

// "Ayúdanos a mejorar ARKA01" (roadmap de mejoras, sección 14): formulario
// público en el Home, sin necesidad de sesión — throttle porque no hay
// cuenta detrás que limite cuántas veces se puede mandar.
Route::post('/opiniones', [PlatformFeedbackController::class, 'store'])
    ->middleware('throttle:6,1,platform-feedback.store')
    ->name('platform-feedback.store');

// Encuesta corta de conductor/pasajero (pedido explícito del usuario:
// "no necesita tener usuario para hacer la encuesta") — pública, accesible
// desde el Home y desde el login por igual, con o sin sesión iniciada. Sin
// middleware 'guest' a propósito (a diferencia de guest-rides.store, más
// arriba): alguien YA logueado también tiene que poder responderla desde el Home.
Route::get('/encuesta', [SurveyController::class, 'show'])->name('survey.show');
Route::post('/encuesta', [SurveyController::class, 'store'])
    ->middleware('throttle:10,1,survey.store')
    ->name('survey.store');

// Páginas legales (pedido explícito del usuario, gap identificado antes del
// despliegue): públicas, sin necesidad de sesión — hace falta poder verlas
// antes de registrarse.
Route::get('/terminos', function () {
    return Inertia::render('Legal/Terms', [
        // Pedido explícito del usuario ("por el momento quemá el correo"):
        // fijo en vez de depender de MAIL_FROM_ADDRESS — evita que quede
        // mostrando el placeholder de Laravel si esa variable no está bien
        // completada en el .env del servidor.
        'contactEmail' => 'soporte@arka01.com',
        'updatedAt' => '2026-08-09',
    ]);
})->name('legal.terms');

Route::get('/privacidad', function () {
    return Inertia::render('Legal/Privacy', [
        'contactEmail' => 'soporte@arka01.com',
        'updatedAt' => '2026-08-09',
    ]);
})->name('legal.privacy');

// Enlace compartible por WhatsApp u otra red. El código es aleatorio y
// revocable; no revela el ID del canal ni de quien lo creó.
Route::get('/radio/invitacion/{radioChannel:share_code}', [RadioChannelController::class, 'showInvitation'])
    ->middleware('throttle:60,1,radio.invitation.show')
    ->name('radio.invitation.show');

// Mapa para elegir origen/destino desde el bot de WhatsApp (pedido explícito
// del usuario): el enlace lo manda WhatsAppRideBookingHandler::askLocation()
// junto con el pedido de dirección escrita — público a propósito, quien lo
// abre viene desde WhatsApp sin sesión en la app; la firma temporal (30 min,
// atada a la conversación) es la única protección, mismo patrón que
// guest-account.complete-registration en routes/auth.php.
Route::get('/whatsapp/ubicacion/{conversation}/{step}', [WhatsAppLocationPickerController::class, 'show'])
    ->where('step', 'origin|destination')
    ->middleware('signed')
    ->name('whatsapp.location-picker.show');
Route::post('/whatsapp/ubicacion/{conversation}/{step}', [WhatsAppLocationPickerController::class, 'store'])
    ->where('step', 'origin|destination')
    ->middleware(['signed', 'throttle:20,1,whatsapp.location-picker.store'])
    ->name('whatsapp.location-picker.store');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified', 'phone_verified', 'driver_onboarding'])
    ->name('dashboard');

Route::post('/dashboard/ubicacion', [DashboardController::class, 'updateLocation'])
    ->middleware(['auth', 'verified', 'phone_verified', 'throttle:12,1,dashboard.location.update'])
    ->name('dashboard.location.update');

Route::middleware('auth')->group(function () {
    // Canal personal de seguridad: su membresía persiste, pero el audio solo
    // se habilita mientras el propietario solicita o realiza una carrera.
    Route::get('/radio/status', [RadioSessionController::class, 'status'])
        ->middleware('throttle:60,1,radio.status')
        ->name('radio.status');
    Route::post('/radio/session', RadioSessionController::class)
        ->middleware('throttle:30,1,radio.session')
        ->name('radio.session');
    Route::post('/radio/invitacion/{radioChannel:share_code}', [RadioChannelController::class, 'join'])
        ->middleware('throttle:12,1,radio.invitation.join')
        ->name('radio.invitation.join');
    Route::patch('/radio/canales/{radioChannel:public_id}', [RadioChannelController::class, 'update'])
        ->name('radio.channels.update');
    Route::post('/radio/canales/{radioChannel:public_id}/renovar-enlace', [RadioChannelController::class, 'rotateInvitation'])
        ->middleware('throttle:6,1,radio.channels.rotate-invitation')
        ->name('radio.channels.rotate-invitation');
    Route::delete('/radio/canales/{radioChannel:public_id}/miembros/{memberPublicId}', [RadioChannelController::class, 'removeMember'])
        ->name('radio.channels.members.destroy');
    Route::delete('/radio/canales/{radioChannel:public_id}/salir', [RadioChannelController::class, 'leave'])
        ->name('radio.channels.leave');

    // Recorrido guiado por rol, una sola vez (pedido explícito del usuario).
    Route::post('/onboarding/completar', [OnboardingController::class, 'complete'])->name('onboarding.complete');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    // "¿Quién lo recomendó?" (pedido explícito del usuario): buscar en la
    // plataforma por nombre, usuario o código, y guardarlo una sola vez
    // como referido — mismo mecanismo para cualquier rol (cliente o
    // conductor), por eso vive en ProfileController y no en uno separado.
    Route::get('/perfil/buscar-referido', [ProfileController::class, 'searchReferrer'])->name('profile.search-referrer');
    Route::post('/perfil/referido', [ProfileController::class, 'setReferrer'])->name('profile.set-referrer');

    // Perfil y documentos privados de la cuenta Cooperativa. El registro
    // crea la cuenta base; este formulario completa la postulación legal.
    // Pedido explícito del usuario: "las cooperativas deberian ser como los
    // conductores, si no me llenan todo no pueden ir a mas ningun lado
    // hasta que yo les verifique y les apruebe" — estas 4 rutas son
    // justamente las que necesita para completar y enviar su postulación,
    // así que quedan siempre accesibles sin el gate de abajo.
    Route::middleware('cooperative')->group(function () {
        Route::get('/cooperativa/perfil', [CooperativeProfileController::class, 'edit'])->name('cooperative.profile.edit');
        Route::post('/cooperativa/perfil', [CooperativeProfileController::class, 'update'])->name('cooperative.profile.update');
        Route::post('/cooperativa/perfil/enviar-revision', [CooperativeProfileController::class, 'submitForReview'])->name('cooperative.profile.submit-review');
        Route::post('/cooperativa/perfil/logo', [CooperativeProfileController::class, 'updateLogo'])->name('cooperative.profile.logo.update');
    });

    // El resto del panel de la cooperativa (despacho, conductores, billetera,
    // clientes, pagos) queda bloqueado hasta que un admin la apruebe — antes
    // solo se le avisaba con un banner en el dashboard, pero nada se lo
    // impedía de verdad (ver App\Http\Middleware\EnsureCooperativeIsApproved).
    Route::middleware(['cooperative', 'cooperative_approved'])->group(function () {
        Route::get('/cooperativa', [CooperativeDashboardController::class, 'index'])->name('cooperative.dashboard');
        Route::patch('/cooperativa/configuracion-despacho', [CooperativeDashboardController::class, 'updateDispatchSettings'])->name('cooperative.dispatch-settings.update');
        Route::get('/cooperativa/conductores', [CooperativeDriverController::class, 'index'])->name('cooperative.drivers.index');
        Route::get('/cooperativa/conductores/buscar', [CooperativeDriverController::class, 'search'])->name('cooperative.drivers.search');
        Route::post('/cooperativa/conductores/invitar', [CooperativeDriverController::class, 'invite'])->name('cooperative.drivers.invite');
        Route::get('/cooperativa/conductores/{membership}', [CooperativeDriverController::class, 'show'])->name('cooperative.drivers.show');
        Route::post('/cooperativa/conductores/{membership}/suspender', [CooperativeDriverController::class, 'suspend'])->name('cooperative.drivers.suspend');
        Route::post('/cooperativa/conductores/{membership}/reactivar', [CooperativeDriverController::class, 'reactivate'])->name('cooperative.drivers.reactivate');
        Route::delete('/cooperativa/conductores/{membership}', [CooperativeDriverController::class, 'remove'])->name('cooperative.drivers.remove');
        Route::post('/cooperativa/solicitudes/{rideRequest}/asignar', [CooperativeRideAssignmentController::class, 'assign'])->name('cooperative.rides.assign');
        // Pedido explícito del usuario: trazabilidad de todo el equipo,
        // cuánto facturó y el saldo de billetera agregado — antes solo se
        // podía ver conductor por conductor (cooperative.drivers.show).
        Route::get('/cooperativa/billetera', [CooperativeWalletController::class, 'index'])->name('cooperative.wallet');
        Route::post('/cooperativa/pagos/{ride}/confirmar-transferencia', [RidePaymentController::class, 'confirmTransfer'])->name('cooperative.payments.transfer.confirm');
        Route::post('/cooperativa/pagos/{ride}/rechazar-transferencia', [RidePaymentController::class, 'rejectTransfer'])->name('cooperative.payments.transfer.reject');
        Route::post('/cooperativa/cuentas-bancarias', [CooperativeBankAccountController::class, 'store'])->name('cooperative.bank-accounts.store');
        Route::delete('/cooperativa/cuentas-bancarias/{bankAccount}', [CooperativeBankAccountController::class, 'destroy'])->name('cooperative.bank-accounts.destroy');
        Route::patch('/cooperativa/cuentas-bancarias/{bankAccount}/principal', [CooperativeBankAccountController::class, 'markFavorite'])->name('cooperative.bank-accounts.favorite');
        // Pedido explícito del usuario: "quiero ver mis clientes vinculados
        // la lista, cantidad de carreras, puntuacion y desvincular" — ver
        // CooperativeClientController.
        Route::get('/cooperativa/clientes', [CooperativeClientController::class, 'index'])->name('cooperative.clients.index');
        Route::delete('/cooperativa/clientes/{clientCooperative}', [CooperativeClientController::class, 'destroy'])->name('cooperative.clients.destroy');
    });
    Route::get('/cooperativas/documentos/{document}', [CooperativeProfileController::class, 'document'])->name('cooperative.documents.show');

    // Directorio visible para cuentas de la plataforma y red del cliente.
    Route::get('/cooperativas', [CooperativeDirectoryController::class, 'index'])->name('cooperatives.index');
    Route::post('/cooperativas/{cooperative}/agregar', [CooperativeDirectoryController::class, 'attach'])->name('cooperatives.attach');
    Route::delete('/cooperativas/{cooperative}/retirar', [CooperativeDirectoryController::class, 'detach'])->name('cooperatives.detach');

    // El conductor siempre decide si acepta o rechaza el vínculo.
    Route::get('/cooperativas/invitaciones', [CooperativeDriverController::class, 'invitations'])->name('cooperative-driver-invitations.index');
    Route::post('/cooperativas/invitaciones/{membership}/responder', [CooperativeDriverController::class, 'respond'])->name('cooperative-driver-invitations.respond');

    // "Convertirme en conductor" / editar mi perfil de conductor (sección 9.5-B).
    Route::get('/driver/profile', [DriverProfileController::class, 'edit'])->name('driver.profile.edit');
    Route::post('/driver/profile', [DriverProfileController::class, 'update'])->name('driver.profile.update');
    // Auditoría de seguridad: la foto de licencia vive en disco privado —
    // este endpoint la sirve solo al propio conductor o a un admin (ver
    // DriverProfileController::licensePhoto()).
    Route::get('/driver/profile/{user}/licencia', [DriverProfileController::class, 'licensePhoto'])->name('driver-profile.license-photo');
    Route::get('/driver/profile/{user}/documentos/{type}', [DriverProfileController::class, 'document'])
        // Bug real: 'vehicle-registration' faltaba acá — DriverProfile::
        // getVehicleRegistrationUrlAttribute() sí generaba el link (route()
        // no valida whereIn al generar, solo al hacer match de una request
        // entrante), pero al hacer clic la matrícula siempre daba 404, tanto
        // para el conductor como para un admin.
        ->whereIn('type', ['identity', 'license', 'police-record', 'vehicle-registration'])
        ->name('driver-profile.document');
    // Pasar de conductor a cliente y volver (pedido explícito del usuario) —
    // ver DriverProfileController::deactivate()/reactivate().
    Route::post('/driver/profile/pasar-a-cliente', [DriverProfileController::class, 'deactivate'])->name('driver.profile.deactivate');
    Route::post('/driver/profile/reactivar', [DriverProfileController::class, 'reactivate'])->name('driver.profile.reactivate');

    // Cuentas bancarias del conductor (pedido explícito del usuario) — ver
    // App\Http\Controllers\DriverBankAccountController.
    Route::post('/driver/cuentas-bancarias', [DriverBankAccountController::class, 'store'])->name('driver.bank-accounts.store');
    Route::patch('/driver/cuentas-bancarias/{bankAccount}', [DriverBankAccountController::class, 'update'])->name('driver.bank-accounts.update');
    Route::delete('/driver/cuentas-bancarias/{bankAccount}', [DriverBankAccountController::class, 'destroy'])->name('driver.bank-accounts.destroy');
    Route::patch('/driver/cuentas-bancarias/{bankAccount}/favorita', [DriverBankAccountController::class, 'markFavorite'])->name('driver.bank-accounts.favorite');

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
    Route::post('/flota/solicitudes', [RideRequestController::class, 'store'])->middleware('throttle:10,1,ride-requests.store')->name('ride-requests.store');

    Route::get('/flota/{fleet}', [FleetController::class, 'show'])->name('fleet.show');
    // Auditoría de seguridad: buscador en vivo (debounce de 300ms del lado
    // del navegador, ver Fleet/Show.vue) que devuelve nombre/teléfono/ciudad
    // de otros usuarios — sin límite del lado del servidor, un script podía
    // barrer nombres/teléfonos reales probando términos de búsqueda uno
    // atrás de otro. 30/min deja de sobra margen para escribir a mano.
    Route::get('/flota/{fleet}/buscar-conductores', [FleetController::class, 'searchDrivers'])
        ->middleware('throttle:30,1,fleet.search-drivers')
        ->name('fleet.search-drivers');
    Route::post('/flota/{fleet}/invitaciones', [FleetInvitationController::class, 'store'])->name('fleet.invitations.store');
    Route::delete('/flota/invitaciones/{invitation}', [FleetInvitationController::class, 'destroy'])->name('fleet.invitations.destroy');
    Route::delete('/flota/miembros/{member}', [FleetMemberController::class, 'destroy'])->name('fleet.members.destroy');
    // "Recomendar mi flota" (pedido explícito del usuario): buscar a un
    // amigo (otro cliente) y recomendarle uno o varios conductores de esta
    // misma flota — mismo límite de auditoría de seguridad que el resto de
    // los buscadores por código.
    Route::get('/flota/{fleet}/referir/buscar-amigo', [FleetInvitationController::class, 'searchFriends'])
        ->middleware('throttle:30,1,fleet.referral.search-friends')
        ->name('fleet.referral.search-friends');
    Route::post('/flota/{fleet}/referir', [FleetInvitationController::class, 'storeReferral'])->name('fleet.referral.store');
    // Pedido explícito del usuario: un conductor busca clientes y les manda
    // una solicitud para unirse a su flota — dirección opuesta a la de
    // siempre, ver FleetInvitationController::storeFromDriver().
    Route::post('/flota-solicitudes', [FleetInvitationController::class, 'storeFromDriver'])->name('fleet-invitations.request');

    // "Referí a tu conductor" (pedido explícito del usuario): mandar la
    // invitación de verdad exige sesión de cliente — ver la ruta pública
    // GET más abajo, junto al seguimiento en vivo, para solo mirar el enlace.
    Route::post('/referir/{driverProfile:invite_code}', [ReferralController::class, 'store'])->name('referrals.store');

    // Mis clientes de confianza (lado conductor, sección 3.2 y 9.5-B).
    Route::get('/mis-clientes', [DriverInvitationController::class, 'index'])->name('driver.invitations.index');
    // Buscador de clientes existentes (pedido explícito del usuario) — mismo
    // criterio que fleet.search-drivers, del otro lado, mismo límite de
    // auditoría de seguridad.
    Route::get('/mis-clientes/buscar', [DriverInvitationController::class, 'searchClients'])
        ->middleware('throttle:30,1,driver.clients.search')
        ->name('driver.clients.search');
    Route::post('/mis-clientes/invitaciones/{invitation}/aceptar', [DriverInvitationController::class, 'accept'])->name('driver.invitations.accept');
    Route::post('/mis-clientes/invitaciones/{invitation}/rechazar', [DriverInvitationController::class, 'reject'])->name('driver.invitations.reject');
    Route::post('/mis-clientes/{member}/salir', [DriverInvitationController::class, 'leave'])->name('driver.fleets.leave');
    Route::post('/mis-clientes/{member}/solicitudes', [DriverInvitationController::class, 'toggleRequests'])->name('driver.fleets.toggle-requests');

    // Ruta Google calculada desde el servidor: protege la clave privada y
    // aplica caché/throttle para controlar costos.
    Route::post('/mapas/ruta', MapRouteController::class)
        ->middleware('throttle:30,1,maps.route')
        ->name('maps.route');

    // Ubicación en vivo del conductor (sección 9.3). El frontend la llama
    // cada ~15s (DriverAvailabilityToggle.vue) — 20/min da margen de sobra
    // sin dejar la puerta abierta a inundarla (pedido explícito del usuario:
    // rendimiento/seguridad de cara a producción).
    Route::post('/driver/location', [DriverLocationController::class, 'update'])->middleware('throttle:20,1,driver.location.update')->name('driver.location.update');

    // Resto de la negociación de precio de una solicitud (sección 3.5 y 5).
    Route::post('/solicitudes/{rideRequest}/aceptar', [RideRequestController::class, 'accept'])->name('ride-requests.accept');
    Route::post('/solicitudes/{rideRequest}/contraofertar', [RideRequestController::class, 'counter'])->name('ride-requests.counter');
    Route::post('/solicitudes/{rideRequest}/subir-oferta', [RideRequestController::class, 'raiseOffer'])->name('ride-requests.raise-offer');
    Route::post('/solicitudes/{rideRequest}/rechazar', [RideRequestController::class, 'reject'])->name('ride-requests.reject');
    Route::post('/solicitudes/{rideRequest}/cancelar', [RideRequestController::class, 'cancel'])->name('ride-requests.cancel');

    // Carreras (sección 3.5, 8 y 9.5).
    Route::get('/carreras', [RideController::class, 'index'])->name('rides.index');
    // Respaldo liviano del tiempo real: si el celular pierde por unos
    // segundos la conexión WebSocket, Carreras reconcilia solicitudes sin
    // obligar al usuario a recargar toda la página manualmente.
    Route::get('/carreras/sincronizar', [RideController::class, 'syncRequests'])
        ->middleware('throttle:12,1,rides.sync-requests')
        ->name('rides.sync-requests');
    // OJO con el orden (mismo caso que "/flota/solicitar"): "/carreras/{ride}"
    // es comodín, así que el tramo literal "indicadores" tiene que ir antes,
    // si no Laravel lo toma como {ride}="indicadores" y tira 404.
    Route::get('/carreras/indicadores', [DriverStatsController::class, 'index'])->name('rides.stats');
    Route::get('/carreras/{ride}', [RideController::class, 'show'])->name('rides.show');
    // Seguimiento GPS durante la carrera, separado del estado Disponible.
    // 30/min permite una posición cada pocos segundos sin aceptar abuso.
    Route::post('/carreras/{ride}/ubicacion', [RideController::class, 'updateLocation'])
        ->middleware('throttle:30,1,rides.location.update')
        ->name('rides.location.update');
    Route::post('/carreras/{ride}/arrancar', [RideController::class, 'start'])->name('rides.start');
    // "Ir por el pasajero" (pedido explícito del usuario, bug real con
    // captura) — ver RideController::headingToPassenger().
    Route::post('/carreras/{ride}/voy-por-el-pasajero', [RideController::class, 'headingToPassenger'])->name('rides.heading-to-passenger');
    Route::post('/carreras/{ride}/llegue', [RideController::class, 'arrived'])->name('rides.arrived');
    Route::post('/carreras/{ride}/recogido', [RideController::class, 'pickedUp'])->name('rides.picked-up');
    Route::post('/carreras/{ride}/transferencia-notificada', [RideController::class, 'notifyTransferPayment'])->name('rides.transfer-payment.notify');
    Route::post('/carreras/{ride}/comprobante-pago', [RidePaymentController::class, 'uploadProof'])->name('rides.payment-proof.store');
    Route::get('/carreras/{ride}/comprobante-pago', [RidePaymentController::class, 'proof'])->name('rides.payment-proof.show');
    Route::post('/carreras/{ride}/confirmar-efectivo', [RidePaymentController::class, 'confirmCash'])->name('rides.cash-payment.confirm');
    Route::post('/carreras/{ride}/cancelar', [RideController::class, 'cancel'])->name('rides.cancel');
    // Editar una carrera programada (pedido explícito del usuario: "si es
    // que se equivocaron") — el cliente propone, el conductor confirma o
    // rechaza, ver RideController::propose/confirm/rejectReschedule().
    Route::post('/carreras/{ride}/reprogramar', [RideController::class, 'proposeReschedule'])->name('rides.reschedule.propose');
    Route::post('/carreras/{ride}/reprogramar/confirmar', [RideController::class, 'confirmReschedule'])->name('rides.reschedule.confirm');
    Route::post('/carreras/{ride}/reprogramar/rechazar', [RideController::class, 'rejectReschedule'])->name('rides.reschedule.reject');
    Route::post('/carreras/{ride}/completar', [RideController::class, 'complete'])->name('rides.complete');
    Route::post('/carreras/{ride}/paradas/{stop}/completar', [RideController::class, 'completeStop'])->name('rides.stops.complete');
    // Chat temporal cliente↔conductor (sección 10 del roadmap de mejoras).
    Route::post('/carreras/{ride}/mensajes', [RideMessageController::class, 'store'])->name('ride-messages.store');
    Route::post('/carreras/{ride}/calificar', [ReviewController::class, 'store'])->name('reviews.store');
    Route::get('/carreras/{ride}/seguimiento', [RideController::class, 'trackingLink'])->name('rides.tracking-link');
    // Auditoría de seguridad (pedido explícito del usuario): sin límite,
    // una cuenta comprometida o un script podían spamear alertas SOS falsas
    // — cada una manda correo a los contactos de confianza. 5/min alcanza de
    // sobra para una emergencia real y frena el abuso.
    Route::post('/carreras/{ride}/sos', [SosAlertController::class, 'store'])
        ->middleware('throttle:5,1,sos.store')
        ->name('sos.store');

    // Directorio de conductores (sección 3.4) — a diferencia del perfil
    // público puntual, este sí se deja atrás del login (listar a TODOS los
    // conductores públicos de un saque es una superficie de scraping mucho
    // mayor que compartir un perfil concreto).
    Route::get('/conductores', [DriverDirectoryController::class, 'index'])->name('directory.index');

    // "Mi plan" (sección 7 y 7.5): catálogo, plan vigente y cupo usado, para
    // cada rol por separado — un mismo usuario puede tener un plan de
    // conductor y otro de cliente al mismo tiempo (sección 3.1).
    Route::get('/mi-plan/conductor', [MyPlanController::class, 'driver'])->name('driver.plan.edit');
    Route::get('/mi-plan/cliente', [MyPlanController::class, 'client'])->name('client.plan.edit');
    Route::get('/mi-plan/cooperativa', [MyPlanController::class, 'cooperative'])->name('cooperative.plan.edit');

    // Elegir un plan + subir comprobante de pago (consideración agregada al
    // alcance) — sigue sin haber pasarela de pago, esto solo junta el pedido
    // para que un admin lo revise y active la suscripción real.
    Route::post('/mi-plan/pedidos', [SubscriptionRequestController::class, 'store'])->name('subscription-requests.store');
    // Auditoría de seguridad: sin límite, se podía spamear la subida de
    // comprobantes (cada uno queda en disco).
    Route::post('/mi-plan/pedidos/{subscriptionRequest}/comprobante', [SubscriptionRequestController::class, 'uploadProof'])
        ->middleware('throttle:6,1,subscription-requests.upload-proof')
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
    Route::post('/compartidos/{companion}/confirmar-conductor', [ExpressRouteCompanionController::class, 'driverAccept'])->name('express-companions.driver-accept');
    Route::post('/compartidos/{companion}/rechazar-conductor', [ExpressRouteCompanionController::class, 'driverReject'])->name('express-companions.driver-reject');
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

    // Círculo social privado entre cuentas reales. Es distinto de los
    // contactos SOS: aquí ambas personas deben aceptar la conexión y cada
    // una controla si comparte su flota y su índice con la otra.
    Route::get('/circulo-de-confianza', [TrustCircleController::class, 'index'])->name('trust-circle.index');
    Route::get('/circulo-de-confianza/buscar', [TrustCircleController::class, 'search'])
        ->middleware('throttle:30,1,trust-circle.search')
        ->name('trust-circle.search');
    Route::post('/circulo-de-confianza/solicitudes', [TrustCircleController::class, 'store'])
        ->middleware('throttle:10,1,trust-circle.store')
        ->name('trust-circle.store');
    Route::post('/circulo-de-confianza/solicitudes/{connection}/responder', [TrustCircleController::class, 'respond'])->name('trust-circle.respond');
    Route::put('/circulo-de-confianza/{connection}/privacidad', [TrustCircleController::class, 'updateSettings'])->name('trust-circle.settings.update');
    Route::delete('/circulo-de-confianza/{connection}', [TrustCircleController::class, 'destroy'])->name('trust-circle.destroy');
    Route::post('/circulo-de-confianza/invitar-conductor', [TrustCircleController::class, 'inviteDriver'])->name('trust-circle.drivers.invite');

    // "Mis rutas" (pedido explícito del usuario): origen+destino guardados
    // para pedir la próxima carrera sin escribir ni marcar nada de nuevo.
    Route::post('/mis-rutas', [SavedRouteController::class, 'store'])->name('saved-routes.store');
    Route::delete('/mis-rutas/{savedRoute}', [SavedRouteController::class, 'destroy'])->name('saved-routes.destroy');
});

// Panel admin (sección 9.5-C): activación manual de suscripciones (7.5),
// mantenimiento del catálogo de planes y de las tarifas, e indicadores
// básicos — todo acotado a usuarios con is_admin.
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/cooperativas', [AdminCooperativeController::class, 'index'])->name('cooperatives.index');
    Route::get('/cooperativas/{cooperative}', [AdminCooperativeController::class, 'show'])->name('cooperatives.show');
    Route::post('/cooperativas/{cooperative}/revisar', [AdminCooperativeController::class, 'markInReview'])->name('cooperatives.review');
    Route::post('/cooperativas/{cooperative}/aprobar', [AdminCooperativeController::class, 'approve'])->name('cooperatives.approve');
    Route::post('/cooperativas/{cooperative}/rechazar', [AdminCooperativeController::class, 'reject'])->name('cooperatives.reject');
    Route::post('/cooperativas/{cooperative}/suspender', [AdminCooperativeController::class, 'suspend'])->name('cooperatives.suspend');
    Route::post('/cooperativas/{cooperative}/reactivar', [AdminCooperativeController::class, 'reactivate'])->name('cooperatives.reactivate');
    Route::patch('/cooperativas/{cooperative}/whatsapp', [AdminCooperativeController::class, 'updateWhatsApp'])->name('cooperatives.whatsapp');
    Route::patch('/cooperativas/{cooperative}/publica', [AdminCooperativeController::class, 'updatePublicVisibility'])->name('cooperatives.public-visibility');
    Route::post('/cooperativas/documentos/{document}/revisar', [AdminCooperativeController::class, 'reviewDocument'])->name('cooperative-documents.review');
    // Perfil completo de un usuario (pedido explícito del usuario): toda la
    // información relevante de un conductor o cliente en una sola pantalla,
    // sin tener que navegar entre suscripciones/verificaciones/flotas.
    Route::get('/usuarios/{user}', [AdminUserProfileController::class, 'show'])->name('users.show');
    Route::post('/usuarios/{user}/reactivar', [AdminUserProfileController::class, 'unlock'])->name('users.unlock');
    // Corregir correo/teléfono, y dar de baja un número (pedido explícito
    // del usuario) — ver Admin\UserProfileController::updateContact()/releasePhone().
    Route::patch('/usuarios/{user}/contacto', [AdminUserProfileController::class, 'updateContact'])->name('users.update-contact');
    Route::delete('/usuarios/{user}/telefono', [AdminUserProfileController::class, 'releasePhone'])->name('users.release-phone');
    // Ajuste manual de puntos de un conductor (pedido explícito del usuario)
    // — ver Admin\UserProfileController::updatePoints().
    Route::patch('/usuarios/{user}/puntos', [AdminUserProfileController::class, 'updatePoints'])->name('users.update-points');
    // Activación manual de un conductor puntual (pedido explícito del
    // usuario) — ver Admin\UserProfileController::forceActivate().
    Route::post('/usuarios/{user}/activar-conductor', [AdminUserProfileController::class, 'forceActivate'])->name('users.force-activate-driver');
    Route::delete('/usuarios/{user}/activar-conductor', [AdminUserProfileController::class, 'revokeForceActivate'])->name('users.revoke-force-activate-driver');
    // Exigir documentos a UN conductor puntual, al revés de lo de arriba
    // (pedido explícito del usuario) — ver
    // Admin\UserProfileController::requireDocuments().
    Route::post('/usuarios/{user}/exigir-documentos', [AdminUserProfileController::class, 'requireDocuments'])->name('users.require-driver-documents');
    Route::delete('/usuarios/{user}/exigir-documentos', [AdminUserProfileController::class, 'revokeRequireDocuments'])->name('users.revoke-require-driver-documents');
    // Pedido explícito del usuario: "como hago para pasar yo a un cliente
    // como conductor" — ver Admin\UserProfileController::convertToDriver().
    Route::post('/usuarios/{user}/convertir-en-conductor', [AdminUserProfileController::class, 'convertToDriver'])->name('users.convert-to-driver');
    // Pedido explícito del usuario: ver el detalle de los clientes de un
    // conductor desde el admin, y poder sacarlo de esa flota — ver
    // AdminUserProfileController::removeDriverClient().
    Route::delete('/usuarios/{user}/clientes/{member}', [AdminUserProfileController::class, 'removeDriverClient'])->name('users.remove-client');
    // Eliminar una cuenta real y todo lo que le pertenece (pedido explícito
    // del usuario) — ver AdminUserProfileController::destroy().
    Route::delete('/usuarios/{user}', [AdminUserProfileController::class, 'destroy'])->name('users.destroy');

    // Depurar carreras de prueba (pedido explícito del usuario): sueltas,
    // programadas o de Expreso — todas son filas de `rides`. Ver
    // Admin\RideController::destroy() para qué se lleva de encajada.
    Route::get('/carreras', [AdminRideController::class, 'index'])->name('rides.index');
    // Detalle de una carrera puntual (pedido explícito del usuario: "ver el
    // detalle de las carreras cuando le de click alguna de ellas").
    Route::get('/carreras/{ride}', [AdminRideController::class, 'show'])->name('rides.show');
    Route::delete('/carreras/{ride}', [AdminRideController::class, 'destroy'])->name('rides.destroy');

    Route::get('/suscripciones', [AdminSubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('/suscripciones', [AdminSubscriptionController::class, 'store'])->name('subscriptions.store');
    Route::post('/suscripciones/{subscription}/expirar', [AdminSubscriptionController::class, 'expire'])->name('subscriptions.expire');

    // Comprobantes de pago de pedidos de plan (consideración agregada al
    // alcance): aprobar activa la suscripción real, rechazar deja que el
    // usuario suba uno nuevo.
    Route::post('/pedidos-plan/{subscriptionRequest}/aprobar', [AdminSubscriptionController::class, 'approveRequest'])->name('subscription-requests.approve');
    Route::post('/pedidos-plan/{subscriptionRequest}/rechazar', [AdminSubscriptionController::class, 'rejectRequest'])->name('subscription-requests.reject');

    Route::get('/metricas', [MetricsController::class, 'index'])->name('metrics.index');
    Route::get('/encuestas', [SurveyMetricsController::class, 'index'])->name('survey-metrics.index');

    // Trazabilidad de referidos (pedido explícito del usuario): quién invitó
    // a quién a registrarse — ver App\Models\User::referredBy()/referrals().
    Route::get('/referidos', [AdminReferralController::class, 'index'])->name('referrals.index');

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

    // Cupones de descuento para suscripciones (pedido explícito del
    // usuario): "generar cupones de descuentos... para clientes y para
    // conductores como para cooperativa" — ver Admin\PlanCouponController.
    Route::get('/cupones-de-planes', [PlanCouponController::class, 'index'])->name('plan-coupons.index');
    // Buscar a quién atribuirle el cupón como referidor (pedido explícito
    // del usuario) — literal antes de cualquier ruta con {planCoupon} para
    // que no se confunda "buscar-referido" con un id.
    Route::get('/cupones-de-planes/buscar-referido', [PlanCouponController::class, 'searchReferrer'])->name('plan-coupons.search-referrer');
    Route::post('/cupones-de-planes', [PlanCouponController::class, 'store'])->name('plan-coupons.store');
    Route::patch('/cupones-de-planes/{planCoupon}', [PlanCouponController::class, 'update'])->name('plan-coupons.update');
    Route::post('/cupones-de-planes/{planCoupon}/alternar', [PlanCouponController::class, 'toggle'])->name('plan-coupons.toggle');
    Route::delete('/cupones-de-planes/{planCoupon}', [PlanCouponController::class, 'destroy'])->name('plan-coupons.destroy');

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

    // Configuración del sitio público (pedido explícito del usuario: subir
    // la imagen de fondo del hero de Welcome.vue desde acá, en vez de
    // depender de copiarla a mano a public/img/) — POST, no PATCH: sube un
    // archivo, mismo criterio que driver.profile.update.
    Route::get('/sitio', [SiteSettingController::class, 'edit'])->name('site.edit');
    Route::post('/sitio', [SiteSettingController::class, 'update'])->name('site.update');

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

    // Inbox de WhatsApp (pedido explícito del usuario: "tener a todos los
    // que me escriben y poder responder desde allí yo también o activar el
    // bot o no") — a diferencia de /soporte de arriba, acá aparece
    // CUALQUIER número que le haya escrito, no solo los que pidieron
    // soporte — ver Admin\WhatsAppInboxController.
    Route::get('/whatsapp', [WhatsAppInboxController::class, 'index'])->name('whatsapp-inbox.index');
    Route::get('/whatsapp/{conversation}', [WhatsAppInboxController::class, 'show'])->name('whatsapp-inbox.show');
    Route::post('/whatsapp/{conversation}/mensajes', [WhatsAppInboxController::class, 'reply'])->name('whatsapp-inbox.reply');
    Route::patch('/whatsapp/{conversation}/bot', [WhatsAppInboxController::class, 'toggleBot'])->name('whatsapp-inbox.toggle-bot');

    // Chatbot / asistente virtual (pedido explícito del usuario): a
    // propósito separado de /admin/integraciones/whatsapp (esa es la
    // configuración transaccional cruda), nunca mezclados.
    Route::prefix('chatbot')->name('chatbot.')->group(function () {
        Route::get('/intenciones', [ChatbotIntentController::class, 'index'])->name('intents.index');
        Route::post('/intenciones', [ChatbotIntentController::class, 'store'])->name('intents.store');
        Route::patch('/intenciones/{chatbotIntent}', [ChatbotIntentController::class, 'update'])->name('intents.update');
        Route::post('/intenciones/{chatbotIntent}/vocablos', [ChatbotIntentController::class, 'storeKeyword'])->name('intents.keywords.store');
        Route::delete('/vocablos/{chatbotIntentKeyword}', [ChatbotIntentController::class, 'destroyKeyword'])->name('intents.keywords.destroy');

        Route::get('/mensajes', [ChatbotSettingController::class, 'edit'])->name('settings.edit');
        Route::patch('/mensajes', [ChatbotSettingController::class, 'update'])->name('settings.update');

        Route::get('/no-reconocidas', [ChatbotUnrecognizedController::class, 'index'])->name('unrecognized.index');
        Route::post('/no-reconocidas/{chatbotUnrecognizedMessage}/revisar', [ChatbotUnrecognizedController::class, 'markReviewed'])->name('unrecognized.review');
    });

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
    Route::patch('/conductores/{driverProfile}/whatsapp', [AdminDriverController::class, 'updateWhatsApp'])->name('drivers.whatsapp');
    Route::patch('/conductores/{driverProfile}/categoria', [AdminDriverController::class, 'updateCategory'])->name('drivers.category');
    Route::patch('/conductores/{driverProfile}/categoria-publica', [AdminDriverController::class, 'updatePublicCategory'])->name('drivers.public-category');

    // Panel de clientes registrados (pedido explícito del usuario): mismo
    // criterio que el de conductores, del otro lado — ver Admin\ClientController.
    Route::get('/clientes', [AdminClientController::class, 'index'])->name('clients.index');

    // Centro de operaciones (pedido explícito del usuario): concentración de
    // solicitudes activas, conectados, demanda por horario/zona, y avisar a
    // los conductores cercanos dónde conviene estar.
    Route::get('/operaciones', [AdminOperationsController::class, 'index'])->name('operations.index');
    Route::post('/operaciones/avisar-demanda', [AdminOperationsController::class, 'notifyNearby'])->name('operations.notify-demand');

    // Operaciones en vivo (pedido explícito del usuario: "ver las
    // transaciones que se estan ejecutando ahorita... cliente esperando
    // conductor... carrera en curso... con el detalle y el mapa") —
    // distinto de /operaciones (arriba): acá cada solicitud/carrera activa
    // es su propia tarjeta con detalle, no un agregado histórico.
    Route::get('/en-vivo', [LiveOperationsController::class, 'index'])->name('live-operations.index');

    // Registros por ubicación (pedido explícito del usuario: "ver de dónde
    // se registran las personas, por su ubicación").
    Route::get('/registros-por-ubicacion', [UserLocationsController::class, 'index'])->name('user-locations.index');

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
    // Accesos rápidos del menú (pedido explícito del usuario: "permiteme en
    // el modulo de sistema de habilitar o no estas opciones del menu").
    Route::patch('/sistema/accesos-rapidos', [AdminSystemController::class, 'updateQuickLinks'])->name('system.quick-links.update');
    // Requisitos de verificación del conductor (pedido explícito del
    // usuario: "permiteme desde el admin poder activar o no lo obligatorio
    // para que el conductor se le haga mas facil activarse").
    Route::patch('/sistema/requisitos-conductor', [AdminSystemController::class, 'updateDriverRequirements'])->name('system.driver-requirements.update');
    // Sonidos de notificaciones + volumen (pedido explícito del usuario:
    // "una lista de sonidos que puedo seleccionar para las notificaciones y
    // que las pueda activar desde el panel administrativo. y que tenga todo
    // el volumen").
    Route::patch('/sistema/sonidos', [AdminSystemController::class, 'updateNotificationSounds'])->name('system.notification-sounds.update');

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
    Route::get('/seguimiento/{ride:public_id}', [PublicRideTrackingController::class, 'show'])->name('public.rides.track');
    Route::get('/seguimiento/{ride:public_id}/estado', [PublicRideTrackingController::class, 'status'])->name('public.rides.track.status');
});

// "Referí a tu conductor" (pedido explícito del usuario): landing pública,
// sin login — cualquiera puede ver a quién le están recomendando antes de
// decidir crear una cuenta. El `invite_code` ya es un identificador propio
// (aleatorio, de 8 caracteres) que cumple el mismo papel que una firma.
Route::get('/referir/{driverProfile:invite_code}', [ReferralController::class, 'show'])->name('referrals.show');

// Perfil público (sección 3.6 + pedido explícito del usuario: "compartir mi
// perfil" con QR/WhatsApp) — sin login, mismo criterio que /referir de
// arriba: quien escanea el código o abre el link puede no tener cuenta
// todavía, y es justo a esa persona a la que se lo quiere mostrar. El
// controlador ya manda solo los campos pensados para verse en público (ver
// PublicProfileController::show()), nunca datos sensibles.
Route::get('/perfil/{user:public_id}', [PublicProfileController::class, 'show'])->name('profiles.show');

// Perfil público de una cooperativa aprobada. Una cooperativa pendiente solo
// puede previsualizarlo con su propia sesión; un admin también puede verlo.
Route::get('/cooperativas/{cooperative:public_id}', [CooperativeDirectoryController::class, 'show'])->name('cooperatives.show');

require __DIR__.'/auth.php';
