<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fila única con la configuración de WhatsApp editable desde el admin
 * (mismo patrón singleton que PricingSetting::current()). Los campos
 * sensibles se guardan cifrados — ver App\Services\WhatsAppConfig para la
 * jerarquía "base de datos primero, .env como respaldo".
 */
class WhatsAppSetting extends Model
{
    use HasFactory;

    // Eloquent adivinaría "whats_app_settings" (separa "WhatsApp" en dos
    // palabras) — la migración usa el nombre real de la integración, sin el
    // guion bajo de más.
    protected $table = 'whatsapp_settings';

    protected $fillable = [
        'token',
        'phone_number_id',
        'verification_template',
        'business_number',
        'webhook_verify_token',
        'app_secret',
        'ride_notifications_enabled',
        'driver_ride_actions_enabled',
        'client_ride_booking_enabled',
        'privacy_notice_text',
        // Pedido explícito del usuario ("que yo las active o desactive y si
        // las desactivo entonce esas notificaciones no llegaran") — un
        // apagado puntual por tipo de aviso, distinto de
        // ride_notifications_enabled de arriba (el apagado general de
        // TODO). Ver App\Services\WhatsAppRideAccess::notificationTypeEnabled().
        'notify_ride_accepted',
        'notify_ride_started',
        'notify_ride_arrived',
        'notify_ride_picked_up',
        'notify_ride_completed',
        'notify_new_ride_alert',
        'notify_cooperative_invitation',
        'notify_scheduled_reminder',
        'notify_scheduled_overdue',
        'notify_offer_expired',
        'notify_driver_disconnected',
        'notify_session_expiring_soon',
        // Pedido explícito del usuario: "coloquemos precios estimados por
        // las cantidades de mensajes enviados" — editable a mano, el precio
        // real de Meta varía por categoría y país.
        'estimated_cost_per_message',
        'updated_by',
    ];

    protected $casts = [
        // Cifrados con la APP_KEY de la aplicación (pedido explícito del
        // usuario: "los valores sensibles deben almacenarse protegidos/
        // cifrados") — Eloquent los descifra solo al leer el atributo desde
        // PHP, nunca quedan en texto plano en la base.
        'token' => 'encrypted',
        'webhook_verify_token' => 'encrypted',
        'app_secret' => 'encrypted',
        'ride_notifications_enabled' => 'boolean',
        'driver_ride_actions_enabled' => 'boolean',
        'client_ride_booking_enabled' => 'boolean',
        'notify_ride_accepted' => 'boolean',
        'notify_ride_started' => 'boolean',
        'notify_ride_arrived' => 'boolean',
        'notify_ride_picked_up' => 'boolean',
        'notify_ride_completed' => 'boolean',
        'notify_new_ride_alert' => 'boolean',
        'notify_cooperative_invitation' => 'boolean',
        'notify_scheduled_reminder' => 'boolean',
        'notify_scheduled_overdue' => 'boolean',
        'notify_offer_expired' => 'boolean',
        'notify_driver_disconnected' => 'boolean',
        'notify_session_expiring_soon' => 'boolean',
        'estimated_cost_per_message' => 'decimal:4',
    ];

    // Pedido explícito del usuario: "nunca exponer tokens completos en
    // frontend" — ni por accidente, si algún día alguien serializa este
    // modelo entero a un Inertia::render() sin pasar por el controlador.
    protected $hidden = [
        'token',
        'webhook_verify_token',
        'app_secret',
    ];

    public static function current(): self
    {
        return self::query()->firstOrFail();
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
