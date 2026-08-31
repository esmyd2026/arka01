<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CityController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\CooperativeController;
use App\Http\Controllers\Api\V1\CooperativeDriverInvitationController;
use App\Http\Controllers\Api\V1\CouponController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DriverBankAccountController;
use App\Http\Controllers\Api\V1\DriverController;
use App\Http\Controllers\Api\V1\DriverDirectoryController;
use App\Http\Controllers\Api\V1\DriverInvitationController;
use App\Http\Controllers\Api\V1\DriverStatsController;
use App\Http\Controllers\Api\V1\ExpressApplicationController;
use App\Http\Controllers\Api\V1\ExpressIncidentController;
use App\Http\Controllers\Api\V1\ExpressRouteCompanionController;
use App\Http\Controllers\Api\V1\ExpressRouteController;
use App\Http\Controllers\Api\V1\FleetController;
use App\Http\Controllers\Api\V1\FleetInvitationController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PublicProfileController;
use App\Http\Controllers\Api\V1\RadioChannelController;
use App\Http\Controllers\Api\V1\RideController;
use App\Http\Controllers\Api\V1\RideRequestController;
use App\Http\Controllers\Api\V1\SavedRouteController;
use App\Http\Controllers\Api\V1\SosAlertController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\TrustCircleController;
use App\Http\Controllers\Api\V1\TrustedContactController;
use App\Http\Controllers\Api\V1\VanTripController;
use App\Http\Controllers\Api\V1\VanTripReservationController;
use App\Http\Controllers\DriverProfileController;
use App\Http\Controllers\MapRouteController;
use App\Http\Controllers\RadioSessionController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// API móvil (ROADMAP_APLICACION_MOVIL_CAPACITOR.md, Hito 2) — versionada
// para poder cambiar el contrato sin romper apps ya publicadas.
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/config', [ConfigController::class, 'show'])->name('config');

    Route::post('/auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:10,1,api.auth.register')
        ->name('auth.register');

    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1,api.auth.login')
        ->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::delete('/account', [AccountController::class, 'destroy'])->name('account.destroy');
        Route::put('/device/push-token', [DeviceController::class, 'updatePushToken'])->name('device.push-token');
        Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::get('/profile/search-referrer', [ProfileController::class, 'searchReferrer'])->name('profile.search-referrer');
        Route::post('/profile/referrer', [ProfileController::class, 'setReferrer'])->name('profile.set-referrer');
        Route::get('/cities', [CityController::class, 'index'])->name('cities.index');
        Route::get('/my-plan', [PlanController::class, 'mine'])->name('my-plan');
        Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');

        Route::get('/support', [SupportController::class, 'index'])->name('support.index');
        Route::post('/support/messages', [SupportController::class, 'storeMessage'])
            ->middleware('throttle:20,1,api.support.messages.store')
            ->name('support.messages.store');
        Route::get('/fleet', [FleetController::class, 'index'])->name('fleet.index');
        Route::post('/fleet', [FleetController::class, 'store'])->name('fleet.store');
        Route::get('/fleet/{fleet}/search-drivers', [FleetController::class, 'searchDrivers'])
            ->middleware('throttle:30,1,api.fleet.search-drivers')
            ->name('fleet.search-drivers');
        Route::get('/fleet/{fleet}/search-friends', [FleetInvitationController::class, 'searchFriends'])
            ->middleware('throttle:30,1,api.fleet.search-friends')
            ->name('fleet.referral.search-friends');
        Route::post('/fleet/{fleet}/invitations', [FleetController::class, 'invite'])->name('fleet.invite');
        Route::post('/fleet/{fleet}/referral', [FleetInvitationController::class, 'storeReferral'])->name('fleet.referral.store');
        Route::delete('/fleet/members/{member}', [FleetController::class, 'removeMember'])->name('fleet.members.destroy');
        Route::delete('/fleet/invitations/{invitation}', [FleetInvitationController::class, 'destroy'])->name('fleet.invitations.destroy');
        Route::get('/fleet/{fleet}', [FleetController::class, 'show'])->name('fleet.show');

        Route::get('/driver/invitations', [DriverInvitationController::class, 'index'])->name('driver.invitations.index');
        Route::get('/driver/clients/search', [DriverInvitationController::class, 'searchClients'])
            ->middleware('throttle:30,1,api.driver.clients.search')
            ->name('driver.clients.search');
        Route::post('/driver/invitations', [FleetInvitationController::class, 'storeFromDriver'])->name('fleet-invitations.request');
        Route::post('/driver/invitations/{invitation}/accept', [DriverInvitationController::class, 'accept'])->name('driver.invitations.accept');
        Route::post('/driver/invitations/{invitation}/reject', [DriverInvitationController::class, 'reject'])->name('driver.invitations.reject');
        Route::post('/driver/fleets/{member}/leave', [DriverInvitationController::class, 'leave'])->name('driver.fleets.leave');
        Route::post('/driver/fleets/{member}/toggle-requests', [DriverInvitationController::class, 'toggleRequests'])->name('driver.fleets.toggle-requests');

        // /ride-requests/incoming va ANTES de /ride-requests/{rideRequest}
        // (mismo cuidado que la web con /carreras/indicadores vs.
        // /carreras/{ride}): si no, "incoming" se interpreta como el id.
        Route::get('/ride-requests/incoming', [RideRequestController::class, 'incoming'])->name('ride-requests.incoming');
        Route::get('/ride-requests', [RideRequestController::class, 'index'])->name('ride-requests.index');
        Route::post('/ride-requests', [RideRequestController::class, 'store'])
            ->middleware('throttle:10,1,api.ride-requests.store')
            ->name('ride-requests.store');
        Route::get('/ride-requests/{rideRequest}', [RideRequestController::class, 'show'])->name('ride-requests.show');
        Route::post('/ride-requests/{rideRequest}/accept', [RideRequestController::class, 'accept'])->name('ride-requests.accept');
        Route::post('/ride-requests/{rideRequest}/reject', [RideRequestController::class, 'reject'])->name('ride-requests.reject');
        Route::post('/ride-requests/{rideRequest}/counter', [RideRequestController::class, 'counter'])->name('ride-requests.counter');
        Route::post('/ride-requests/{rideRequest}/raise-offer', [RideRequestController::class, 'raiseOffer'])->name('ride-requests.raise-offer');
        Route::post('/ride-requests/{rideRequest}/cancel', [RideRequestController::class, 'cancel'])->name('ride-requests.cancel');

        Route::get('/driver/status', [DriverController::class, 'status'])->name('driver.status');
        Route::post('/driver/location', [DriverController::class, 'updateLocation'])
            ->middleware('throttle:20,1,api.driver.location')
            ->name('driver.location');
        Route::get('/driver/profile', [DriverController::class, 'profile'])->name('driver.profile.show');
        Route::post('/driver/profile', [DriverController::class, 'updateProfile'])
            ->middleware('throttle:10,1,api.driver.profile.update')
            ->name('driver.profile.update');
        Route::post('/driver/profile/deactivate', [DriverController::class, 'deactivate'])->name('driver.profile.deactivate');
        Route::post('/driver/profile/reactivate', [DriverController::class, 'reactivate'])->name('driver.profile.reactivate');
        // Mismo controlador que la web (App\Http\Controllers\DriverProfileController):
        // solo depende de $request->user(), sin nada de sesión/Inertia, así
        // que sirve tal cual también bajo Sanctum sin duplicar una línea.
        Route::get('/driver/{user}/license-photo', [DriverProfileController::class, 'licensePhoto'])->name('driver-profile.mobile.license-photo');
        Route::get('/driver/{user}/documents/{type}', [DriverProfileController::class, 'document'])->name('driver-profile.mobile.document');

        // Directorio de conductores (sección 3.4) — a diferencia del perfil
        // público puntual, este sí se deja atrás de un token (listar a
        // TODOS los conductores públicos de un saque es una superficie de
        // scraping mucho mayor que compartir un perfil concreto).
        Route::get('/directory', [DriverDirectoryController::class, 'index'])->name('directory.index');
        Route::get('/driver/stats', [DriverStatsController::class, 'index'])->name('driver.stats');
        Route::get('/coupons', [CouponController::class, 'index'])->name('coupons.index');
        Route::get('/saved-routes', [SavedRouteController::class, 'index'])->name('saved-routes.index');
        Route::post('/saved-routes', [SavedRouteController::class, 'store'])->name('saved-routes.store');
        Route::delete('/saved-routes/{savedRoute}', [SavedRouteController::class, 'destroy'])->name('saved-routes.destroy');

        Route::get('/trusted-contacts', [TrustedContactController::class, 'index'])->name('trusted-contacts.index');
        Route::post('/trusted-contacts', [TrustedContactController::class, 'store'])->name('trusted-contacts.store');
        Route::delete('/trusted-contacts/{contact}', [TrustedContactController::class, 'destroy'])->name('trusted-contacts.destroy');
        Route::post('/rides/{ride}/sos', [SosAlertController::class, 'store'])->name('sos.store');

        Route::get('/driver/bank-accounts', [DriverBankAccountController::class, 'index'])->name('driver.bank-accounts.index');
        Route::post('/driver/bank-accounts', [DriverBankAccountController::class, 'store'])->name('driver.bank-accounts.store');
        Route::patch('/driver/bank-accounts/{bankAccount}', [DriverBankAccountController::class, 'update'])->name('driver.bank-accounts.update');
        Route::delete('/driver/bank-accounts/{bankAccount}', [DriverBankAccountController::class, 'destroy'])->name('driver.bank-accounts.destroy');
        Route::patch('/driver/bank-accounts/{bankAccount}/favorite', [DriverBankAccountController::class, 'markFavorite'])->name('driver.bank-accounts.favorite');

        // Directorio de cooperativas y red de cooperativas del cliente.
        Route::get('/cooperatives', [CooperativeController::class, 'index'])->name('cooperatives.index');
        Route::get('/cooperatives/{cooperative}', [CooperativeController::class, 'show'])->name('cooperatives.show');
        Route::post('/cooperatives/{cooperative}/attach', [CooperativeController::class, 'attach'])->name('cooperatives.attach');
        Route::delete('/cooperatives/{cooperative}/detach', [CooperativeController::class, 'detach'])->name('cooperatives.detach');

        // El conductor siempre decide si acepta o rechaza el vínculo con una cooperativa.
        Route::get('/cooperative-driver-invitations', [CooperativeDriverInvitationController::class, 'index'])->name('cooperative-driver-invitations.index');
        Route::post('/cooperative-driver-invitations/{membership}/respond', [CooperativeDriverInvitationController::class, 'respond'])->name('cooperative-driver-invitations.respond');

        // Ruta Google calculada desde el servidor (protege la clave privada,
        // con caché/throttle) — reusa MapRouteController TAL CUAL, no
        // depende de sesión ni de $request->user(), mismo criterio que
        // DriverProfileController::licensePhoto()/document().
        Route::post('/maps/route', MapRouteController::class)
            ->middleware('throttle:30,1,maps.route')
            ->name('maps.route');

        Route::get('/trust-circle', [TrustCircleController::class, 'index'])->name('trust-circle.index');
        Route::get('/trust-circle/search', [TrustCircleController::class, 'search'])
            ->middleware('throttle:30,1,trust-circle.search')
            ->name('trust-circle.search');
        Route::post('/trust-circle', [TrustCircleController::class, 'store'])
            ->middleware('throttle:10,1,trust-circle.store')
            ->name('trust-circle.store');
        Route::post('/trust-circle/{connection}/respond', [TrustCircleController::class, 'respond'])->name('trust-circle.respond');
        Route::put('/trust-circle/{connection}/settings', [TrustCircleController::class, 'updateSettings'])->name('trust-circle.settings.update');
        Route::delete('/trust-circle/{connection}', [TrustCircleController::class, 'destroy'])->name('trust-circle.destroy');
        Route::post('/trust-circle/drivers/invite', [TrustCircleController::class, 'inviteDriver'])->name('trust-circle.drivers.invite');

        // Viajes tipo VAN/buseta — /van-trips/browse va ANTES de
        // /van-trips/{vanTrip}, mismo cuidado de orden que el resto de la API.
        Route::get('/van-trips', [VanTripController::class, 'index'])->name('van-trips.index');
        Route::post('/van-trips', [VanTripController::class, 'store'])->name('van-trips.store');
        Route::get('/van-trips/browse', [VanTripController::class, 'browse'])->name('van-trips.browse');
        Route::get('/van-trips/{vanTrip}', [VanTripController::class, 'show'])->name('van-trips.show');
        Route::post('/van-trips/{vanTrip}/cancel', [VanTripController::class, 'cancel'])->name('van-trips.cancel');
        Route::post('/van-trips/{vanTrip}/reservations', [VanTripReservationController::class, 'store'])->name('van-trip-reservations.store');
        Route::post('/van-trip-reservations/{reservation}/cancel', [VanTripReservationController::class, 'cancel'])->name('van-trip-reservations.cancel');

        // Canal de radio/walkie-talkie de seguridad. status()/__invoke() de
        // RadioSessionController (web) ya eran JSON puro sin depender de
        // sesión ni Inertia — se reusan TAL CUAL, mismo criterio que
        // MapRouteController. La invitación compartible sigue siendo solo
        // web (se abre desde un enlace externo en el navegador).
        Route::get('/radio/status', [RadioSessionController::class, 'status'])
            ->middleware('throttle:60,1,radio.status')
            ->name('radio.status');
        Route::post('/radio/session', RadioSessionController::class)
            ->middleware('throttle:30,1,radio.session')
            ->name('radio.session');
        Route::post('/radio/invitations/{radioChannel:share_code}/join', [RadioChannelController::class, 'join'])
            ->middleware('throttle:12,1,radio.invitation.join')
            ->name('radio.invitation.join');
        Route::patch('/radio/channels/{radioChannel:public_id}', [RadioChannelController::class, 'update'])->name('radio.channels.update');
        Route::post('/radio/channels/{radioChannel:public_id}/rotate-invitation', [RadioChannelController::class, 'rotateInvitation'])
            ->middleware('throttle:6,1,radio.channels.rotate-invitation')
            ->name('radio.channels.rotate-invitation');
        Route::delete('/radio/channels/{radioChannel:public_id}/members/{memberPublicId}', [RadioChannelController::class, 'removeMember'])->name('radio.channels.members.destroy');
        Route::delete('/radio/channels/{radioChannel:public_id}/leave', [RadioChannelController::class, 'leave'])->name('radio.channels.leave');

        // /rides/active y /rides/history van ANTES de /rides/{ride}, mismo
        // cuidado de orden que el resto de la API.
        Route::get('/rides/active', [RideController::class, 'active'])->name('rides.active');
        Route::get('/rides/history', [RideController::class, 'history'])->name('rides.history');
        Route::get('/rides/{ride}', [RideController::class, 'show'])->name('rides.show');
        Route::post('/rides/{ride}/start', [RideController::class, 'start'])->name('rides.start');
        Route::post('/rides/{ride}/heading-to-passenger', [RideController::class, 'headingToPassenger'])->name('rides.heading-to-passenger');
        Route::post('/rides/{ride}/arrived', [RideController::class, 'arrived'])->name('rides.arrived');
        Route::post('/rides/{ride}/picked-up', [RideController::class, 'pickedUp'])->name('rides.picked-up');
        Route::post('/rides/{ride}/complete', [RideController::class, 'complete'])->name('rides.complete');
        Route::post('/rides/{ride}/cancel', [RideController::class, 'cancel'])->name('rides.cancel');
        Route::post('/rides/{ride}/location', [RideController::class, 'updateLocation'])
            ->middleware('throttle:30,1,api.rides.location')
            ->name('rides.location');
        Route::post('/rides/{ride}/stops/{stop}/complete', [RideController::class, 'completeStop'])->name('rides.stops.complete');
        Route::post('/rides/{ride}/reschedule', [RideController::class, 'proposeReschedule'])->name('rides.reschedule.propose');
        Route::post('/rides/{ride}/reschedule/confirm', [RideController::class, 'confirmReschedule'])->name('rides.reschedule.confirm');
        Route::post('/rides/{ride}/reschedule/reject', [RideController::class, 'rejectReschedule'])->name('rides.reschedule.reject');
        Route::get('/rides/{ride}/messages', [RideController::class, 'messages'])->name('rides.messages.index');
        Route::post('/rides/{ride}/messages', [RideController::class, 'sendMessage'])->name('rides.messages.store');
        Route::post('/rides/{ride}/review', [RideController::class, 'review'])->name('rides.review');
        Route::get('/rides/{ride}/tracking-link', [RideController::class, 'trackingLink'])->name('rides.tracking-link');

        // /express-routes/mine, /available y /discover van ANTES de
        // /express-routes/{route}, mismo cuidado de orden que el resto de la API.
        Route::get('/express-routes/mine', [ExpressRouteController::class, 'mine'])->name('express-routes.mine');
        Route::get('/express-routes/available', [ExpressRouteController::class, 'available'])->name('express-routes.available');
        Route::get('/express-routes/discover', [ExpressRouteCompanionController::class, 'discover'])
            ->middleware('throttle:30,1,api.express-route-companions.discover')
            ->name('express-route-companions.discover');
        Route::post('/express-routes', [ExpressRouteController::class, 'store'])
            ->middleware('throttle:10,1,api.express-routes.store')
            ->name('express-routes.store');
        Route::get('/express-routes/{route}', [ExpressRouteController::class, 'show'])->name('express-routes.show');
        Route::put('/express-routes/{route}', [ExpressRouteController::class, 'update'])->name('express-routes.update');
        Route::post('/express-routes/{route}/pause', [ExpressRouteController::class, 'pause'])->name('express-routes.pause');
        Route::post('/express-routes/{route}/resume', [ExpressRouteController::class, 'resume'])->name('express-routes.resume');
        Route::post('/express-routes/{route}/cancel', [ExpressRouteController::class, 'cancel'])->name('express-routes.cancel');
        Route::post('/express-routes/{route}/applications', [ExpressApplicationController::class, 'store'])
            ->middleware('throttle:10,1,api.express-applications.store')
            ->name('express-applications.store');
        Route::post('/express-applications/{application}/accept', [ExpressApplicationController::class, 'accept'])->name('express-applications.accept');
        Route::post('/express-applications/{application}/reject', [ExpressApplicationController::class, 'reject'])->name('express-applications.reject');
        Route::post('/express-applications/{application}/withdraw', [ExpressApplicationController::class, 'withdraw'])->name('express-applications.withdraw');
        Route::post('/express-routes/{route}/companions', [ExpressRouteCompanionController::class, 'store'])
            ->middleware('throttle:10,1,api.express-route-companions.store')
            ->name('express-route-companions.store');
        Route::post('/express-companions/{companion}/accept', [ExpressRouteCompanionController::class, 'accept'])->name('express-route-companions.accept');
        Route::post('/express-companions/{companion}/reject', [ExpressRouteCompanionController::class, 'reject'])->name('express-route-companions.reject');
        Route::post('/express-companions/{companion}/leave', [ExpressRouteCompanionController::class, 'leave'])->name('express-route-companions.leave');
        Route::post('/express-companions/{companion}/driver-accept', [ExpressRouteCompanionController::class, 'driverAccept'])->name('express-route-companions.driver-accept');
        Route::post('/express-companions/{companion}/driver-reject', [ExpressRouteCompanionController::class, 'driverReject'])->name('express-route-companions.driver-reject');
        Route::post('/express-routes/{route}/incidents', [ExpressIncidentController::class, 'store'])->name('express-incidents.store');
    });

    // Perfil público (sección 3.6): a diferencia del resto de /api/v1, esta
    // ruta es pública a propósito — mismo criterio que la web
    // (routes/web.php: profiles.show vive fuera de cualquier grupo `auth`),
    // cualquiera con el enlace puede verlo, incluso sin cuenta.
    Route::get('/profiles/{user:public_id}', [PublicProfileController::class, 'show'])->name('profiles.show');
});

// Webhook entrante de WhatsApp Cloud API (pedido explícito del usuario): sin
// middleware de sesión/CSRF a propósito — es Meta quien llama, no un
// navegador con sesión — ver WhatsAppWebhookController. El POST (donde llegan
// mensajes reales) sí valida la firma HMAC de Meta (auditoría de seguridad,
// ver VerifyWhatsAppSignature) — el GET de verificación no la necesita,
// se valida solo con el hub_verify_token.
Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp.verify');
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])
    ->middleware('whatsapp.signed')
    ->name('webhooks.whatsapp.receive');
