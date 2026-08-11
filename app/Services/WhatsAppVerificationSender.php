<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verificación de teléfono por WhatsApp (consideración de seguridad agregada
 * al alcance): usa la API oficial de WhatsApp Cloud (Meta) para mandar el
 * código como mensaje de plantilla — WhatsApp exige una plantilla
 * pre-aprobada para el primer mensaje que manda una cuenta de negocio, no
 * se puede mandar texto libre. La plantilla ("arka01_verificacion" por
 * defecto) hay que crearla y aprobarla en Meta Business Manager, con un
 * único parámetro de cuerpo para el código — ver .env.example.
 *
 * Si no está configurado (sin token/phone_number_id en .env), el registro
 * sigue funcionando: el teléfono queda auto-verificado en vez de bloquear
 * al usuario por una integración que el admin todavía no completó (mismo
 * criterio que `googleLoginEnabled`).
 */
class WhatsAppVerificationSender
{
    public static function enabled(): bool
    {
        return filled(WhatsAppConfig::token()) && filled(WhatsAppConfig::phoneNumberId());
    }

    /**
     * @param  string  $phoneE164  Ej. "+593991234567"
     */
    public static function sendCode(string $phoneE164, string $code): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $response = Http::withToken(WhatsAppConfig::token())
            ->post('https://graph.facebook.com/v20.0/'.WhatsAppConfig::phoneNumberId().'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($phoneE164, '+'),
                'type' => 'template',
                'template' => [
                    'name' => WhatsAppConfig::verificationTemplate(),
                    'language' => ['code' => 'es'],
                    'components' => [
                        [
                            'type' => 'body',
                            'parameters' => [['type' => 'text', 'text' => $code]],
                        ],
                    ],
                ],
            ]);

        if ($response->failed()) {
            Log::warning('No se pudo enviar el código de verificación por WhatsApp.', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            SystemEventLogger::log(
                eventType: 'whatsapp_verification_send_failed',
                module: 'whatsapp',
                message: "No se pudo enviar el código de verificación por WhatsApp a {$phoneE164}.",
                severity: 'error',
                context: ['status' => $response->status(), 'body' => $response->json()],
                channel: 'whatsapp',
                providerErrorCode: (string) $response->status(),
            );
        }

        return $response->successful();
    }
}
