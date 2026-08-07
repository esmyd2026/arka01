<?php

namespace App\Listeners;

use App\Exceptions\ActiveSessionExistsException;
use App\Mail\ConcurrentLoginAttemptMail;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Se dispara en cada login (contraseña o Google) — un único punto de control
 * para la regla de sesión única, en vez de duplicar la validación en cada
 * controlador. Si ya existe otra sesión activa para este usuario, deshace el
 * login recién hecho y avisa con ActiveSessionExistsException, que cada punto
 * de entrada (LoginRequest, GoogleAuthController) convierte en el mensaje
 * adecuado para su propio flujo.
 *
 * Nota: por esto mismo la app no ofrece "recordarme" (sección de seguridad
 * agregada al alcance) — un remember-token dejaría reautenticar en silencio
 * sin pasar por el formulario de login, saltándose el mensaje claro que pide
 * cerrar la otra sesión primero.
 */
class EnforceSingleActiveSession
{
    public function handle(Login $event): void
    {
        $activeSince = now()->subMinutes((int) config('session.lifetime'))->getTimestamp();

        $hasOtherActiveSession = DB::table('sessions')
            ->where('user_id', $event->user->getAuthIdentifier())
            ->where('id', '!=', session()->getId())
            ->where('last_activity', '>=', $activeSince)
            ->exists();

        if (! $hasOtherActiveSession) {
            return;
        }

        Log::warning('Login bloqueado: ya había una sesión activa en otro dispositivo.', [
            'user_id' => $event->user->getAuthIdentifier(),
            'guard' => $event->guard,
        ]);

        // Avisar al dueño real de la cuenta (no a quien intentó entrar): así
        // se entera si fue él desde otro equipo, o si alguien más lo intenta.
        // Si el envío falla (ej. el servidor de correo local no está levantado),
        // no puede tirar abajo el login: el usuario tiene que seguir viendo el
        // mensaje claro de "sesión activa en otro dispositivo", no un error 500
        // por un problema de infraestructura de correo que no es asunto suyo.
        try {
            $lockUrl = URL::temporarySignedRoute('session-takeover.lock', now()->addMinutes(30), ['user' => $event->user->id]);

            Mail::to($event->user->email)->send(
                new ConcurrentLoginAttemptMail($event->user, request()?->ip(), $lockUrl)
            );
        } catch (\Throwable $e) {
            Log::warning('No se pudo enviar el aviso de intento de login concurrente.', [
                'user_id' => $event->user->getAuthIdentifier(),
                'error' => $e->getMessage(),
            ]);
        }

        Auth::guard($event->guard)->logout();
        session()->invalidate();

        throw new ActiveSessionExistsException;
    }
}
