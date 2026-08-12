<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'fleet_id',
        'driver_user_id',
        'invited_by',
        'initiated_by',
        'status',
        'message',
        'responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class);
    }

    /**
     * El conductor invitado (a quién se le manda la invitación).
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    /**
     * Quién mandó esto — el cliente (dirección 'client', el caso de siempre)
     * o el propio conductor (dirección 'driver', pedido explícito del
     * usuario: un conductor buscando clientes para pedirles unirse a su
     * flota). Ver initiatedByDriver()/respondingPartyId() para quién le toca
     * responder en cada caso.
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function initiatedByDriver(): bool
    {
        return $this->initiated_by === 'driver';
    }

    /**
     * A quién le toca aceptar o rechazar esto — el conductor si fue el
     * cliente quien invitó (de siempre), o el cliente (dueño de la flota) si
     * fue el conductor quien mandó la solicitud. Ver
     * App\Policies\FleetInvitationPolicy::respond().
     */
    public function respondingPartyId(): int
    {
        return $this->initiatedByDriver() ? $this->fleet->owner_user_id : $this->driver_user_id;
    }
}
