<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fleet extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_user_id',
        'name',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(FleetInvitation::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(FleetMember::class);
    }

    /**
     * Solo los miembros vigentes (no los que ya se fueron o fueron dados de baja).
     * Es la lista que el cliente ve como "mi flota" en la sección 3.2.
     */
    public function activeMembers(): HasMany
    {
        return $this->members()->whereNull('left_at');
    }

    /**
     * Cupo usado contra el límite de conductores por flota del plan vigente
     * del cliente (sección 7.3 y 9.6, ver App\Services\PlanLimits), para
     * mostrar "14 de 20 conductores".
     */
    public function activeMemberCount(): int
    {
        return $this->activeMembers()->count();
    }
}
