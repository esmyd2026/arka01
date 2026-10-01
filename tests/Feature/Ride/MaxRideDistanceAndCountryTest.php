<?php

namespace Tests\Feature\Ride;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Driver\DriverDirectoryFinder;
use App\Services\RideDispatchCandidates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: "puede limitar los recorridos osea que las
 * personas no puedan ver conductores a mas de 50 km mas o menos para evitar
 * solicitudes asi tan extensas. y menos de otros paises" — tope global de
 * distancia (SiteSetting::max_ride_request_distance_km, editable desde
 * /admin/sistema) y bloqueo duro entre países, sin importar la distancia.
 */
class MaxRideDistanceAndCountryTest extends TestCase
{
    use RefreshDatabase;

    private function driverAt(Fleet $fleet, User $client, float $lat, float $lng, array $overrides = []): User
    {
        $driver = User::factory()->create(array_intersect_key($overrides, ['phone' => true]));
        DriverProfile::factory()->for($driver)->create(array_merge([
            'rate_per_km' => 0.5,
            'is_available' => true,
            'current_lat' => $lat,
            'current_lng' => $lng,
        ], array_diff_key($overrides, ['phone' => true])));
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        return $driver;
    }

    /**
     * Origen de referencia en todos los casos: Quito (-0.1807, -78.4678).
     */
    public function test_a_driver_beyond_the_platform_wide_cap_is_excluded_even_without_a_driver_set_limit(): void
    {
        $client = User::factory()->create(['phone' => '+593900000000']);
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $near = $this->driverAt($fleet, $client, -0.1810, -78.4680, ['phone' => '+593900000001']);
        // ~58 km de diferencia de latitud respecto al origen — supera el
        // tope de fábrica (50 km) sin que el conductor haya configurado
        // ningún radio propio.
        $far = $this->driverAt($fleet, $client, -0.70, -78.4678, ['phone' => '+593900000002']);

        $ids = RideDispatchCandidates::forPool($fleet, $client, 'fleet', -0.1807, -78.4678);

        $this->assertSame([$near->id], $ids);
        $this->assertNotContains($far->id, $ids);
    }

    public function test_raising_the_platform_cap_lets_a_previously_out_of_range_driver_back_in(): void
    {
        SiteSetting::current()->update(['max_ride_request_distance_km' => 100]);

        $client = User::factory()->create(['phone' => '+593900000000']);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $far = $this->driverAt($fleet, $client, -0.70, -78.4678, ['phone' => '+593900000002']);

        $ids = RideDispatchCandidates::forPool($fleet, $client, 'fleet', -0.1807, -78.4678);

        $this->assertContains($far->id, $ids);
    }

    /**
     * El conductor puede seguir angostando SU propio radio, pero nunca
     * ensancharlo por encima del tope de la plataforma.
     */
    public function test_a_drivers_own_narrower_limit_still_applies_under_the_platform_cap(): void
    {
        $client = User::factory()->create(['phone' => '+593900000000']);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        // ~5 km de diferencia de latitud (0.045° ≈ 5 km).
        $driver = $this->driverAt($fleet, $client, -0.2257, -78.4678, [
            'phone' => '+593900000001',
            'max_request_distance_km' => 1,
        ]);

        // A ~5 km, bien dentro del tope de la plataforma (50 km) pero fuera
        // del radio propio de 1 km que declaró este conductor.
        $ids = RideDispatchCandidates::forPool($fleet, $client, 'fleet', -0.1807, -78.4678);

        $this->assertNotContains($driver->id, $ids);
    }

    public function test_a_driver_from_another_country_is_excluded_from_the_fleet_pool_regardless_of_distance(): void
    {
        $client = User::factory()->create(['phone' => '+593900000000']);
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        // Mismísimas coordenadas que el origen — ningún motivo de distancia
        // para excluirlo, solo el país.
        $chileanDriver = $this->driverAt($fleet, $client, -0.1807, -78.4678, ['phone' => '+56900000001']);
        $ecuadorianDriver = $this->driverAt($fleet, $client, -0.1807, -78.4678, ['phone' => '+593900000003']);

        $ids = RideDispatchCandidates::forPool($fleet, $client, 'fleet', -0.1807, -78.4678);

        $this->assertNotContains($chileanDriver->id, $ids);
        $this->assertContains($ecuadorianDriver->id, $ids);
    }

    public function test_the_public_directory_never_lists_a_driver_from_another_country(): void
    {
        $client = User::factory()->create(['phone' => '+593900000000']);

        $chileanDriver = User::factory()->create(['phone' => '+56900000001']);
        DriverProfile::factory()->for($chileanDriver)->create([
            'is_public' => true,
            'verification_status' => 'approved',
            'total_points' => 10000,
        ]);

        $result = app(DriverDirectoryFinder::class)->browse($client, null, null, 1);

        $this->assertFalse(
            collect($result['drivers']->items())->contains('user_id', $chileanDriver->id)
        );
    }

    public function test_an_admin_can_change_the_platform_wide_distance_cap(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->patch(route('admin.system.max-distance.update'), [
            'max_ride_request_distance_km' => 75,
        ])->assertRedirect();

        $this->assertEquals(75, SiteSetting::current()->max_ride_request_distance_km);
    }
}
