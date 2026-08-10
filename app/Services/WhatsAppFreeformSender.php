<?php

namespace App\Services;

use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mensajes de texto libre por WhatsApp (pedido explícito del usuario): a
 * diferencia de WhatsAppVerificationSender (que manda plantilla HSM porque es
 * el primer contacto), esto solo funciona DENTRO de la ventana de 24 horas
 * que se abre cuando el usuario le escribe primero al número oficial — ver
 * App\Models\WhatsAppSession. Mandar texto libre fuera de esa ventana lo
 * rechaza la propia API de Meta, así que el llamador siempre tiene que
 * chequear `$user->hasActiveWhatsAppSession()` antes de usar esto.
 */
class WhatsAppFreeformSender
{
    public static function enabled(): bool
    {
        return filled(config('services.whatsapp.token')) && filled(config('services.whatsapp.phone_number_id'));
    }

    /**
     * @param  string  $phoneE164  Ej. "+593991234567"
     */
    public static function sendText(string $phoneE164, string $message): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $response = Http::withToken(config('services.whatsapp.token'))
            ->post('https://graph.facebook.com/v20.0/'.config('services.whatsapp.phone_number_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($phoneE164, '+'),
                'type' => 'text',
                'text' => ['body' => $message],
            ]);

        if ($response->failed()) {
            Log::warning('No se pudo enviar el mensaje libre de WhatsApp.', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }

        return $response->successful();
    }

    /**
     * Aviso de carrera nueva (pedido explícito del usuario) — nombre del
     * cliente, dirección de recogida, distancia y valor aproximado, con un
     * link directo para abrir la app y aceptarla. Se usa tanto cuando se
     * crea la solicitud (RideRequestController) como cuando el despacho
     * secuencial le pasa el turno al siguiente candidato (RideDispatchAdvancer)
     * — mismo mensaje, dos disparadores. No hace nada si el conductor no
     * tiene la ventana de 24h abierta (le escribió "Hola" al número
     * oficial): fuera de esa ventana, Meta no deja mandar texto libre.
     */
    public static function sendNewRideAlert(User $driver, RideRequest $rideRequest): void
    {
        if (! $driver->phone || ! $driver->hasActiveWhatsAppSession()) {
            return;
        }

        $distanceKm = $driver->driverProfile?->current_lat !== null
            ? round(Haversine::distanceKm(
                (float) $driver->driverProfile->current_lat,
                (float) $driver->driverProfile->current_lng,
                (float) $rideRequest->origin_lat,
                (float) $rideRequest->origin_lng,
            ), 1)
            : null;

        // Pedido explícito del usuario: indicar cuánto tiempo tiene para
        // responder — solo aplica al despacho secuencial (una solicitud
        // DIRIGIDA no tiene vencimiento, queda pendiente hasta que el
        // cliente la cancele a mano).
        $secondsLeft = $rideRequest->current_offer_expires_at
            ? max(0, $rideRequest->current_offer_expires_at->getTimestamp() - now()->getTimestamp())
            : null;

        $message = "🚗 ¡Carrera nueva de {$rideRequest->client->name}!\n"
            .'Recogida: '.($rideRequest->origin_address ?? 'ver en la app')."\n"
            .($distanceKm !== null ? "Distancia: {$distanceKm} km\n" : '')
            ."Valor aproximado: \${$rideRequest->current_offered_price}\n"
            .($secondsLeft !== null ? "⏱ Tiene {$secondsLeft} segundos para aceptar antes de que pase al siguiente conductor.\n" : '')
            ."\nAbra Arka01 para aceptarla:\n".route('rides.index')
            // Pedido explícito del usuario: un conductor puede seguir
            // recibiendo estos avisos por WhatsApp aunque la app esté en
            // segundo plano (ver DriverProfile::isReachable()) — si no
            // quiere más, tiene que desconectarse a propósito desde la app,
            // no alcanza con cerrarla o dejarla en segundo plano.
            ."\n\n¿No quiere más solicitudes? Desconéctese desde la app para dejar de recibirlas.";

        self::sendText($driver->phone, $message);
    }

    /**
     * Pedido explícito del usuario: avisarle al conductor que se le acabó el
     * tiempo para responder una oferta del despacho secuencial — antes se
     * enteraba solo por la app (si la tenía abierta); ahora también por
     * WhatsApp, igual que el aviso de la oferta en sí.
     */
    public static function sendOfferExpiredNotice(User $driver): void
    {
        if (! $driver->phone || ! $driver->hasActiveWhatsAppSession()) {
            return;
        }

        $message = '⏱ Se le acabó el tiempo para responder — esa carrera ya pasó a otro conductor.';

        self::sendText($driver->phone, $message);
    }

    /**
     * Pedido explícito del usuario: avisar al conductor cuando se desconecta
     * — ya sea porque cerró sesión/se puso "no disponible" a propósito, o
     * porque el barrido (App\Console\Commands\SweepStaleDriverAvailability)
     * lo detectó sin ping reciente — para animarlo a volver a activarse. Se
     * llama SIEMPRE desde App\Jobs\NotifyDriverDisconnectedByWhatsApp (nunca
     * sincrónico desde el request/comando que lo dispara), y el chequeo de
     * la ventana de 24h se hace acá, en el momento real de mandar el
     * mensaje — no al encolar el job, que puede tardar un rato en correr.
     */
    public static function sendDisconnectedAlert(User $driver): void
    {
        if (! $driver->phone || ! $driver->hasActiveWhatsAppSession()) {
            return;
        }

        $message = "👋 Se desconectó de Arka01 y dejó de recibir carreras.\n"
            .'Cuando quiera volver a estar disponible, actívese de nuevo desde la app.';

        self::sendText($driver->phone, $message);
    }

