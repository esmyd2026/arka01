<?php

namespace App\Services;

class Haversine
{
    /**
     * Radio medio de la Tierra en kilómetros. Es la constante de siempre para
     * la fórmula de Haversine (sección 9.3 del alcance: cálculo de cercanía
     * sin depender de columnas geoespaciales ni de un proveedor de mapas pago).
     */
    private const EARTH_RADIUS_KM = 6371.0;

    /**
     * Distancia en línea recta entre dos coordenadas, en kilómetros.
     * No es la distancia real de manejo (eso requeriría un servicio de rutas),
     * pero alcanza para "quién está más cerca" y para el precio sugerido.
     */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_KM * $c;
    }
}
