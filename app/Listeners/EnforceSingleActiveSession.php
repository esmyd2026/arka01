<?php

namespace App\Listeners;

use App\Exceptions\ActiveSessionExistsException;
use App\Mail\ConcurrentLoginAttemptMail;
use App\Models\User;
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
 * que se amplió a un año (para que la sesión no se cierre sola, ver
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
 *
 * Roadmap app móvil (ROADMAP_APLICACION_MOVIL_CAPACITOR.md, Hito 2): la
 * misma regla cubre los tokens de Sanctum que emite el login móvil
 * (Api\V1\AuthController) — "otra sesión activa" es una fila en `sessions`
 * O un token vigente en `personal_access_tokens`, lo primero que aparezca.
 * Así una cuenta no puede tener a la vez una sesión web y un token móvil (o
 * dos tokens móviles) desde dispositivos distintos, sin tener que duplicar
 * esta lógica en dos sitios.
 */
class EnforceSingleActiveSession
{
    /** Público a propósito: los tests lo reusan en vez de repetir el número a mano. */
    public const CONCURRENT_WINDOW_MINUTES = 15;

    public const DEVICE_COOKIE = 'arka01_device';

    public function handle(Login $event): void
    {
        $deviceId = $this->deviceId();
        $userId = $event->user->getAuthIdentifier();
        $activeSince = now()->subMinutes(self::CONCURRENT_WINDOW_MINUTES)->getTimestamp();

        $competing = $this->findCompetingSession($userId, $activeSince)
            ?? $this->findCompetingToken($userId, $activeSince);

        if (! $competing) {
            session(['device_id' => $deviceId]);

            return;
        }

        if ($competing['device_id'] === $deviceId) {
            // Mismo dispositivo: se cierra la sesión/token viejo sin avisar
            // por correo ni bloquear — no es una cuenta compartida, es la
            // misma persona reingresando.
            $this->revokeCompeting($competing);
            session(['device_id' => $deviceId]);

            Log::info('Sesión anterior del mismo dispositivo cerrada sola al reingresar.', [
                'user_id' => $userId,
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
     * Identificador del dispositivo. En web es una cookie propia (separada de
     * la de sesión), larga duración, que sobrevive un logout — si no existe
     * todavía (primera vez en este navegador), se genera y se deja en cola
     * para la respuesta. En móvil no hay cookies: el cliente Capacitor manda
     * su propio device_id (generado una vez y guardado en el dispositivo) en
     * el body del login, y ese es el que se usa.
     */
    private function deviceId(): string
    {
        $fromMobile = request()?->input('device_id');
        if (is_string($fromMobile) && $fromMobile !== '') {
            return $fromMobile;
        }

        $existing = request()?->cookie(self::DEVICE_COOKIE);
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $new = (string) Str::uuid();
        Cookie::queue(Cookie::forever(self::DEVICE_COOKIE, $new));

        return $new;
    }

    /**
     * Otra sesión web activa para este usuario, si hay. Devuelve la forma
     * común que también usa findCompetingToken(), para que handle() no tenga
     * que saber de cuál de las dos fuentes salió.
     *
     * @return array{type: string, id: int|string, device_id: ?string}|null
     */
    private function findCompetingSession(int|string $userId, int $activeSince): ?array
    {
        $session = DB::table('sessions')
            ->where('user_id', $userId)
            ->where('id', '!=', session()->getId())
            ->where('last_activity', '>=', $activeSince)
            ->first();

        if (! $session) {
            return null;
        }

        return [
            'type' => 'session',
            'id' => $session->id,
            'device_id' => $this->deviceIdFromPayload($session->payload),
        ];
    }

    /**
     * Igual que findCompetingSession() pero para tokens de Sanctum emitidos
     * por el login móvil (Api\V1\AuthController) — ver la migración que le
     * agrega device_id a personal_access_tokens.
     *
     * @return array{type: string, id: int|string, device_id: ?string}|null
     */
    private function findCompetingToken(int|string $userId, int $activeSince): ?array
    {
        $token = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $userId)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(function ($query) use ($activeSince) {
                $query->where('last_used_at', '>=', date('Y-m-d H:i:s', $activeSince))
                    ->orWhere('created_at', '>=', date('Y-m-d H:i:s', $activeSince));
            })
            ->latest('id')
            ->first();

        if (! $token) {
            return null;
        }

        return [
            'type' => 'token',
            'id' => $token->id,
            'device_id' => $token->device_id,
        ];
    }

    /**
     * @param  array{type: string, id: int|string, device_id: ?string}  $competing
     */
    private function revokeCompeting(array $competing): void
    {
        if ($competing['type'] === 'session') {
            DB::table('sessions')->where('id', $competing['id'])->delete();

            return;
        }

        DB::table('personal_access_tokens')->where('id', $competing['id'])->delete();
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
