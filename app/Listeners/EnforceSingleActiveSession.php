<?php

namespace App\Listeners;

use App\Exceptions\ActiveSessionExistsException;
use App\Mail\ConcurrentLoginAttemptMail;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

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
 *
 * Bug/pedido real del usuario: la sesión "otra activa" se medía contra
 * config('session.lifetime') — bien cuando ese valor eran 2 horas, pero desde
 * que se subió a 30 días (para que la sesión no se cierre sola, ver
 * Arka01_Progreso.md) esto empezó a bloquear logins legítimos por una sesión
 * de hace semanas que el usuario ni recordaba. "Otra sesión activa" ahora se
 * mide con una ventana corta y propia (CONCURRENT_WINDOW_MINUTES), separada
 * de cuánto dura la sesión — lo que importa acá es si hay uso concurrente de
 * verdad, no si la otra sesión sigue siendo técnicamente válida.
 *
 * Además, pedido explícito del usuario: si la otra sesión activa es del
 * MISMO navegador (misma cookie de dispositivo, ver deviceId() abajo), no
 * tiene sentido bloquear ni avisar por correo — es la misma persona
 * reingresando (otra pestaña, sesión vencida, etc.), no una cuenta
 * compartida. En ese caso se cierra la sesión vieja sola y el login sigue
 * normal. Solo se bloquea (con el aviso de siempre) cuando la otra sesión
 * activa viene de un navegador/dispositivo distinto.
 */
class EnforceSingleActiveSession
{
    /** Público a propósito: los tests lo reusan en vez de repetir el número a mano. */
    public const CONCURRENT_WINDOW_MINUTES = 15;

    public const DEVICE_COOKIE = 'arka01_device';

    public function handle(Login $event): void
    {
        $deviceId = $this->deviceId();

        $activeSince = now()->subMinutes(self::CONCURRENT_WINDOW_MINUTES)->getTimestamp();

        $otherSession = DB::table('sessions')
            ->where('user_id', $event->user->getAuthIdentifier())
            ->where('id', '!=', session()->getId())
            ->where('last_activity', '>=', $activeSince)
            ->first();

        if (! $otherSession) {
            session(['device_id' => $deviceId]);

            return;
        }

        if ($this->deviceIdFromPayload($otherSession->payload) === $deviceId) {
            // Mismo navegador: se cierra la sesión vieja sin avisar por
            // correo ni bloquear — no es una cuenta compartida, es la misma
            // persona reingresando.
            DB::table('sessions')->where('id', $otherSession->id)->delete();
            session(['device_id' => $deviceId]);

            Log::info('Sesión anterior del mismo dispositivo cerrada sola al reingresar.', [
                'user_id' => $event->user->getAuthIdentifier(),
            ]);

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

    /**
     * Identificador de este navegador — no de la persona ni de la cuenta:
     * una cookie propia (separada de la de sesión), larga duración, que
     * sobrevive un logout. Si no existe todavía (primera vez en este
     * navegador), se genera y se deja en cola para la respuesta.
     */
    private function deviceId(): string
    {
        $existing = request()?->cookie(self::DEVICE_COOKIE);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $new = (string) Str::uuid();
        Cookie::queue(Cookie::forever(self::DEVICE_COOKIE, $new));

        return $new;
    }

    /**
     * El `device_id` guardado en el payload serializado de otra sesión (ver
     * dónde se guarda arriba: session(['device_id' => ...])). El payload de
     * `sessions` no está encriptado en esta app (config/session.php,
     * 'encrypt' => false), así que alcanza con decodificar base64 +
     * unserialize — mismo formato que usa StartSession internamente.
     */
    private function deviceIdFromPayload(string $payload): ?string
    {
        $data = @unserialize(base64_decode($payload));

        return is_array($data) ? ($data['device_id'] ?? null) : null;
    }
}
