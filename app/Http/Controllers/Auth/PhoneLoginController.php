<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\ActiveSessionExistsException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\WhatsAppVerificationSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Login sin contraseña por WhatsApp (pedido explícito del usuario) — para
 * CUALQUIER cuenta con teléfono verificado, no solo las del registro rápido
 * (QuickRegistrationController). Calco exacto del patrón de
 * SessionTakeoverController: respuesta genérica siempre igual, no delata si
 * la cuenta existe ni si tiene teléfono verificado.
 */
class PhoneLoginController extends Controller
{
    public function sendCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string'],
        ]);

        $user = User::findByLoginIdentifier($validated['login']);

        if ($user && $user->phone_verified_at && ! $user->isLocked() && WhatsAppVerificationSender::enabled()) {
            $code = $user->issueLoginCode();
            WhatsAppVerificationSender::sendCode($user->phone, $code);

            Log::info('Código de login por WhatsApp solicitado.', ['user_id' => $user->id]);
        }

        return response()->json([
            'message' => 'Si esa cuenta tiene un teléfono verificado, le enviamos un código por WhatsApp.',
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = User::findByLoginIdentifier($validated['login']);

        if (! $user || $user->isLocked() || ! $user->verifyLoginCode($validated['code'])) {
            throw ValidationException::withMessages([
                'code' => 'Ese código no es válido o ya venció.',
            ]);
        }

        try {
            Auth::login($user);
        } catch (ActiveSessionExistsException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        $request->session()->regenerate();

        return redirect()->intended(RouteServiceProvider::HOME);
    }
}
