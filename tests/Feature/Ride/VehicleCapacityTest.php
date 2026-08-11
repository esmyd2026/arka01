<?php

namespace Tests\Feature\Ride;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\RideDispatchCandidates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: al pedir una carrera, cantidad de pasajeros
 * (por defecto 1) y si hace falta cajuela (por defecto no) — solo conductores
 * cuyo vehículo cumple pueden tomarla. Para eso, el perfil del conductor
 * ahora exige esos datos (y los del vehículo en general) para poder ponerse
 * disponible.
 */
class VehicleCapacityTest extends TestCase
{
    use RefreshDatabase;

    private function validVehiclePayload(): array
    {
        return [
            'license_number' => 'LIC-001',
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

    public function test_the_driver_profile_form_requires_every_vehicle_field(): void
    {
        $driver = User::factory()->create();

        $this->actingAs($driver)->post(route('driver.profile.update'), [
            'license_number' => 'LIC-001',
            'rate_per_km' => 0.5,
        ])->assertSessionHasErrors(['vehicle_make', 'vehicle_model', 'vehicle_color', 'vehicle_type', 'vehicle_plate', 'vehicle_year', 'passenger_capacity']);
    }

    public function test_completing_every_vehicle_field_succeeds(): void
    {
        $driver = User::factory()->create();

        $this->actingAs($driver)
            ->post(route('driver.profile.update'), $this->validVehiclePayload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('driver_profiles', [
            'user_id' => $driver->id,
            'vehicle_color' => 'Blanco',
            'passenger_capacity' => 4,
            'has_trunk' => true,
        ]);
    }

    /**
     * Bug/pedido del usuario: si un conductor no completó los datos de su
     * vehículo, no se puede poner disponible — antes no había ningún chequeo
     * de esto, un perfil a medias igual recibía carreras.
     */
    public function test_a_driver_with_an_incomplete_profile_cannot_become_available(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['passenger_capacity' => null, 'vehicle_color' => null, 'is_available' => false]);

        $this->actingAs($driver)->post(route('driver.location.update'), [
            'lat' => -0.18,
            'lng' => -78.46,
            'is_available' => true,
        ])->assertForbidden();

        $this->assertFalse($driver->driverProfile->fresh()->is_available);
    }

    public function test_a_driver_with_a_complete_profile_can_become_available(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => false]);

        $this->actingAs($driver)->post(route('driver.location.update'), [
            'lat' => -0.18,
            'lng' => -78.46,
            'is_available' => true,
        ])->assertOk();

        $this->assertTrue($driver->driverProfile->fresh()->is_available);
    }

    /**
     * Disconnecting (is_available: false) always has to work, incomplete
     * profile or not — the gate only blocks CONNECTING.
     */
    public function test_a_driver_with_an_incomplete_profile_can_still_disconnect(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['passenger_capacity' => null, 'is_available' => true]);

        $this->actingAs($driver)->post(route('driver.location.update'), [
            'lat' => -0.18,
            'lng' => -78.46,
            'is_available' => false,
        ])->assertOk();

        $this->assertFalse($driver->driverProfile->fresh()->is_available);
    }

    public function test_forpool_only_returns_drivers_with_enough_passenger_capacity(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $smallCar = User::factory()->create();
        DriverProfile::factory()->for($smallCar)->create(['is_available' => true, 'current_lat' => -0.18, 'current_lng' => -78.46, 'passenger_capacity' => 2]);
        FleetMember::factory()->for($fleet)->for($smallCar, 'driver')->create(['added_by' => $client->id]);

        $bigCar = User::factory()->create();
        DriverProfile::factory()->for($bigCar)->create(['is_available' => true, 'current_lat' => -0.181, 'current_lng' => -78.461, 'passenger_capacity' => 4]);
        FleetMember::factory()->for($fleet)->for($bigCar, 'driver')->create(['added_by' => $client->id]);

        $ids = RideDispatchCandidates::forPool($fleet, $client, 'fleet', -0.1807, -78.4678, passengerCount: 3);

        $this->assertSame([$bigCar->id], $ids);
    }

    public function test_forpool_only_returns_drivers_with_a_trunk_when_needed(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $noTrunk = User::factory()->create();
        DriverProfile::factory()->for($noTrunk)->create(['is_available' => true, 'current_lat' => -0.18, 'current_lng' => -78.46, 'has_trunk' => false]);
        FleetMember::factory()->for($fleet)->for($noTrunk, 'driver')->create(['added_by' => $client->id]);

        $withTrunk = User::factory()->create();
        DriverProfile::factory()->for($withTrunk)->create(['is_available' => true, 'current_lat' => -0.181, 'current_lng' => -78.461, 'has_trunk' => true]);
        FleetMember::factory()->for($fleet)->for($withTrunk, 'driver')->create(['added_by' => $client->id]);

        $ids = RideDispatchCandidates::forPool($fleet, $client, 'fleet', -0.1807, -78.4678, needsTrunk: true);

        $this->assertSame([$withTrunk->id], $ids);
    }

    /**
     * Pedido explícito del usuario ("solo buscar los conductores que tengan
     * esa característica"): se valida también en store(), no solo se filtra
     * la lista — para una solicitud DIRIGIDA a un conductor puntual.
     */
    public function test_store_rejects_a_directed_driver_without_enough_capacity(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['passenger_capacity' => 2, 'has_trunk' => false]);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'passenger_count' => 4,
        ])->assertSessionHasErrors('driver_user_id');

        $this->assertDatabaseMissing('ride_requests', ['client_user_id' => $client->id]);
    }

    public function test_store_defaults_passenger_count_to_one_when_not_sent(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertRedirect();

        $this->assertDatabaseHas('ride_requests', ['client_user_id' => $client->id, 'passenger_count' => 1, 'needs_trunk' => false]);
    }

    /**
     * Fix reportado por el usuario: la insignia de verificado se mostraba
     * sin importar el plan — ahora depende de las DOS cosas.
     */
    public function test_verified_badge_requires_both_admin_approval_and_the_plan_feature(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $approvedNoPlan = User::factory()->create();
        DriverProfile::factory()->for($approvedNoPlan)->create(['verification_status' => 'approved', 'is_public' => true]);
        FleetMember::factory()->for($fleet)->for($approvedNoPlan, 'driver')->create(['added_by' => $client->id]);

        $response = $this->actingAs($client)->get(route('ride-requests.create'));

        $response->assertInertia(fn ($page) => $page->where(
            'fleetDrivers',
            fn ($drivers) => collect($drivers)->firstWhere('user_id', $approvedNoPlan->id)['is_verified'] === false
        ));
    }

    /**
     * Cada cuenta es cliente O conductor, nunca las dos (sección 3.1) — el
     * admin no puede activarle un plan del lado que no le corresponde a una
     * cuenta puntual, ni siquiera manipulando el pedido a mano.
     */
    public function test_admin_cannot_activate_a_driver_plan_for_a_user_without_a_driver_profile(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();
        $driverPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.subscriptions.store'), [
            'user_id' => $client->id,
            'subscription_plan_id' => $driverPlan->id,
        ])->assertSessionHasErrors('subscription_plan_id');

        $this->assertDatabaseMissing('subscriptions', ['user_id' => $client->id]);
    }
}
