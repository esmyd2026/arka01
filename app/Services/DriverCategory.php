<?php

namespace App\Services;

/**
 * Categoría del conductor según su historial de calificaciones (sección 3.6
 * y consideración agregada al alcance: "diamante, oro, plata, cobre"), para
 * priorizar visualmente al directorio público cuando la flota personal no
 * alcanza. No es un plan de suscripción — es reputación ganada, no comprada.
 */
class DriverCategory
{
    public static function forRating(float $averageRating, int $reviewCount): string
    {
        if ($reviewCount === 0) {
            return 'cobre';
        }

        return match (true) {
            $averageRating >= 4.8 && $reviewCount >= 10 => 'diamante',
            $averageRating >= 4.5 && $reviewCount >= 5 => 'oro',
            $averageRating >= 4.0 => 'plata',
            default => 'cobre',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'diamante' => 'Diamante',
            'oro' => 'Oro',
            'plata' => 'Plata',
            'cobre' => 'Cobre',
        ];
    }
}
