<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una intención administrable del chatbot (pedido explícito del usuario,
 * /admin/chatbot) — ej. CODIGO_NO_RECIBIDO, INFORMACION_CONDUCTOR. Sus
 * vocablos viven en ChatbotIntentKeyword. `DESCONOCIDO` no es una fila acá:
 * es la clasificación por defecto de App\Services\Chatbot\IntentDetector
 * cuando nada supera el umbral de confianza.
 */
class ChatbotIntent extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'label',
        'role_scope',
        'is_active',
        'show_in_menu',
        'menu_label',
        'sort_order',
        'reply_message',
        'action',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'show_in_menu' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function keywords(): HasMany
    {
        return $this->hasMany(ChatbotIntentKeyword::class);
    }

    /**
     * Mismo criterio que Faq::scopeForAudience: "ambos" siempre aplica, más
     * lo propio del rol de quien escribe (si ya se sabe quién es).
     */
    public function scopeForRole($query, ?string $role)
    {
        return $role
            ? $query->whereIn('role_scope', [$role, 'ambos'])
            : $query;
    }
}
