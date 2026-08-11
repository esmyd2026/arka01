<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Una pregunta frecuente del Centro de Ayuda (sección 11 del roadmap de
 * mejoras) — administrable desde /admin/preguntas-frecuentes.
 */
class Faq extends Model
{
    use HasFactory;

    protected $fillable = [
        'audience',
        'category',
        'question',
        'answer',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Las de "ambos" siempre se ven, más las propias del rol de quien mira.
     */
    public function scopeForAudience($query, string $audience)
    {
        return $query->whereIn('audience', [$audience, 'ambos']);
    }
}
