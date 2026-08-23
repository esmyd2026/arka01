<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fila única con los mensajes generales del chatbot (bienvenida, ayuda,
 * fallback, despedida — pedido explícito del usuario), mismo patrón
 * singleton que WhatsAppSetting/PricingSetting. A propósito en una tabla
 * separada de whatsapp_settings — no mezclar configuración transaccional
 * con la del asistente virtual.
 */
class ChatbotSetting extends Model
{
    protected $fillable = [
        'welcome_message',
        'help_message',
        'fallback_message',
        'fallback_escalation_message',
        'farewell_message',
        'max_fallback_attempts',
        // Contacto humano de soporte (pedido explícito del usuario): editable
        // desde /admin/chatbot, se manda como tarjeta de contacto de
        // WhatsApp de verdad al escalar a soporte — ver
        // WhatsAppFreeformSender::sendContact() y EscalateToSupportHandler.
        'support_contact_name',
        'support_contact_phone',
        'updated_by',
    ];

    protected $casts = [
        'max_fallback_attempts' => 'integer',
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
