<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una respuesta completa a la encuesta corta de conductor/pasajero (pedido
 * explícito del usuario). `user_id` queda null si quien respondió no tenía
 * sesión iniciada — nunca es obligatorio tener cuenta para responder.
 */
class SurveyResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'role',
        'user_id',
        'answers',
    ];

    protected $casts = [
        'answers' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
