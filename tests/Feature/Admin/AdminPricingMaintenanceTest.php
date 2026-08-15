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
            'peak_surcharge_percent' => 20,
            'peak_morning_starts_at' => 7,
            'peak_morning_ends_at' => 9,
            'peak_evening_starts_at' => 17,
            'peak_evening_ends_at' => 19,
            'minimum_fare' => 2.5,
            'average_ticket_price' => 3.5,
            'driver_stale_after_minutes' => 5,
        ])->assertRedirect();

        $this->assertDatabaseHas('pricing_settings', [
            'night_surcharge_percent' => 35,
            'night_starts_at' => 21,
            'night_ends_at' => 5,
            'peak_surcharge_percent' => 20,
            'peak_morning_starts_at' => 7,
            'peak_morning_ends_at' => 9,
            'peak_evening_starts_at' => 17,
            'peak_evening_ends_at' => 19,
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
            'peak_surcharge_percent' => 15,
            'peak_morning_starts_at' => 7,
            'peak_morning_ends_at' => 9,
            'peak_evening_starts_at' => 17,
            'peak_evening_ends_at' => 19,
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
        // Pedido explícito del usuario ("súbele siempre... a los km 800
        // metros más"): 10.0 km pedidos + 0.8 km de margen = 10.8 km reales
        // de base — ver PriceCalculator::DISTANCE_PADDING_KM.
        $this->assertEquals(10.8, $result['base']);
        $this->assertEquals(5.4, $result['night_surcharge']);
        $this->assertEquals(16.2, $result['total']);
    }

    /**
     * Pedido explícito del usuario: "subir un poco las tarifas en las horas
     * pico" — dos franjas por día, mañana y tarde.
     */
    public function test_price_calculator_applies_the_peak_surcharge_in_the_morning_window(): void
    {
        PricingSetting::current()->update(['peak_surcharge_percent' => 20]);

        $result = PriceCalculator::suggestedPrice(10.0, 1.0, Carbon::parse('2026-01-15 08:00:00'));

        $this->assertTrue($result['is_peak']);
        $this->assertFalse($result['is_night']);
        // Ídem margen de 800 m de arriba.
        $this->assertEquals(10.8, $result['base']);
        $this->assertEquals(2.16, $result['peak_surcharge']);
        $this->assertEquals(0.0, $result['night_surcharge']);
        $this->assertEquals(12.96, $result['total']);
    }

    public function test_price_calculator_applies_the_peak_surcharge_in_the_evening_window(): void
    {
        PricingSetting::current()->update(['peak_surcharge_percent' => 20]);

        $result = PriceCalculator::suggestedPrice(10.0, 1.0, Carbon::parse('2026-01-15 18:00:00'));

        $this->assertTrue($result['is_peak']);
        $this->assertEquals(12.96, $result['total']);
    }

    public function test_price_calculator_does_not_apply_the_peak_surcharge_outside_its_windows(): void
    {
        PricingSetting::current()->update(['peak_surcharge_percent' => 20]);

        $result = PriceCalculator::suggestedPrice(10.0, 1.0, Carbon::parse('2026-01-15 12:00:00'));

        $this->assertFalse($result['is_peak']);
        $this->assertEquals(0.0, $result['peak_surcharge']);
        // El margen de 800 m se suma siempre, aunque no haya recargo.
        $this->assertEquals(10.8, $result['total']);
    }

    /**
     * Nocturno y pico nunca se suman — si por una configuración rara
     * llegaran a solaparse, gana el nocturno.
     */
    public function test_the_night_surcharge_takes_priority_over_the_peak_surcharge_when_they_overlap(): void
    {
        PricingSetting::current()->update([
            'night_surcharge_percent' => 50,
            'peak_surcharge_percent' => 20,
            'night_starts_at' => 6,
            'night_ends_at' => 10,
            'peak_morning_starts_at' => 7,
            'peak_morning_ends_at' => 9,
        ]);

        $result = PriceCalculator::suggestedPrice(10.0, 1.0, Carbon::parse('2026-01-15 08:00:00'));

        $this->assertTrue($result['is_night']);
        $this->assertFalse($result['is_peak']);
        $this->assertEquals(5.4, $result['night_surcharge']);
        $this->assertEquals(0.0, $result['peak_surcharge']);
        $this->assertEquals(16.2, $result['total']);
    }

    /**
     * Pedido explícito del usuario: "súbele siempre a cada carrera... a los
     * km 800 metros más" — un margen fijo sobre TODA carrera, sin depender
     * de hora pico ni nocturna, para cubrir desvíos de ruta reales e
     * imprecisión del pin de origen/destino.
     */
    public function test_price_calculator_always_pads_the_distance_by_800_meters(): void
    {
        $result = PriceCalculator::suggestedPrice(4.0, 1.0, Carbon::parse('2026-01-15 12:00:00'));

        $this->assertEquals(4.8, $result['base']);
        $this->assertEquals(4.8, $result['total']);
    }
}
