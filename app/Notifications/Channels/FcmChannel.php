<?php

namespace App\Notifications\Channels;

use App\Services\Push\FcmSender;
use Illuminate\Notifications\Notification;

/**
 * Canal de notificación para push nativo (FCM) — groundwork del Hito 6.
 * Reusa el mismo `toWebPush()` que cada *PushNotification ya define para el
 * navegador (título/cuerpo/data) en vez de pedirle a cada una un método
 * nuevo — un solo lugar entiende cómo mandar a un token de FCM, las ~25
 * clases de notificación no cambian su forma de armar el mensaje.
 *
 * Un usuario puede tener varios dispositivos móviles con sesión activa
 * (ver personal_access_tokens.device_id) — se manda a todos los que
 * registraron un push_token de tipo 'fcm' mediante
 * Api\V1\DeviceController::updatePushToken().
 */
class FcmChannel
{
    public function __construct(private readonly FcmSender $sender) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! $this->sender->isConfigured() || ! method_exists($notification, 'toWebPush')) {
            return;
        }

        if (! method_exists($notifiable, 'tokens')) {
            return;
        }

        $deviceTokens = $notifiable->tokens()
            ->where('push_provider', 'fcm')
            ->whereNotNull('push_token')
            ->pluck('push_token');

        if ($deviceTokens->isEmpty()) {
            return;
        }

        $message = $notification->toWebPush($notifiable, $notification)->toArray();
        $title = (string) ($message['title'] ?? '');
        $body = (string) ($message['body'] ?? '');
        // FCM exige que cada valor de "data" sea un string — el mismo array
        // que WebPushMessage::data() ya arma (ids, categoría, url) pasa
        // casi siempre valores simples (int/string/bool).
        $data = array_map(
            fn ($value) => is_scalar($value) ? (string) $value : json_encode($value),
            (array) ($message['data'] ?? [])
        );

        foreach ($deviceTokens as $deviceToken) {
            $this->sender->send($deviceToken, $title, $body, $data);
        }
    }
}
