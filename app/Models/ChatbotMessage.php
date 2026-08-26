<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea de la transcripción completa de WhatsApp — entrante (del
 * usuario) o saliente (del bot, o de un admin cuando responde un ticket de
 * soporte y eso también sale por WhatsApp). Ver la migración para el porqué
 * de esta tabla nueva.
 */
class ChatbotMessage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'phone',
        'user_id',
        'direction',
        'body',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
