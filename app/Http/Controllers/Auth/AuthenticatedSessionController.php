<?php

namespace App\Http\Controllers\Auth;

use App\Events\DriverLocationUpdated;
use App\Http\Controllers\Controller;
use App\Jobs\NotifyDriverDisconnectedByWhatsApp;
use App\Http\Requests\Auth\LoginRequest;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
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
