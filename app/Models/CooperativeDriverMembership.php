<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CooperativeDriverMembership extends Model
{
    use HasFactory;

    protected $fillable = [
        'cooperative_id',
        'driver_user_id',
        'invited_by_user_id',
        'status',
        'responded_at',
        'suspended_at',
        'ended_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
        'suspended_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function cooperative(): BelongsTo
    {
        return $this->belongsTo(Cooperative::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * La cooperativa a la que este conductor está afiliado ahora mismo (o
     * null si es independiente) — mismo criterio que ya usaba
     * DashboardController::index(), movido acá para reusarlo también desde
     * HandleInertiaRequests::share() (pedido explícito del usuario: la
     * etiqueta de cooperativa en el menú de cuenta tiene que verse en
     * CUALQUIER pantalla, no solo en Inicio).
     */
    public static function activeCooperativeFor(int $driverUserId): ?Cooperative
    {
        return self::query()
            ->where('driver_user_id', $driverUserId)
            ->where('status', 'accepted')
            ->whereNull('ended_at')
            // public_id es obligatorio acá: AuthenticatedLayout arma el link
            // "Cooperativa: ..." del menú de cuenta con route('cooperatives.show',
            // $cooperative->public_id) — sin esta columna llegaba null y Ziggy
            // tiraba "'cooperative' parameter is required" (bug reportado por
            // el usuario: el menú del avatar del conductor no se desplegaba).
            ->with('cooperative:id,public_id,name,logo_path')
            ->first()?->cooperative;
    }
}
