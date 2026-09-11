<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\ActiveSessionExistsException;
use App\Http\Controllers\Controller;
use App\Mail\PhoneLoginCodeMail;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Services\WhatsAppVerificationSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
 *
 * Pedido explícito del usuario ("priorizar el teléfono pero si no que sea
 * por email"): si WhatsApp no está configurado o el envío falla de verdad,
 * se intenta por correo — solo si la cuenta tiene uno real (ver
 * User::hasRealEmail()), nunca a los correos "de relleno" de cuentas creadas
 * por WhatsApp/registro rápido. La respuesta al frontend es SIEMPRE la misma
 * sin importar qué canal (o ninguno) haya funcionado — no delata nada.
 */
class PhoneLoginController extends Controller
{
    public function sendCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string'],
        ]);

        $user = User::findByLoginIdentifier($validated['login']);

        if ($user && ! $user->isLocked()) {
            $code = $user->issueLoginCode();
            $sentByWhatsApp = WhatsAppVerificationSender::enabled() && WhatsAppVerificationSender::sendCode($user->phone, $code);

            if (! $sentByWhatsApp && $user->hasRealEmail()) {
                try {
                    Mail::to($user->email)->send(new PhoneLoginCodeMail($user, $code));
                } catch (\Throwable $e) {
                    Log::warning('No se pudo mandar el código de login por correo.', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Código de login solicitado.', [
                'user_id' => $user->id,
                'enviado_por_whatsapp' => $sentByWhatsApp,
            ]);
        }

        return response()->json([
            'message' => 'Si ese teléfono está registrado, le enviamos un código por WhatsApp o, si no pudimos, por correo.',
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
