<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estado de conversación del chatbot de WhatsApp (pedido explícito del
 * usuario) — una fila por número de teléfono, no por usuario: el chatbot
 * también atiende a prospectos sin cuenta todavía. Separada a propósito de
 * App\Models\WhatsAppSession (esa es el bookkeeping de la ventana de 24h
 * para conductores registrados, no se toca).
 */
class ChatbotConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone',
        'user_id',
        'pending_intent',
        'context',
        'unresolved_attempts',
        'last_message_at',
        'bot_paused',
    ];

    protected $casts = [
        'context' => 'array',
        'unresolved_attempts' => 'integer',
        'last_message_at' => 'datetime',
        'bot_paused' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function forPhone(string $phone): self
    {
        return self::query()->firstOrCreate(['phone' => $phone]);
    }
}
