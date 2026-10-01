<?php

namespace App\Support;

use App\Models\Country;

/**
 * Formato de moneda en PHP (pedido explícito del usuario: "arka01 debe
 * funcionar en cualquier país") — espejo de resources/js/Utils/currency.js,
 * para notificaciones push y plantillas de WhatsApp, que no pasan por Vue.
 * Antes el símbolo "$" y 2 decimales estaban escritos a mano en cada mensaje.
 */
class Currency
{
    public static function format(float $amount, ?Country $country = null): string
    {
        $country ??= Country::default();

        return $country->currency_symbol.number_format($amount, $country->decimal_digits, '.', ',');
    }
}
