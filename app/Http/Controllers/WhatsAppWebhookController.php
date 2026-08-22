<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessChatbotMessage;
use App\Jobs\SendWhatsAppNumberAlreadyRegisteredNotice;
use App\Jobs\SendWhatsAppPhoneMismatchNotice;
use App\Jobs\SendWhatsAppSessionRecoveryPrompt;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\WhatsAppConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook entrante de WhatsApp Cloud API (pedido explícito del usuario): acá
 * es donde Meta nos avisa que un conductor escribió "Hola" al número oficial
 * — ese mensaje es lo que abre la ventana de 24 horas de texto libre (ver
 * App\Models\WhatsAppSession). Sin este webhook, no hay forma de saber que la
 * ventana se abrió.
 *
 * Hay que configurar esta URL (…/api/webhooks/whatsapp) en Meta for
 * Developers → tu app → WhatsApp → Configuration → Webhook, con el mismo
 * valor de WHATSAPP_WEBHOOK_VERIFY_TOKEN del .env.
 */
class WhatsAppWebhookController extends Controller
{
    /**
     * Pedido explícito del usuario: frase que dispara el "bot" de
     * recuperación de sesión en vez de la confirmación genérica de
     * "activarme" del conductor — tiene que coincidir con el texto que arma
     * resources/js/Utils/whatsapp.js::buildSessionRecoveryWhatsAppUrl(). Se
     * compara sin mayúsculas ni tilde (isSessionRecoveryMessage()) para no
     * fallar si el navegador o WhatsApp normalizan el texto distinto.
     */
    private const SESSION_RECOVERY_TRIGGER_PHRASE = 'recuperar mi sesion';

    private function isSessionRecoveryMessage(string $text): bool
    {
        $normalized = str_replace(['ó', 'Ó'], ['o', 'O'], $text);

        return str_contains(mb_strtolower($normalized), self::SESSION_RECOVERY_TRIGGER_PHRASE);
    }

