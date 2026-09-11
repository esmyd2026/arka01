<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verificación de teléfono por WhatsApp (consideración de seguridad agregada
 * al alcance): usa la API oficial de WhatsApp Cloud (Meta) para mandar el
 * código como mensaje de plantilla — WhatsApp exige una plantilla
 * pre-aprobada para el primer mensaje que manda una cuenta de negocio, no
 * se puede mandar texto libre. El nombre de la plantilla se configura desde
 * /admin/integraciones/whatsapp (o WHATSAPP_VERIFICATION_TEMPLATE en .env
 * como respaldo, ver WhatsAppConfig) — tiene que coincidir EXACTO con el
 * nombre real aprobado en Meta Business Manager, con un único parámetro de
 * cuerpo para el código y en el idioma TEMPLATE_LANGUAGE de abajo (tiene que
 * ser el mismo idioma con el que se aprobó ahí, no cualquier variante de
 * español).
 *
 * Si no está configurado (sin token/phone_number_id en .env), el registro
 * sigue funcionando: el teléfono queda auto-verificado en vez de bloquear
 * al usuario por una integración que el admin todavía no completó (mismo
 * criterio que `googleLoginEnabled`).
 */
class WhatsAppVerificationSender
{
    /**
     * Bug real reportado por el usuario (error 132001 de Meta, "Template
     * name does not exist in the translation"): la plantilla de verificación
     * está aprobada en Meta Business Manager como "Spanish (ECU)", no como
     * español genérico — Meta busca la traducción exacta por este código, no
     * por idioma en general. Una sola constante (no repetida en el request y
     * en los dos logs de abajo) para que nunca se desincronicen si el día de
     * mañana hay que volver a cambiarla.
     */
    private const TEMPLATE_LANGUAGE = 'es_EC';

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

        // Timeout explícito — mismo motivo que WhatsAppFreeformSender::sendText().
        try {
            $response = Http::withToken(WhatsAppConfig::token())
                ->connectTimeout(3)
                ->timeout(8)
                ->retry(1, 200)
                ->post('https://graph.facebook.com/v20.0/'.WhatsAppConfig::phoneNumberId().'/messages', [
                    'messaging_product' => 'whatsapp',
                    'to' => ltrim($phoneE164, '+'),
                    'type' => 'template',
                    'template' => [
                        'name' => WhatsAppConfig::verificationTemplate(),
                        'language' => ['code' => self::TEMPLATE_LANGUAGE],
                        'components' => [
                            [
                                'type' => 'body',
                                'parameters' => [['type' => 'text', 'text' => $code]],
                            ],
                        ],
                    ],
                ]);
        } catch (Throwable $exception) {
            // Una caída de Meta o de red no debe transformarse en un error
            // 500 de toda la aplicación ni registrar teléfono/token/código.
            Log::warning('No se pudo conectar con WhatsApp para enviar el código.', [
                'exception' => $exception::class,
            ]);

            SystemEventLogger::log(
                eventType: 'whatsapp_verification_unavailable',
                module: 'whatsapp',
                message: 'WhatsApp no estuvo disponible para enviar un código de verificación.',
                severity: 'error',
                context: ['exception' => $exception::class],
                channel: 'whatsapp',
            );

            return false;
        }

        if ($response->failed()) {
            // Diagnóstico real reportado por el usuario: el error 132001 de
            // Meta ("Template name does not exist in the translation") no
            // decía CUÁL plantilla/idioma se había intentado — había que ir
            // al código para saberlo. Con esto en el detalle del error, se ve
            // de una si el problema es el nombre o el idioma sin salir del
            // panel admin.
            Log::warning('No se pudo enviar el código de verificación por WhatsApp.', [
                'status' => $response->status(),
                'provider_error_code' => $response->json('error.code'),
                'template' => WhatsAppConfig::verificationTemplate(),
                'language' => self::TEMPLATE_LANGUAGE,
            ]);

            SystemEventLogger::log(
                eventType: 'whatsapp_verification_send_failed',
                module: 'whatsapp',
                message: 'WhatsApp rechazó el envío de un código de verificación.',
                severity: 'error',
                context: [
                    'status' => $response->status(),
                    'provider_error_code' => $response->json('error.code'),
                    'template' => WhatsAppConfig::verificationTemplate(),
                    'language' => self::TEMPLATE_LANGUAGE,
                ],
                channel: 'whatsapp',
                providerErrorCode: (string) $response->status(),
            );
        }

        return $response->successful();
    }
}
