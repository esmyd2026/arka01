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
     * visible, nunca oculto. El recargo, el horario nocturno y las franjas
     * de hora pico salen de pricing_settings (editable desde
     * /admin/tarifas), no de una constante.
     *
     * Pedido explícito del usuario ("subir un poco las tarifas en las horas
     * pico"): nocturno y pico NUNCA se suman entre sí — una carrera es una
     * cosa o la otra, nunca las dos (si algún día se solaparan en la
     * configuración, gana el nocturno, ya que suele ser el recargo más
     * alto de los dos).
     *
     * @return array{base: float, night_surcharge: float, peak_surcharge: float, total: float, is_night: bool, is_peak: bool}
     */
    public static function suggestedPrice(float $distanceKm, float $ratePerKm, ?Carbon $at = null, ?float $driverMinimumFare = null): array
    {
        $at ??= now();
        $settings = PricingSetting::current();

        // Tarifa base mínima (pedido explícito del usuario, editable desde
        // /admin/tarifas): una carrera corta no puede salir tan barata que no
        // le convenga al conductor por los km. Si distancia × tarifa da menos
        // que el mínimo, se cobra el mínimo — el recargo nocturno/pico se
        // sigue calculando sobre esta base ya ajustada, no sobre la de antes.
        //
        // El conductor puede declarar SU PROPIA tarifa mínima en su perfil
        // (pedido explícito del usuario, sección "el conductor define su
        // propia tarifa, la plataforma no impone precio") — se respeta
        // siempre que sea MENOR o igual a la de la plataforma (un conductor
        // dispuesto a aceptar carreras más baratas puede hacerlo). Si la
        // suya fuera mayor, acá se la recorta igual (además de bloquearse ya
        // al guardar el perfil, ver DriverProfileController::update()) —
        // doble candado por si el admin bajó su tarifa DESPUÉS de que el
        // conductor hubiera guardado una más alta cuando todavía era válida.
        $floor = $driverMinimumFare !== null
            ? min($driverMinimumFare, (float) $settings->minimum_fare)
            : (float) $settings->minimum_fare;

        $base = max(round($distanceKm * $ratePerKm, 2), $floor);
        $isNight = self::isNightTime($at, $settings);
        $isPeak = ! $isNight && self::isPeakTime($at, $settings);

        $nightSurcharge = $isNight ? round($base * ($settings->night_surcharge_percent / 100), 2) : 0.0;
        $peakSurcharge = $isPeak ? round($base * ($settings->peak_surcharge_percent / 100), 2) : 0.0;

        return [
            'base' => $base,
            'night_surcharge' => $nightSurcharge,
            'peak_surcharge' => $peakSurcharge,
            'total' => round($base + $nightSurcharge + $peakSurcharge, 2),
            'is_night' => $isNight,
            'is_peak' => $isPeak,
        ];
    }

    /**
     * El rango nocturno cruza la medianoche (ej. 20:00 a 06:00), por eso no
     * alcanza con un simple "entre X e Y".
     */
    private static function isNightTime(Carbon $at, PricingSetting $settings): bool
    {
        return self::isWithinHourRange((int) $at->format('G'), $settings->night_starts_at, $settings->night_ends_at);
    }

    /**
     * Hora pico (pedido explícito del usuario): dos franjas típicas de una
     * ciudad, mañana y tarde — mismo cálculo de cruce de medianoche que
     * isNightTime(), reutilizado para cada franja por si alguna configuración
     * puntual llegara a cruzarla (poco común, pero no cuesta nada cubrirlo).
     */
    private static function isPeakTime(Carbon $at, PricingSetting $settings): bool
    {
        $hour = (int) $at->format('G');

        return self::isWithinHourRange($hour, $settings->peak_morning_starts_at, $settings->peak_morning_ends_at)
            || self::isWithinHourRange($hour, $settings->peak_evening_starts_at, $settings->peak_evening_ends_at);
    }

    private static function isWithinHourRange(int $hour, int $start, int $end): bool
    {
        if ($start > $end) {
            return $hour >= $start || $hour < $end;
        }

        return $hour >= $start && $hour < $end;
    }
}
