// Pedido explícito del usuario: los precios sugeridos de una carrera siempre
// redondeados hacia ARRIBA a los 10 centavos (5.35 → 5.40, 5.92 → 6.00,
// 5.05 → 5.10), nunca hacia abajo — mismo criterio y misma fórmula que
// App\Services\PriceCalculator::roundUpToDime() en el backend, para que lo
// que el cliente ve como estimado ya sea el mismo número que se termina
// guardando.
export function roundUpToDime(amount) {
    return Math.ceil(Math.round(amount * 10000) / 1000) / 10;
}
