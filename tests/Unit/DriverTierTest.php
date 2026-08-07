<?php

namespace Tests\Unit;

use App\Models\DriverTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Medallas por puntos (pedido explícito del usuario): la medalla vigente es
 * la de mayor min_points que no supere los puntos del conductor — usa la
 * semilla sembrada en la migración (Cobre 0, Plata 150, Oro 500, Diamante 1000).
 */
class DriverTierTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_the_right_tier_at_each_boundary(): void
    {
        $this->assertSame('Cobre', DriverTier::forPoints(0)->name);
        $this->assertSame('Cobre', DriverTier::forPoints(149)->name);
        $this->assertSame('Plata', DriverTier::forPoints(150)->name);
        $this->assertSame('Plata', DriverTier::forPoints(499)->name);
        $this->assertSame('Oro', DriverTier::forPoints(500)->name);
        $this->assertSame('Oro', DriverTier::forPoints(999)->name);
        $this->assertSame('Diamante', DriverTier::forPoints(1000)->name);
        $this->assertSame('Diamante', DriverTier::forPoints(50000)->name);
    }

    public function test_only_gold_and_up_are_eligible_for_the_public_directory_by_default(): void
    {
        $this->assertFalse(DriverTier::forPoints(0)->is_public_eligible);
        $this->assertFalse(DriverTier::forPoints(149)->is_public_eligible);
        $this->assertTrue(DriverTier::forPoints(500)->is_public_eligible);
        $this->assertTrue(DriverTier::forPoints(1000)->is_public_eligible);
    }
}
