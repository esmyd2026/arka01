<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Medalla del conductor por puntos ganados en carreras completadas (pedido
 * explícito del usuario: fidelizar el uso de la app en vez de arreglar
 * directo por WhatsApp). Catálogo editable desde /admin/medallas — mismo
 * espíritu que SubscriptionPlan, no son constantes en código.
 */
class DriverTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'min_points',
        'badge_emoji',
        'color_key',
        'is_public_eligible',
    ];

    protected $casts = [
        'min_points' => 'integer',
        'is_public_eligible' => 'boolean',
    ];

    /**
     * La medalla vigente de un conductor con $points puntos: la de mayor
     * min_points que no supere esa cantidad. Con la fila sembrada de
     * min_points=0 (ver la migración) siempre hay una que calza — el
     * fallback a la de menor min_points es solo defensivo, por si alguien
     * borra esa fila base a mano en la base de datos.
     */
    public static function forPoints(int $points): self
    {
        return static::query()
            ->where('min_points', '<=', $points)
            ->orderByDesc('min_points')
            ->first()
            ?? static::query()->orderBy('min_points')->firstOrFail();
    }

    /**
     * La siguiente medalla por puntos (sin importar si habilita el directorio
     * público o no) — `null` si ya está en la más alta. Antes este mismo
     * cálculo estaba repetido a mano en DriverProfileController::edit(), ahí
     * filtrado además por is_public_eligible (esa variante se queda tal cual,
     * es una pregunta distinta: "la próxima que lo hace público", no "la
     * próxima que sea").
     */
    public static function nextAfter(int $points): ?self
    {
        return static::query()
            ->where('min_points', '>', $points)
            ->orderBy('min_points')
            ->first();
    }

    /**
     * Lo mínimo que el frontend necesita para pintar la insignia (ver
     * resources/js/Utils/tierBadge.js) — nunca el modelo completo, evita
     * repetir este array a mano en cada controlador que lo manda.
     * min_points/is_public_eligible viajan también: no son datos sensibles
     * (el catálogo de medallas ya es público a efectos prácticos) y
     * DriverDirectoryController los necesita para filtrar/ordenar.
     *
     * @return array{name: string, badge_emoji: ?string, color_key: string, min_points: int, is_public_eligible: bool}
     */
    public function toBadge(): array
    {
        return [
            'name' => $this->name,
            'badge_emoji' => $this->badge_emoji,
            'color_key' => $this->color_key,
            'min_points' => $this->min_points,
            'is_public_eligible' => $this->is_public_eligible,
        ];
    }
}
