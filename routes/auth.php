<?php

use App\Http\Controllers\Auth\AccountTypeController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\GuestAccountController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\PhoneVerificationController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\SessionTakeoverController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    // Rendimiento/seguridad de cara a producción (pedido explícito del
    // usuario): sin esto, nada impedía crear cuentas en cadena ni barrer
    // contraseñas contra muchos usuarios distintos desde la misma IP — el
    // bloqueo de LoginRequest (5 intentos) es por email+IP, no cubre eso.
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:6,1,auth.register');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:10,1,auth.login');

    // Sesión única por cuenta (pedido explícito del usuario, caso real: no
    // sabía en qué navegador había quedado logueado) — pedir/confirmar un
    // código para cerrar la otra sesión sin esperar a que venza sola.
    Route::post('sesion/liberar', [SessionTakeoverController::class, 'request'])
        ->middleware('throttle:3,1,session-takeover.request')
        ->name('session-takeover.request');

    Route::post('sesion/liberar/confirmar', [SessionTakeoverController::class, 'confirm'])
        ->middleware('throttle:6,1,session-takeover.confirm')
        ->name('session-takeover.confirm');

    // Iniciar sesión con Google (Socialite/OAuth) — alternativa al usuario y
    // contraseña de siempre.
    Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    // Auditoría de seguridad (pedido explícito del usuario): sin esto,
    // cualquiera podía mandar el correo de recuperación en cadena contra
    // direcciones ajenas (email bombing) — mismo límite que pedir el código
    // de liberación de sesión, otro endpoint "guest" que manda un correo.
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:3,1,password.email')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    // Auditoría de seguridad: sin esto, no había límite para probar tokens
    // de reset al voleo.
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:6,1,password.store')
        ->name('password.store');
});

// Bloqueo de cuenta desde el aviso de "si no fue usted" (pedido explícito
// del usuario) — a propósito fuera del grupo "guest": tiene que funcionar
// sin importar si quien lo toca está logueado o no en ese navegador. La
// firma (URL::signedRoute(), ver WhatsAppFreeformSender::sendSessionTakeoverCode()
// y SessionTakeoverCodeMail) es la única verificación — nadie puede armar
// este link a mano.
Route::get('sesion/bloquear/{user}', [SessionTakeoverController::class, 'lock'])
    ->middleware('signed')
    ->name('session-takeover.lock');

// Completar el registro de una cuenta creada sola al reservar por WhatsApp
// (pedido explícito del usuario) — mismo criterio de firma que la de
// arriba: nadie puede armar este link a mano, ver
// App\Http\Controllers\Auth\GuestAccountController.
Route::get('cuenta/completar-registro/{user}', [GuestAccountController::class, 'completeRegistration'])
    ->middleware('signed')
    ->name('guest-account.complete-registration');

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1,verification.verify'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1,verification.send')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    // Verificación de teléfono por WhatsApp (consideración de seguridad
    // agregada al alcance) — mismo criterio que verify-email de arriba.
    Route::get('verificar-telefono', [PhoneVerificationController::class, 'show'])
        ->name('phone.verify.show');

    Route::post('verificar-telefono', [PhoneVerificationController::class, 'store'])
        ->middleware('throttle:6,1,phone.verify.store')
        ->name('phone.verify.store');

    Route::post('verificar-telefono/reenviar', [PhoneVerificationController::class, 'resend'])
        ->middleware('throttle:3,1,phone.verify.resend')
        ->name('phone.verify.resend');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');

    // Pedido explícito del usuario: una cuenta de Google recién creada
    // (login o registro, da igual) todavía no eligió si es cliente o
    // conductor — GoogleAuthController redirige acá antes de dejarla pasar
    // al resto de la app.
    Route::get('cuenta/tipo', [AccountTypeController::class, 'show'])->name('account-type.choose');
});
