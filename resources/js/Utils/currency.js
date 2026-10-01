// Pedido explícito del usuario: los precios sugeridos de una carrera siempre
// redondeados hacia ARRIBA a los 10 centavos (5.35 → 5.40, 5.92 → 6.00,
// 5.05 → 5.10), nunca hacia abajo — mismo criterio y misma fórmula que
// App\Services\PriceCalculator::roundUpToDime() en el backend, para que lo
// que el cliente ve como estimado ya sea el mismo número que se termina
// guardando.
export function roundUpToDime(amount) {
    return Math.ceil(Math.round(amount * 10000) / 1000) / 10;
}

// Formato de moneda (pedido explícito del usuario: "arka01 debe funcionar en
// cualquier país") — antes cada pantalla escribía "$"+toFixed(2) a mano,
// asumiendo siempre dólares con 2 decimales. `country` es el objeto que
// comparte HandleInertiaRequests::share() en `auth.country` (o el `country`
// de un recurso puntual que no es el del usuario logueado, ej. una carrera
// de otro país que un admin está revisando — ver App\Models\Country::publicPayload()),
// SIEMPRE explícito, nunca implícito/global, para no adivinar mal en
// pantallas admin que muestran datos de un país que no es el propio.
export function formatCurrency(amount, country) {
    return new Intl.NumberFormat('es-'.concat(country?.iso_code ?? 'EC'), {
        style: 'currency',
        currency: country?.currency_code ?? 'USD',
        minimumFractionDigits: country?.decimal_digits ?? 2,
        maximumFractionDigits: country?.decimal_digits ?? 2,
    }).format(Number(amount ?? 0));
}