    /**
     * Pedido explícito del usuario: el "bot" le responde apenas se abre la
     * ventana de 24h (WhatsAppWebhookController::receive()), confirmándole
     * que ya quedó conectado — le sirve de comprobante de que el WhatsApp
     * está bien enlazado para recibir avisos de carreras.
     */
    public static function sendWindowConfirmation(User $driver): void
    {
        if (! $driver->phone || ! $driver->hasActiveWhatsAppSession()) {
            return;
        }

        $message = "✅ ¡Listo, {$driver->name}! Ya quedó conectado y activo para recibir avisos de carreras nuevas por acá.\n\n"
            .'A partir de ahora le vamos a escribir apenas le llegue una solicitud — ¡a generar ingresos! 🚀';

        self::sendText($driver->phone, $message);
    }

    /**
     * Pedido explícito del usuario: verificar el número desde el que se
     * conecta contra el que declaró en su perfil de conductor — si no
     * coincide, no se abre la ventana en su nombre (los avisos siempre se
     * mandan al número del perfil, no al que acaba de escribir), y se le
     * avisa acá mismo por qué. Se manda al número que ACABA de escribir
     * (`$toE164`, no `$intendedDriver->phone`) — es el único que Meta nos
     * habilita a esta altura, todavía no hay ninguna ventana abierta a
     * nombre del conductor real.
     */
    public static function sendPhoneMismatchNotice(string $toE164, User $intendedDriver): void
    {
        $registeredHint = $intendedDriver->phone ? substr($intendedDriver->phone, -4) : null;

        $message = '⚠️ Este número no coincide con el que tiene registrado en su perfil de Arka01'
            .($registeredHint ? " (termina en {$registeredHint})" : '').".\n\n"
            .'Para conectar los avisos de carreras, escríbanos desde ese mismo número, o actualice su teléfono desde su perfil en la app.';

        self::sendText($toE164, $message);
    }

    /**
     * Bug reportado por el usuario (caso real visto en el webhook: una
     * cuenta distinta trató de conectar un número que ya estaba verificado a
     * nombre de otro conductor): un número de WhatsApp es de una sola cuenta
     * a la vez — no se abre la ventana en nombre de quien lo intenta de
     * nuevo, se le avisa por qué. No se manda ningún dato del dueño real del
     * número (privacidad) — solo que ya está en uso.
     */
    public static function sendNumberAlreadyRegisteredNotice(string $toE164): void
    {
        $message = "⚠️ Este número de WhatsApp ya está conectado a otra cuenta de Arka01.\n\n"
            .'Si le parece un error, escríbanos, o actualice su número desde su perfil de conductor con otro número que no esté en uso.';

        self::sendText($toE164, $message);
    }

    /**
     * Sesión única por cuenta (pedido explícito del usuario, caso real: no
     * sabía en qué navegador había quedado logueado): el código para cerrar
     * esa otra sesión, mandado por acá cuando el dueño tiene la ventana de
     * 24h abierta — más rápido que esperar el correo. Se manda SIEMPRE al
     * teléfono declarado en el perfil, nunca a uno que alguien más escriba,
     * mismo criterio que el resto de los avisos de esta clase. Incluye
     * también un link para bloquear la cuenta de inmediato si no fue el
     * dueño real quien lo pidió (pedido explícito del usuario: "si no es
     * usted, solicitar bloquear la cuenta" — no alcanzaba con "ignore este
     * mensaje").
     */
    /**
     * Pedido explícito del usuario: en vez de que "Pedir código" sea el
     * primer paso (y ahí recién intentar mandarlo por WhatsApp, fallando en
     * silencio a correo si la ventana de 24h no estaba abierta), el widget
     * de sesión única en Auth/Login.vue ahora invita a escribir primero al
     * WhatsApp oficial — este es el "bot" confirmando que ya puede volver a
     * la web y tocar "Pedir código" de una, con el WhatsApp ya listo para
     * recibirlo. Ver WhatsAppWebhookController::receive() (detecta la frase
     * exacta de Utils/whatsapp.js::buildSessionRecoveryWhatsAppUrl()) y
     * SessionTakeoverController::request() (el que manda el código en sí).
     */
    public static function sendSessionRecoveryPrompt(User $user): void
    {
        if (! $user->phone || ! $user->hasActiveWhatsAppSession()) {
            return;
        }

        $message = '✅ ¡Listo! Ya puede volver a la página de inicio de sesión de Arka01 y tocar "Pedir código" — se lo mandamos por acá.';

        self::sendText($user->phone, $message);
    }

    public static function sendSessionTakeoverCode(User $user, string $code, string $lockUrl): void
    {
        if (! $user->phone || ! $user->hasActiveWhatsAppSession()) {
            return;
        }

        $message = "🔐 Alguien solicitó cerrar su otra sesión de Arka01 para entrar desde un dispositivo nuevo.\n\n"
            ."Su código es: {$code}\n"
            ."Vence en 10 minutos.\n\n"
            ."¿No fue usted? Bloquee su cuenta de inmediato:\n{$lockUrl}";

        self::sendText($user->phone, $message);
    }
}
