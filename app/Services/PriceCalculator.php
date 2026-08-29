<?php

namespace App\Services;

use App\Models\DriverProfile;
use App\Models\PricingSetting;
use Carbon\Carbon;

class PriceCalculator
{
    /**
     * Pedido explícito del usuario: "súbele siempre a cada carrera... a los
     * km 800 metros más" — un margen fijo que se suma a la distancia ANTES
     * de calcular el precio, en toda carrera pedida por un cliente (ahora
     * mismo, programada, o el piso de precio de un Expreso — ver
     * ExpressRouteController::suggestedPrice(), que llama a este mismo
     * método). Cubre desvíos reales de ruta, imprecisión del pin de
     * origen/destino y el tramo de acercamiento del conductor, sin que el
     * conductor tenga que pelear por eso carrera por carrera.
     *
     * A propósito NO se suma a `distance_km` guardado ni al que se le
     * muestra al cliente/conductor como "distancia del viaje" (eso sigue
     * siendo la distancia real, para no mentirle a nadie en pantalla ni
     * mover el umbral de puntos del conductor en RideController::complete())
     * — el margen queda encapsulado acá adentro, solo afecta el precio.
     */
    public const DISTANCE_PADDING_KM = 0.8;

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
     * El margen de DISTANCE_PADDING_KM se suma PRIMERO, así que el recargo
     * nocturno/pico (que se calcula como % de `$base`, más abajo) ya queda
     * aplicado sobre la distancia con margen incluido — nada de sumarlo
     * aparte ni de tocar esa lógica.
     *
     * @return array{base: float, night_surcharge: float, peak_surcharge: float, total: float, is_night: bool, is_peak: bool}
     */
    public static function suggestedPrice(float $distanceKm, float $ratePerKm, ?Carbon $at = null, ?float $driverMinimumFare = null): array
    {
        $at ??= now();
        $settings = PricingSetting::current();
        $distanceKm += self::DISTANCE_PADDING_KM;

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
            // Pedido explícito del usuario: el monto final de la carrera
            // siempre redondeado hacia ARRIBA a los 10 centavos (5.35→5.40,
            // 5.92→6.00, 5.05→5.10) — nunca hacia abajo, para que el
            // conductor no pierda centavos por el redondeo. El desglose
            // (base/recargos) queda con su valor exacto para que se siga
            // entendiendo de dónde sale el total, solo el total ya cobrado
            // se ajusta a la décima.
            'total' => self::roundUpToDime($base + $nightSurcharge + $peakSurcharge),
            'is_night' => $isNight,
            'is_peak' => $isPeak,
        ];
    }

    /**
     * Redondea hacia arriba a los 10 centavos más cercanos (nunca hacia
     * abajo). El `round(..., 4)` previo evita que un residuo de coma
     * flotante (ej. 5.40 guardado como 5.399999999999) empuje de más al
     * siguiente escalón.
     */
    public static function roundUpToDime(float $amount): float
    {
        return ceil(round($amount, 4) * 10) / 10;
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

    /**
     * Cargo aparte por el trayecto que el conductor recorre para ir a
     * buscar al cliente (pedido explícito del usuario) — independiente de
     * suggestedPrice() a propósito, para no tocar su firma ni el padding
     * fijo de DISTANCE_PADDING_KM que ya usan los ~15 llamadores existentes
     * (Expreso, paradas, WhatsApp, tests). Bajo el umbral configurado
     * (`pricing_settings.pickup_surcharge_threshold_km`, editable desde
     * /admin/tarifas) ese padding fijo ya cubre el acercamiento y este
     * método no agrega nada — solo sobre el umbral se calcula el cargo real:
     * distancia_recogida × tarifa_del_conductor × porcentaje configurado
     * (ejemplo del usuario: 8 km a $0.30/km × 55% = $1.32). Quien llama a
     * esto decide si lo suma al precio final o no (ver
     * App\Services\Ride\RideRequestResponder::accept(), el conductor elige
     * cobrarlo o no al aceptar la solicitud).
     *
     * @return array{distance_km: float, exceeds_threshold: bool, fare: float}
     */
    public static function pickupSurcharge(float $pickupDistanceKm, float $ratePerKm, ?PricingSetting $settings = null): array
    {
        $settings ??= PricingSetting::current();
        $exceedsThreshold = $pickupDistanceKm > (float) $settings->pickup_surcharge_threshold_km;

        return [
            'distance_km' => round($pickupDistanceKm, 2),
            'exceeds_threshold' => $exceedsThreshold,
            'fare' => $exceedsThreshold
                ? round($pickupDistanceKm * $ratePerKm * ($settings->pickup_surcharge_percent / 100), 2)
                : 0.0,
        ];
    }

    /**
     * Distancia y cargo de recogida para el candidato ACTUAL de una
     * solicitud — un solo lugar para los tres puntos que necesitan esto:
     * App\Services\Ride\RideRequestCreator::create() al armar la solicitud,
     * y App\Services\RideDispatchAdvancer::advanceOrExpire()/
     * activateNextWaitingRequest() al pasar al siguiente candidato de la
     * cascada (cada conductor está en otro lugar, hay que recalcular). Sin
     * ubicación conocida del conductor no hay nada que calcular — se deja en
     * null, no se inventa un cargo.
     *
     * Pedido explícito del usuario: el conductor puede apagar esto desde su
     * propio perfil (`driver_profiles.pickup_surcharge_enabled`), igual que
     * su tarifa por km — con el interruptor apagado, la función no existe
     * para él: ni se calcula la distancia ni se le muestra nada en ninguna
     * solicitud, sin importar qué tan lejos esté del cliente.
     *
     * @return array{distance_km: ?float, fare: ?float}
     */
    public static function pickupSurchargeForDriver(int $driverUserId, float $originLat, float $originLng): array
    {
        $profile = DriverProfile::query()->where('user_id', $driverUserId)->first();

        if (! $profile || ! $profile->pickup_surcharge_enabled || $profile->current_lat === null || $profile->current_lng === null) {
            return ['distance_km' => null, 'fare' => null];
        }

        $distanceKm = Haversine::distanceKm($originLat, $originLng, (float) $profile->current_lat, (float) $profile->current_lng);

        return [
            'distance_km' => round($distanceKm, 2),
            'fare' => self::pickupSurcharge($distanceKm, (float) ($profile->rate_per_km ?? 0))['fare'],
        ];
    }
}
