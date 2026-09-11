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
 * cualquier cuenta con teléfono cargado, no solo las del registro rápido
 * (QuickRegistrationController). Calco exacto del patrón de
 * SessionTakeoverController: respuesta genérica siempre igual, no delata si
 * la cuenta existe.
 *
 * Bug real reportado por el usuario: antes esto exigía `phone_verified_at`
 * — una cuenta que nunca pasó por la verificación (registro viejo, creada
 * por un admin, etc.) no podía usar este atajo y el "no llegó ningún
 * código" quedaba sin explicación, dejando a esa persona sin ninguna
 * salida si además no recordaba su contraseña. Recibir y escribir bien un
 * código de un solo uso a ESE número ya es prueba suficiente de que es el
 * dueño — no hace falta haberlo verificado antes — así que ahora se manda
 * igual y, si no estaba verificado, login() lo deja verificado de una vez.
 */
class PhoneLoginController extends Controller
{
    public function sendCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string'],
        ]);

        $user = User::findByLoginIdentifier($validated['login']);

        if ($user && ! $user->isLocked() && WhatsAppVerificationSender::enabled()) {
            $code = $user->issueLoginCode();
            WhatsAppVerificationSender::sendCode($user->phone, $code);

            Log::info('Código de login por WhatsApp solicitado.', ['user_id' => $user->id]);
        }

        return response()->json([
            'message' => 'Si ese teléfono está registrado, le enviamos un código por WhatsApp.',
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

        if (! $user->phone_verified_at) {
            $user->forceFill(['phone_verified_at' => now()])->save();
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
