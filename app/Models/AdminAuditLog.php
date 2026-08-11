<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro inmutable de una acción administrativa crítica (sección 18 del
 * roadmap de mejoras) — ver App\Services\AdminAuditLogger.
 */
class AdminAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'admin_user_id',
        'action',
        'module',
        'old_value',
        'new_value',
        'created_at',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'created_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
