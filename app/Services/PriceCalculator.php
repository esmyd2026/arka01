<?php

namespace App\Services;

use App\Models\PricingSetting;
use Carbon\Carbon;

class PriceCalculator
{
    /**
     * Precio sugerido = distancia × tarifa del conductor × factor horario
     * (sección 5 del alcance). Devuelve el desglose completo (no solo el
     * total) porque el documento pide que el cálculo se muestre siempre
     * visible, nunca oculto. El recargo y el horario nocturno salen de
     * pricing_settings (editable desde /admin/tarifas), no de una constante.
     *
     * @return array{base: float, night_surcharge: float, total: float, is_night: bool}
     */
    public static function suggestedPrice(float $distanceKm, float $ratePerKm, ?Carbon $at = null): array
    {
        $at ??= now();
        $settings = PricingSetting::current();

        // Tarifa base mínima (pedido explícito del usuario, editable desde
        // /admin/tarifas): una carrera corta no puede salir tan barata que no
        // le convenga al conductor por los km. Si distancia × tarifa da menos
        // que el mínimo, se cobra el mínimo — el recargo nocturno se sigue
        // calculando sobre esta base ya ajustada, no sobre la de antes.
        $base = max(round($distanceKm * $ratePerKm, 2), (float) $settings->minimum_fare);
        $isNight = self::isNightTime($at, $settings);

        $surchargePercent = $isNight ? $settings->night_surcharge_percent : 0;
        $surcharge = round($base * ($surchargePercent / 100), 2);

        return [
            'base' => $base,
            'night_surcharge' => $surcharge,
            'total' => round($base + $surcharge, 2),
            'is_night' => $isNight,
        ];
    }

    /**
     * El rango nocturno cruza la medianoche (ej. 20:00 a 06:00), por eso no
     * alcanza con un simple "entre X e Y".
     */
    private static function isNightTime(Carbon $at, PricingSetting $settings): bool
    {
        $hour = (int) $at->format('G');
        $start = $settings->night_starts_at;
        $end = $settings->night_ends_at;

        if ($start > $end) {
            return $hour >= $start || $hour < $end;
        }

        return $hour >= $start && $hour < $end;
    }
}
