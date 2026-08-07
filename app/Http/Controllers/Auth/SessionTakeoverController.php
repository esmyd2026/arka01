<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\SessionTakeoverCodeMail;
use App\Models\User;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sesión única por cuenta (pedido explícito del usuario, caso real: no
 * sabía en qué navegador había quedado logueado y la sesión activa ahí no lo
 * dejaba entrar desde otro) — en vez de esperar a que esa sesión venza sola
 * (App\Listeners\EnforceSingleActiveSession, SESSION_LIFETIME), el dueño real
 * de la cuenta puede pedir un código (por WhatsApp si tiene la ventana de 24h
 * abierta, si no por correo) que la cierra de una.
 *
 * request()/confirm() responden en JSON (no son pantallas Inertia aparte): se
 * usan desde un widget chico dentro de Auth/Login.vue, sin navegar a otra URL.
 */
class SessionTakeoverController extends Controller
{
    /**
     * Pide el código. Por seguridad, la respuesta es la MISMA exista o no la
     * cuenta — así no se puede usar esto para confirmar si un teléfono/correo
     * está registrado en Arka01.
     */
    public function request(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string'],
        ]);

        $user = User::findByLoginIdentifier($validated['login']);

        if ($user) {
            $code = $user->issueSessionTakeoverCode();

            // Pedido explícito del usuario: el aviso tiene que ofrecer una
            // salida real para "si no fue usted", no solo "ignore este
            // mensaje" — un link firmado que bloquea la cuenta de una,
            // válido por 30 minutos (más que alcanza para leer el mensaje).
            $lockUrl = URL::temporarySignedRoute('session-takeover.lock', now()->addMinutes(30), ['user' => $user->id]);

            if ($user->hasActiveWhatsAppSession()) {
                WhatsAppFreeformSender::sendSessionTakeoverCode($user, $code, $lockUrl);
            } else {
                // Best-effort a propósito (mismo criterio que el resto de los
                // avisos de la app): un problema con el correo no puede
                // tirar abajo el flujo ni delatar si la cuenta existe.
                try {
                    Mail::to($user->email)->send(new SessionTakeoverCodeMail($user, $code, $lockUrl));
                } catch (\Throwable $e) {
                    Log::warning('No se pudo mandar el código de liberación de sesión por correo.', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('Código de liberación de sesión solicitado.', ['user_id' => $user->id]);
        }

        return response()->json([
            'message' => 'Si esa cuenta existe, le enviamos un código para cerrar la otra sesión.',
        ]);
    }

    /**
     * Confirma el código y cierra TODAS las sesiones de la cuenta (todavía
     * no hay una nueva sesión propia en curso a esta altura — el usuario
     * sigue en la pantalla de login, recién va a autenticarse después de
     * esto) — así vuelve a poder entrar desde acá.
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = User::findByLoginIdentifier($validated['login']);

        if (! $user || ! $user->verifySessionTakeoverCode($validated['code'])) {
            throw ValidationException::withMessages([
                'code' => 'Ese código no es válido o ya venció.',
            ]);
        }

        $closed = DB::table('sessions')->where('user_id', $user->id)->delete();

        Log::info('Sesión liberada con código de verificación.', ['user_id' => $user->id, 'sesiones_cerradas' => $closed]);

        return response()->json([
            'message' => 'Listo, cerramos la otra sesión.',
        ]);
    }

    /**
     * "Si no fue usted" (pedido explícito del usuario): bloquea la cuenta de
     * inmediato y cierra todas sus sesiones — sin pedir contraseña ni sesión
     * iniciada, porque justamente se usa cuando la cuenta puede estar
     * comprometida. La firma de la URL (middleware `signed`) es la única
     * verificación; nadie puede armar este link sin que lo hayamos mandado
     * nosotros. Reactivarla es una acción de admin (/admin/usuarios), a
     * propósito no hay forma de desbloquearla sola — si fuera trivial,
     * cualquiera que intercepte el link podría abrir y cerrar el bloqueo él
     * mismo.
     */
    public function lock(Request $request, User $user): Response
    {
        if (! $user->isLocked()) {
            $user->forceFill(['locked_at' => now()])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();

            Log::warning('Cuenta bloqueada desde el aviso de sesión concurrente.', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);
        }

        return Inertia::render('Auth/AccountLocked');
    }
}
