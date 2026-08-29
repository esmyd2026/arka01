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
        // Recargo de hora pico (pedido explícito del usuario): dos franjas
        // por día (mañana y tarde) — nunca se suma con el nocturno, ver
        // App\Services\PriceCalculator::suggestedPrice().
        'peak_surcharge_percent',
        'peak_morning_starts_at',
        'peak_morning_ends_at',
        'peak_evening_starts_at',
        'peak_evening_ends_at',
        // Cargo por trayecto de recogida (pedido explícito del usuario): el
        // conductor recorre esta distancia (Haversine, su ubicación actual
        // hasta el origen del cliente) sin pasajero — bajo el umbral se
        // sigue usando el colchón fijo de 0.8 km ya existente, sobre el
        // umbral se cobra aparte. Ver App\Services\PriceCalculator::pickupSurcharge().
        'pickup_surcharge_threshold_km',
        'pickup_surcharge_percent',
        'minimum_fare',
        // Ticket promedio por carrera (pedido explícito del usuario): valor
        // global que alimenta la proyección de ganancia mensual mostrada en
        // el catálogo de planes de conductor (ver SubscriptionPlan y
        // MyPlanController::attachEarningsProjection()).
        'average_ticket_price',
        // Antes era DriverProfile::STALE_AFTER_MINUTES, fija en el código
        // (pedido explícito del usuario: poder ajustarla sin desplegar) —
        // ver DriverProfile::staleAfterMinutes().
        'driver_stale_after_minutes',
    ];

    public static function current(): self
    {
        return self::query()->firstOrFail();
    }
}
