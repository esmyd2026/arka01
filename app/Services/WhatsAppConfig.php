<?php

namespace App\Services;

use App\Models\WhatsAppSetting;

/**
 * Jerarquía única de dónde sale cada valor de configuración de WhatsApp
 * (pedido explícito del usuario, roadmap de mejoras sección 8):
 * 1. Lo que el admin configuró desde /admin/integraciones/whatsapp.
 * 2. Si no hay nada configurado ahí, el respaldo de siempre: .env
 *    (config/services.php) — nunca se elimina ese soporte.
 *
 * TODO el código que antes leía `config('services.whatsapp.*')` directo pasa
 * a usar esta clase, para que haya un solo lugar con esta jerarquía en vez
 * de repetirla en cada Service/Controller.
 */
class WhatsAppConfig
{
    public static function token(): ?string
    {
        return WhatsAppSetting::current()->token ?: config('services.whatsapp.token');
    }

    public static function phoneNumberId(): ?string
    {
        return WhatsAppSetting::current()->phone_number_id ?: config('services.whatsapp.phone_number_id');
    }

    public static function verificationTemplate(): ?string
    {
        return WhatsAppSetting::current()->verification_template ?: config('services.whatsapp.verification_template');
    }

    public static function businessNumber(): ?string
    {
        return WhatsAppSetting::current()->business_number ?: config('services.whatsapp.business_number');
    }

    public static function webhookVerifyToken(): ?string
    {
        return WhatsAppSetting::current()->webhook_verify_token ?: config('services.whatsapp.webhook_verify_token');
    }

    public static function appSecret(): ?string
    {
        return WhatsAppSetting::current()->app_secret ?: config('services.whatsapp.app_secret');
    }
}
