<?php

namespace Tests\Feature\Admin;

use App\Models\DriverProfile;
use App\Models\PricingSetting;
use App\Models\User;
use App\Services\PriceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pantalla de mantenimiento del cálculo de precio sugerido (sección 5): el
 * recargo y el horario nocturno viven en pricing_settings, editables desde
 * /admin/tarifas — PriceCalculator los tiene que leer de ahí, no de una
 * constante.
 */
class AdminPricingMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_the_pricing_maintenance_screen(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.pricing.edit'))->assertForbidden();
    }

    public function test_an_admin_can_update_the_night_surcharge_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.pricing.update'), [
            'night_surcharge_percent' => 35,
            'night_starts_at' => 21,
            'night_ends_at' => 5,
            'minimum_fare' => 2.5,
            'average_ticket_price' => 3.5,
            'driver_stale_after_minutes' => 5,
        ])->assertRedirect();

        $this->assertDatabaseHas('pricing_settings', [
            'night_surcharge_percent' => 35,
            'night_starts_at' => 21,
            'night_ends_at' => 5,
            'minimum_fare' => 2.5,
            'average_ticket_price' => 3.5,
            'driver_stale_after_minutes' => 5,
        ]);
    }

    /**
     * Pedido explícito del usuario: "ese tiempo de inactividad, ¿lo puedo
     * subir desde el panel de administrador?" — antes era la constante
     * DriverProfile::STALE_AFTER_MINUTES, fija en el código.
     */
    public function test_an_admin_can_change_how_long_a_driver_can_go_without_a_location_ping(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.pricing.update'), [
            'night_surcharge_percent' => 20,
            'night_starts_at' => 20,
            'night_ends_at' => 6,
            'minimum_fare' => 2,
            'average_ticket_price' => 3,
            'driver_stale_after_minutes' => 10,
        ])->assertRedirect();

        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create(['location_updated_at' => now()->subMinutes(7)]);

        // Con el umbral por defecto (2 min) esto sería stale; con el nuevo
        // umbral de 10 min, todavía cuenta como reciente.
        $this->assertFalse($profile->isStale());
    }

    /**
     * Tarifa base mínima (pedido explícito del usuario): una carrera corta
     * no puede salir tan barata que no le convenga al conductor por los km.
     */
    public function test_price_calculator_applies_the_minimum_fare_when_the_distance_based_price_is_lower(): void
    {
        PricingSetting::current()->update(['minimum_fare' => 2.0]);

        $result = PriceCalculator::suggestedPrice(2.0, 0.5, Carbon::parse('2026-01-15 12:00:00'));

        $this->assertEquals(2.0, $result['base']);
        $this->assertEquals(2.0, $result['total']);
    }

    public function test_price_calculator_uses_the_surcharge_stored_in_the_database(): void
    {
        PricingSetting::current()->update(['night_surcharge_percent' => 50]);

        $result = PriceCalculator::suggestedPrice(10.0, 1.0, Carbon::parse('2026-01-15 22:00:00'));

        $this->assertTrue($result['is_night']);
        $this->assertEquals(10.0, $result['base']);
        $this->assertEquals(5.0, $result['night_surcharge']);
        $this->assertEquals(15.0, $result['total']);
    }
}
