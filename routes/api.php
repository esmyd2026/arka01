<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\DriverController;
use App\Http\Controllers\Api\V1\FleetController;
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

        Route::get('/ride-requests', [RideRequestController::class, 'index'])->name('ride-requests.index');
        Route::post('/ride-requests', [RideRequestController::class, 'store'])
            ->middleware('throttle:10,1,api.ride-requests.store')
            ->name('ride-requests.store');
        Route::get('/ride-requests/{rideRequest}', [RideRequestController::class, 'show'])->name('ride-requests.show');
        Route::post('/ride-requests/{rideRequest}/cancel', [RideRequestController::class, 'cancel'])->name('ride-requests.cancel');

        Route::get('/driver/status', [DriverController::class, 'status'])->name('driver.status');
        Route::post('/driver/location', [DriverController::class, 'updateLocation'])
            ->middleware('throttle:20,1,api.driver.location')
            ->name('driver.location');
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
