<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un evento/error crítico registrado para el módulo de Monitoreo (sección 9
 * del roadmap de mejoras) — ver App\Services\SystemEventLogger.
 */
class SystemEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'severity',
        'module',
        'event_type',
        'channel',
        'user_id',
        'status',
        'message',
        'provider_error_code',
        'attempts',
        'last_attempt_at',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
        'last_attempt_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
