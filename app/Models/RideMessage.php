<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un mensaje del chat temporal cliente↔conductor de una carrera puntual
 * (sección 10 del roadmap de mejoras) — ver RideMessageController.
 */
class RideMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'ride_id',
        'sender_user_id',
        'body',
    ];

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
