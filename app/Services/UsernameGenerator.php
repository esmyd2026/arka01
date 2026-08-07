<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * "Usuario" público autogenerado (consideración agregada al alcance): primera
 * letra del primer nombre + primer apellido (ej. "Juan Pérez" → "jperez").
 * Sirve tanto para buscar/identificar a alguien como para iniciar sesión, en
 * vez de exigir que cada quien invente y recuerde uno propio.
 *
 * Convención asumida (Ecuador, sección 0 del alcance) al partir el nombre
 * libre en sus partes: con 4+ palabras, "Nombre1 Nombre2 Apellido1
 * Apellido2"; con 3, "Nombre Apellido1 Apellido2" (más común que dos
 * nombres y un apellido en un campo de nombre completo sin estructurar).
 */
class UsernameGenerator
{
    public static function generate(string $fullName): string
    {
        $words = self::wordsFrom($fullName);

        [$primerNombre, $segundoNombre, $primerApellido, $segundoApellido] = self::splitParts($words);

        $base = Str::substr($primerNombre, 0, 1).$primerApellido;
        $base = $base !== '' ? $base : 'usuario';

        foreach (array_filter([$base, $segundoApellido ? $base.Str::substr($segundoApellido, 0, 1) : null, $segundoNombre ? $base.Str::substr($segundoNombre, 0, 1) : null]) as $candidate) {
            if (! User::where('username', $candidate)->exists()) {
                return $candidate;
            }
        }

        // Ninguna variante con iniciales alcanzó: se suma un número hasta
        // encontrar una libre (caso de mucha gente con el mismo nombre/apellido).
        $suffix = 2;
        while (User::where('username', $base.$suffix)->exists()) {
            $suffix++;
        }

        return $base.$suffix;
    }

    /**
     * @return array<int, string>
     */
    private static function wordsFrom(string $fullName): array
    {
        $ascii = Str::ascii($fullName);

        return collect(preg_split('/\s+/', trim($ascii)) ?: [])
            ->map(fn (string $word) => Str::lower(preg_replace('/[^A-Za-z]/', '', $word)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $words
     * @return array{0: string, 1: ?string, 2: string, 3: ?string} [primerNombre, segundoNombre, primerApellido, segundoApellido]
     */
    private static function splitParts(array $words): array
    {
        return match (count($words)) {
            0 => ['usuario', null, '', null],
            1 => [$words[0], null, '', null],
            2 => [$words[0], null, $words[1], null],
            3 => [$words[0], null, $words[1], $words[2]],
            default => [$words[0], $words[1], $words[2], $words[3]],
        };
    }
}
