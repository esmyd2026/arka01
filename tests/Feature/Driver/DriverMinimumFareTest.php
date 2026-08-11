<?php

namespace Tests\Feature\Driver;

use App\Models\DriverProfile;
use App\Models\PricingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: la tarifa mínima que declara el conductor no
 * tenía efecto en nada (PriceCalculator solo usaba la de /admin/tarifas).
 * Ahora se respeta la del conductor SI es menor o igual a la de la
 * plataforma — si intenta poner una mayor, se le indica en su configuración
 * que la plataforma no lo permite.
 */
class DriverMinimumFareTest extends TestCase
{
    use RefreshDatabase;

    private function baseVehiclePayload(): array
    {
        return [
            'license_number' => 'ABC123',
            'vehicle_make' => 'Chevrolet',
            'vehicle_model' => 'Spark',
            'vehicle_color' => 'Blanco',
            'vehicle_type' => 'sedan',
            'vehicle_plate' => 'ABC-1234',
            'vehicle_year' => 2020,
            'passenger_capacity' => 4,
            'has_trunk' => true,
            'rate_per_km' => 0.5,
        ];
    }

    public function test_a_driver_can_set_a_minimum_fare_at_or_below_the_platform_one(): void
    {
        PricingSetting::current()->update(['minimum_fare' => 2.00]);

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($driver)->post(route('driver.profile.update'), $this->baseVehiclePayload() + [
            'minimum_fare' => 1.50,
        ])->assertSessionHasNoErrors();

        $this->assertSame('1.50', $driver->fresh()->driverProfile->minimum_fare);
    }

    public function test_a_driver_cannot_set_a_minimum_fare_above_the_platform_one(): void
    {
        PricingSetting::current()->update(['minimum_fare' => 2.00]);

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->actingAs($driver)->post(route('driver.profile.update'), $this->baseVehiclePayload() + [
            'minimum_fare' => 3.00,
        ]);

        $response->assertSessionHasErrors([
            'minimum_fare' => 'La plataforma no permite superar $2.00 como tarifa mínima de una carrera (tope general definido en /admin/tarifas). Puede dejarla en blanco o poner una menor.',
        ]);
    }

    public function test_the_edit_screen_exposes_the_platform_minimum_fare_as_a_cap(): void
    {
        PricingSetting::current()->update(['minimum_fare' => 2.50]);

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->actingAs($driver)->get(route('driver.profile.edit'));

        $response->assertInertia(fn ($page) => $page->where('platformMinimumFare', fn ($value) => (float) $value === 2.5));
    }
}
