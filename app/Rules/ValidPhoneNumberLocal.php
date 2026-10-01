<?php

namespace App\Rules;

use App\Models\Country;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida el número local de un teléfono (sin el código de país, que viaja
 * aparte en 'country_code' — ver App\Models\Country, catálogo administrable
 * desde /admin/paises). Antes esto tenía el formato de Ecuador (+593)
 * hardcodeado y cualquier otro país caía a un genérico de 7-10 dígitos;
 * ahora el formato exacto (si existe) y el mensaje de ayuda salen de la fila
 * del país en la base — así un país nuevo se da de alta sin tocar código.
 */
class ValidPhoneNumberLocal implements DataAwareRule, ValidationRule
{
    protected array $data = [];

    /**
     * Pedido explícito del usuario: si escribe el 0 inicial de siempre
     * (ej. "0988492339"), quitárselo solo en vez de rechazarlo — el 0 local
     * no va acá, ya lo reemplaza el código de país. Antes esto solo pasaba
     * para Ecuador (+593); ahora depende de `strips_leading_zero` del país,
     * porque no todos anteponen 0 al número local. Se llama ANTES de
     * validar (`$request->merge(...)`) en cada controller que recibe
     * country_code/phone_local, para que la regla de abajo ya vea el valor
     * corregido.
     */
    public static function normalize(?string $countryCode, ?string $phoneLocal): ?string
    {
        if ($phoneLocal === null) {
            return null;
        }

        $country = $countryCode ? Country::active()->firstWhere('phone_prefix', $countryCode) : null;

        if (! $country || ! $country->strips_leading_zero) {
            return $phoneLocal;
        }

        return preg_match('/^0\d{9}$/', $phoneLocal) ? substr($phoneLocal, 1) : $phoneLocal;
    }

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = (string) $value;

        if ($value === '') {
            return;
        }

        $countryCode = $this->data['country_code'] ?? null;
        $country = $countryCode ? Country::active()->firstWhere('phone_prefix', $countryCode) : null;

        // País sin regex propio (o country_code que no matchea ninguno
        // activo, ej. dato viejo) — mismo genérico que se usaba para TODO
        // lo que no fuera Ecuador antes de este cambio.
        $pattern = $country?->phone_local_regex ? '/'.$country->phone_local_regex.'/' : '/^[0-9]{7,10}$/';

        if (! preg_match($pattern, $value)) {
            $hint = $country?->phone_format_hint;
            $fail($hint
                ? "Un celular {$country->name} debe tener {$hint}, sin espacios ni guiones."
                : 'El número tiene que tener entre 7 y 10 dígitos, sin espacios ni guiones.');

            return;
        }

        // Descarta "rellenos" obvios (999999999, 000000000...) que pasan
        // cualquier formato de dígitos repetidos sin ser un celular real —
        // aplica para cualquier país, no solo Ecuador como antes.
        if (preg_match('/^(\d)\1+$/', $value)) {
            $fail('Ese número no parece un celular real — revíselo.');
        }
    }
}
