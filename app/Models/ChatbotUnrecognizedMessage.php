<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Consultas no reconocidas" (pedido explícito del usuario, sección 15):
 * cada mensaje que App\Services\Chatbot\IntentDetector no logró clasificar
 * con suficiente confianza — visible desde /admin/chatbot para descubrir
 * necesidades reales y convertirlas en intención/vocablo/FAQ nueva.
 */
class ChatbotUnrecognizedMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone',
        'user_id',
        'role',
        'message',
        'best_guess_intent_code',
        'confidence',
        'reviewed_at',
    ];

    protected $casts = [
        'confidence' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
