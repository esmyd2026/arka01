<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\DriverController;
use App\Http\Controllers\Api\V1\ExpressApplicationController;
use App\Http\Controllers\Api\V1\ExpressRouteController;
use App\Http\Controllers\Api\V1\FleetController;
use App\Http\Controllers\Api\V1\RideController;
use App\Http\Controllers\Api\V1\RideRequestController;
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
        Route::get('/fleet', [FleetController::class, 'index'])->name('fleet.index');
        Route::get('/fleet/{fleet}/search-drivers', [FleetController::class, 'searchDrivers'])
            ->middleware('throttle:30,1,api.fleet.search-drivers')
            ->name('fleet.search-drivers');
        Route::post('/fleet/{fleet}/invitations', [FleetController::class, 'invite'])->name('fleet.invite');
        Route::delete('/fleet/members/{member}', [FleetController::class, 'removeMember'])->name('fleet.members.destroy');

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

        // /express-routes/mine y /express-routes/available van ANTES de
        // /express-routes/{route}, mismo cuidado de orden que el resto de la API.
        Route::get('/express-routes/mine', [ExpressRouteController::class, 'mine'])->name('express-routes.mine');
        Route::get('/express-routes/available', [ExpressRouteController::class, 'available'])->name('express-routes.available');
        Route::post('/express-routes', [ExpressRouteController::class, 'store'])->name('express-routes.store');
        Route::get('/express-routes/{route}', [ExpressRouteController::class, 'show'])->name('express-routes.show');
        Route::put('/express-routes/{route}', [ExpressRouteController::class, 'update'])->name('express-routes.update');
        Route::post('/express-routes/{route}/pause', [ExpressRouteController::class, 'pause'])->name('express-routes.pause');
        Route::post('/express-routes/{route}/resume', [ExpressRouteController::class, 'resume'])->name('express-routes.resume');
        Route::post('/express-routes/{route}/cancel', [ExpressRouteController::class, 'cancel'])->name('express-routes.cancel');
        Route::post('/express-routes/{route}/applications', [ExpressApplicationController::class, 'store'])->name('express-applications.store');
        Route::post('/express-applications/{application}/accept', [ExpressApplicationController::class, 'accept'])->name('express-applications.accept');
        Route::post('/express-applications/{application}/reject', [ExpressApplicationController::class, 'reject'])->name('express-applications.reject');
        Route::post('/express-applications/{application}/withdraw', [ExpressApplicationController::class, 'withdraw'])->name('express-applications.withdraw');
    });
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