    /**
     * Meta llama a esto UNA vez, al guardar la URL del webhook, para
     * confirmar que el servidor es de verdad nuestro.
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        // Pedido explícito del usuario ("agregá log para ver qué está
        // pasando"): no se loguea el token recibido ni el configurado tal
        // cual (evita dejarlo en texto plano en storage/logs/laravel.log),
        // solo si coinciden — alcanza para diagnosticar un webhook mal
        // configurado sin exponer el secreto en el log.
        $tokenMatches = filled($token) && $token === WhatsAppConfig::webhookVerifyToken();

        Log::info('WhatsApp: intento de verificación del webhook.', [
            'mode' => $mode,
            'token_matches' => $tokenMatches,
            'has_challenge' => filled($challenge),
            'ip' => $request->ip(),
        ]);

        if ($mode === 'subscribe' && $tokenMatches && filled($challenge)) {
            return response($challenge, 200);
        }

        Log::warning('WhatsApp: verificación del webhook rechazada.', [
            'mode' => $mode,
            'token_matches' => $tokenMatches,
            'has_challenge' => filled($challenge),
        ]);

        return response('Token de verificación inválido.', 403);
    }

    /**
     * Cada mensaje entrante (de cualquier número, no solo conductores
     * nuestros) llega acá. Si el número coincide con el teléfono de un
     * usuario registrado, se abre una ventana nueva de 24 horas para él —
     * no importa qué haya escrito, cualquier mensaje cuenta como "abrir la
     * conversación" (regla de Meta, no nuestra).
     */
    public function receive(Request $request): Response
    {
        // Pedido explícito del usuario: ver el payload completo tal cual
        // llega — incluye tanto mensajes de texto como confirmaciones de
        // entrega/lectura ("statuses"), útil para confirmar que Meta está
        // llamando de verdad y con qué forma exacta.
        Log::info('WhatsApp: webhook recibido.', ['payload' => $request->all()]);

        $messages = collect($request->input('entry', []))
            ->flatMap(fn ($entry) => collect($entry['changes'] ?? [])->pluck('value.messages'))
            ->filter()
            ->flatten(1);

        if ($messages->isEmpty()) {
            Log::info('WhatsApp: webhook sin mensajes de texto (probablemente una confirmación de estado).');
        }

        foreach ($messages as $message) {
            $from = $message['from'] ?? null;
            if (! $from) {
                continue;
            }

            $fromE164 = '+'.$from;
            $text = $message['text']['body']
                ?? $message['interactive']['button_reply']['id']
                ?? $message['interactive']['list_reply']['id']
                ?? (isset($message['location']) ? '[ubicacion]' : '');
            $metadata = [
                'type' => $message['type'] ?? 'text',
                'location' => isset($message['location']) ? [
                    'lat' => $message['location']['latitude'] ?? null,
                    'lng' => $message['location']['longitude'] ?? null,
                    'name' => $message['location']['name'] ?? null,
                    'address' => $message['location']['address'] ?? null,
                ] : null,
            ];
            $refUserId = preg_match('/\(ref:(\d+)\)/', $text, $matches) ? (int) $matches[1] : null;

            $phoneOwner = User::query()->where('phone', $fromE164)->first();

            if ($phoneOwner) {
                // Bug reportado por el usuario (caso real: dos cuentas
                // distintas probando conectar el mismo número desde el link
                // "Conectar WhatsApp", cada una con su propia referencia):
                // un número es de una sola cuenta a la vez — si el mensaje
                // trae la referencia de OTRA cuenta, no se abre la ventana
                // como si el dueño real hubiese escrito, se avisa que ese
                // número ya está en uso (sin revelar de quién es).
                if ($refUserId !== null && $refUserId !== $phoneOwner->id) {
                    Log::warning('WhatsApp: se intentó conectar un número que ya es de otro conductor.', [
                        'from' => $from,
                        'phone_owner_user_id' => $phoneOwner->id,
                        'attempted_ref' => $refUserId,
                    ]);
                    SendWhatsAppNumberAlreadyRegisteredNotice::dispatch($fromE164);

                    continue;
                }

                $this->openWindowFor($phoneOwner, $text, isRecoveryPrompt: $this->isSessionRecoveryMessage($text), metadata: $metadata);

                continue;
            }

            // Pedido explícito del usuario: el número no coincide con
            // ningún perfil tal cual — antes de rendirse, se busca la
            // referencia embebida en el mensaje de "Conectar WhatsApp"
            // (ver resources/js/Utils/whatsapp.js, "(ref:ID)") para saber
            // quién intentó conectar de verdad, y validar su número contra
            // lo que declaró en su perfil de conductor.
            if ($refUserId === null) {
                // Pedido explícito del usuario: el chatbot también atiende a
                // números sin cuenta todavía (prospectos que escriben
                // "quiero ser conductor" antes de registrarse) — antes esto
                // se ignoraba en silencio.
                Log::info('WhatsApp: mensaje entrante de un número sin cuenta asociada — pasa al chatbot.', ['from' => $from]);
                ProcessChatbotMessage::dispatch($fromE164, $text, null, $metadata);

                continue;
            }

            $intendedUser = User::find($refUserId);

            if (! $intendedUser) {
                Log::info('WhatsApp: referencia de conexión a un usuario inexistente.', ['from' => $from, 'ref' => $refUserId]);

                continue;
            }

            if (! $intendedUser->phone) {
                // Pedido explícito del usuario ("si no está, agregalo"): sin
                // ningún teléfono declarado todavía, este mensaje sirve como
                // prueba de que el número es suyo de verdad.
                $intendedUser->update(['phone' => $fromE164]);
                Log::info('WhatsApp: teléfono completado a partir de la conexión.', ['user_id' => $intendedUser->id]);
                $this->openWindowFor($intendedUser, $text);

                continue;
            }

            // Pedido explícito del usuario ("si el número es diferente,
            // indicale"): no se abre la ventana a su nombre — los avisos
            // siempre se mandan al número de su perfil, no al que acaba de
            // escribir, así que abrir la ventana acá no serviría de nada.
            Log::warning('WhatsApp: el número que escribió no coincide con el declarado en el perfil.', [
                'user_id' => $intendedUser->id,
                'from' => $from,
            ]);
            SendWhatsAppPhoneMismatchNotice::dispatch($fromE164, $intendedUser->id);
        }

        // Meta reintenta si no responde 200 rápido — no hay nada más que
        // devolver, el procesamiento ya terminó.
        return response('', 200);
    }

    private function openWindowFor(User $user, string $text, bool $isRecoveryPrompt = false, array $metadata = []): void
    {
        WhatsAppSession::query()->create([
            'user_id' => $user->id,
            'opened_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);

        Log::info('WhatsApp: ventana de 24h abierta.', ['user_id' => $user->id, 'is_recovery_prompt' => $isRecoveryPrompt]);

        // Pedido explícito del usuario: si escribió la frase de recuperar
        // sesión, el "bot" lo manda de vuelta a la web — este flujo puntual
        // se deja intacto, tal cual estaba. Para cualquier otro mensaje,
        // antes se mandaba siempre la misma confirmación fija ("ya quedó
        // conectado"); ahora el chatbot lee el mensaje de verdad y responde
        // acorde (pedido explícito del usuario: dejar de ser "un mecanismo
        // para enviar mensajes específicos del sistema"). Encolado en los
        // dos casos para no demorar la respuesta 200 a Meta.
        if ($isRecoveryPrompt) {
            SendWhatsAppSessionRecoveryPrompt::dispatch($user->id);
        } else {
            ProcessChatbotMessage::dispatch($user->phone, $text, $user->id, $metadata);
        }
    }
}
