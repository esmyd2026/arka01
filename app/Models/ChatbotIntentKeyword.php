<?php

namespace App\Models;

use App\Services\Chatbot\MessageNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un vocablo/expresión asociada a una intención (pedido explícito del
 * usuario, /admin/chatbot) — se guarda ya normalizada (ver
 * App\Services\Chatbot\MessageNormalizer) para que la comparación contra el
 * mensaje entrante sea directa, sin normalizar en cada búsqueda.
 */
class ChatbotIntentKeyword extends Model
{
    use HasFactory;

    protected $fillable = [
        'chatbot_intent_id',
        'phrase',
        'weight',
    ];

    protected $casts = [
        'weight' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (ChatbotIntentKeyword $keyword) {
            $keyword->phrase = MessageNormalizer::normalize($keyword->phrase);
        });
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(ChatbotIntent::class, 'chatbot_intent_id');
    }
}
