<?php

namespace App\Http\Controllers\Auth;

use App\Events\DriverLocationUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Jobs\NotifyDriverDisconnectedByWhatsApp;
use App\Providers\RouteServiceProvider;
use App\Services\DriverActivityTracker;
use App\Services\WhatsAppConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function __construct(private readonly DriverActivityTracker $activityTracker) {}

    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            // Bug reportado por el usuario: al volver de un login con Google
            // bloqueado por sesión única, el widget de "pedir código" no
            // tenía forma de saber a qué cuenta mandárselo — GoogleAuthController
            // lo deja acá (ver Auth/Login.vue).
            'loginHint' => session('login_hint'),
            // Pedido explícito del usuario: antes de "Pedir código", ofrecer
            // que le escriba primero al WhatsApp oficial — así la ventana de
            // 24h ya está abierta cuando pide el código, y le llega por ahí
            // en vez de por correo (ver WhatsAppWebhookController::receive()
            // y WhatsAppFreeformSender::sendSessionRecoveryPrompt()).
            'whatsappBusinessNumber' => WhatsAppConfig::businessNumber(),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        // Se reportó: un conductor que cerró sesión (o a quien se le venció
        // la sesión) seguía apareciendo "disponible" para su flota, aunque ni
        // siquiera estuviera logueado. Se apaga acá mismo, igual que si
        // hubiera tocado "Desconectarme" a mano antes de irse.
        $user = $request->user();
        if ($user?->isDriver() && $user->driverProfile->is_available) {
            $user->driverProfile->update(['is_available' => false]);
            $this->activityTracker->close($user->id, now());
            broadcast(new DriverLocationUpdated($user->driverProfile));
            // Pedido explícito del usuario: avisarle por WhatsApp que se
            // desconectó (mismo criterio que el toggle "Activarme" en
            // DriverLocationController::update()).
            NotifyDriverDisconnectedByWhatsApp::dispatch($user->id);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
