<?php

namespace Tests\Unit;

use App\Models\PricingSetting;
use App\Services\PriceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Redondeo hacia arriba a los 10 centavos (pedido explícito del usuario:
 * "redondear los valores siempre a decenas... hacia arriba. ejemplo 5.35 ==
 * 5.40, 5.92 == 6.00, 5.05 == 5.10") — nunca hacia abajo, para no
 * perjudicar al conductor.
 */
class PriceCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_round_up_to_dime_matches_the_examples_given_by_the_user(): void
    {
        $this->assertSame(5.4, PriceCalculator::roundUpToDime(5.35));
        $this->assertSame(6.0, PriceCalculator::roundUpToDime(5.92));
        $this->assertSame(5.1, PriceCalculator::roundUpToDime(5.05));
    }

    public function test_round_up_to_dime_leaves_an_exact_dime_unchanged(): void
    {
        $this->assertSame(5.4, PriceCalculator::roundUpToDime(5.40));
        $this->assertSame(6.0, PriceCalculator::roundUpToDime(6.00));
    }

    // Guarda contra el residuo típico de punto flotante (5.40 puede llegar
    // acá como 5.399999999999999) que empujaría de más al siguiente escalón
    // si no se redondea a 4 decimales antes del ceil().
    public function test_round_up_to_dime_is_not_fooled_by_floating_point_residue(): void
    {
        $this->assertSame(5.4, PriceCalculator::roundUpToDime(5.4 - 0.0000001));
    }

    public function test_the_suggested_price_total_is_always_rounded_up_to_a_dime(): void
    {
        $settings = PricingSetting::current();
        $settings->update(['minimum_fare' => 1.0, 'night_surcharge_percent' => 0, 'peak_surcharge_percent' => 0]);

        // 3.17 km (con el margen fijo de 0.8 km ya incluido) × 1.6538/km da
        // un total con residuo de centavos a propósito, para probar el
        // redondeo de punta a punta, no solo la función aislada.
        $result = PriceCalculator::suggestedPrice(distanceKm: 3.17, ratePerKm: 1.6538);

        $this->assertEqualsWithDelta(round($result['total'] * 10) / 10, $result['total'], 0.0001);
        $this->assertGreaterThanOrEqual($result['base'] + $result['night_surcharge'] + $result['peak_surcharge'], $result['total']);
    }

    /**
     * Cargo por trayecto de recogida (pedido explícito del usuario): bajo el
     * umbral configurado, el colchón fijo de 0.8 km ya existente sigue
     * cubriendo el acercamiento — este método no agrega nada.
     */
    public function test_pickup_surcharge_is_zero_below_the_configured_threshold(): void
    {
        PricingSetting::current()->update(['pickup_surcharge_threshold_km' => 3, 'pickup_surcharge_percent' => 55]);

        $result = PriceCalculator::pickupSurcharge(pickupDistanceKm: 2.5, ratePerKm: 0.30);

        $this->assertFalse($result['exceeds_threshold']);
        $this->assertSame(0.0, $result['fare']);
    }

    /**
     * Ejemplo exacto dado por el usuario: conductor a $0.30/km, 8 km hasta
     * el cliente, 55% de recargo → 8 × 0.30 × 0.55 = $1.32.
     */
    public function test_pickup_surcharge_matches_the_example_given_by_the_user(): void
    {
        PricingSetting::current()->update(['pickup_surcharge_threshold_km' => 3, 'pickup_surcharge_percent' => 55]);

        $result = PriceCalculator::pickupSurcharge(pickupDistanceKm: 8, ratePerKm: 0.30);

        $this->assertTrue($result['exceeds_threshold']);
        $this->assertEqualsWithDelta(1.32, $result['fare'], 0.001);
    }
}
