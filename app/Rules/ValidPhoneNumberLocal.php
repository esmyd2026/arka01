<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida el número local de un teléfono (sin el código de país, que viaja
 * aparte en 'country_code' — ver RegisteredUserController::COUNTRY_CODES).
 * Pedido explícito del usuario: para Ecuador (+593) hay que exigir el
 * formato real de un celular, no cualquier cadena de 7 a 10 dígitos —
 * exactamente 9 dígitos que empiecen en 9 (el 0 inicial que se usa
 * localmente no va acá, ya lo reemplaza el código de país), y de paso
 * descartar números "de relleno" obvios como 999999999 o 000000000, que
 * pasaban el formato viejo sin ser un celular real. Para el resto de
 * países solo se exige que sean puros dígitos con una longitud razonable
 * — no tenemos el formato exacto de cada uno.
 */
class ValidPhoneNumberLocal implements DataAwareRule, ValidationRule
{
    protected array $data = [];

    /**
     * Pedido explícito del usuario: si escribe el 0 inicial de siempre
     * (ej. "0988492339"), quitárselo solo en vez de rechazarlo — el 0 local
     * no va acá, ya lo reemplaza el código de país (+593). Se llama ANTES
     * de validar (`$request->merge(...)`) en cada controller que recibe
     * country_code/phone_local, para que la regla de abajo ya vea el valor
     * corregido.
     */
    public static function normalize(?string $countryCode, ?string $phoneLocal): ?string
    {
        if ($countryCode !== '+593' || $phoneLocal === null) {
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

        if ($countryCode !== '+593') {
            if (! preg_match('/^[0-9]{7,10}$/', $value)) {
                $fail('El número tiene que tener entre 7 y 10 dígitos, sin espacios ni guiones.');
            }

            return;
        }

        if (! preg_match('/^9\d{8}$/', $value)) {
            $fail('Un celular ecuatoriano tiene 9 dígitos y empieza en 9, sin el 0 inicial. Ej: 991234567.');

            return;
        }

        if (preg_match('/^(\d)\1{8}$/', $value)) {
            $fail('Ese número no parece un celular real — revíselo.');
        }
    }
}
