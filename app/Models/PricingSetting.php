<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila única con los parámetros del cálculo de precio sugerido (sección 5).
 * Ver la migración create_pricing_settings_table: siempre existe una fila,
 * sembrada ahí mismo, así que current() nunca necesita un valor por defecto
 * en código — el único "default" real es el de esa migración inicial.
 */
class PricingSetting extends Model
{
    protected $fillable = [
        'night_surcharge_percent',
        'night_starts_at',
        'night_ends_at',
        'minimum_fare',
    ];

    public static function current(): self
    {
        return self::query()->firstOrFail();
    }
}
