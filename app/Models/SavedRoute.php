<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Mis rutas" (pedido explícito del usuario): un origen+destino que el
 * cliente guardó a propósito, con un alias opcional, para pedir la próxima
 * carrera sin volver a escribir ni marcar nada en el mapa.
 */
class SavedRoute extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_user_id',
        'alias',
        'origin_lat',
        'origin_lng',
        'origin_address',
        'origin_sector_id',
        'destination_lat',
        'destination_lng',
        'destination_address',
        'destination_sector_id',
    ];

    protected $casts = [
        'origin_lat' => 'decimal:7',
        'origin_lng' => 'decimal:7',
        'destination_lat' => 'decimal:7',
        'destination_lng' => 'decimal:7',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }
}
