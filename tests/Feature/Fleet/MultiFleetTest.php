<?php

namespace Tests\Feature\Fleet;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 5, sección 7.3: el plan Multi-flota es el que le da sentido a que un
 * cliente pueda tener más de una flota. Cubre la lista, la creación gateada
 * por cupo, y que una flota no la pueda ver ni tocar otro cliente. El
 * catálogo de planes ya viene sembrado por la propia migración.
 */
class MultiFleetTest extends TestCase
{
    use RefreshDatabase;

    public function test_visiting_the_fleet_list_auto_creates_a_first_fleet(): void
    {
        $client = User::factory()->create();

        $response = $this->actingAs($client)->get(route('fleet.index'));

        $response->assertOk();
        $this->assertDatabaseHas('fleets', ['owner_user_id' => $client->id]);
        $response->assertInertia(fn ($page) => $page
            ->component('Fleet/List')
            ->has('fleets', 1)
            ->where('maxFleets', 1)
        );
    }

    public function test_client_cannot_create_a_second_fleet_on_the_free_plan(): void
    {
        $client = User::factory()->create();
        Fleet::factory()->for($client, 'owner')->create();

        $this->actingAs($client)
            ->post(route('fleet.store'), ['name' => 'Turno noche'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Fleet::where('owner_user_id', $client->id)->count());
    }

    public function test_client_with_multiflota_plan_can_create_additional_fleets(): void
    {
        $client = User::factory()->create();
        Fleet::factory()->for($client, 'owner')->create();

        $multiFlotaPlan = SubscriptionPlan::query()
            ->where('owner_type', 'client')
            ->where('code', 'multiflota')
            ->firstOrFail();

        Subscription::factory()->for($client)->create([
            'subscription_plan_id' => $multiFlotaPlan->id,
            'status' => 'active',
        ]);

        $this->actingAs($client)
            ->post(route('fleet.store'), ['name' => 'Turno noche'])
            ->assertRedirect();

        $this->assertSame(2, Fleet::where('owner_user_id', $client->id)->count());
        $this->assertDatabaseHas('fleets', ['owner_user_id' => $client->id, 'name' => 'Turno noche']);
    }

    public function test_a_client_cannot_view_another_clients_fleet(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::factory()->for($owner, 'owner')->create();

        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('fleet.show', $fleet))->assertForbidden();
    }

    public function test_driver_public_visibility_is_ignored_without_a_plan_that_includes_it(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_public' => false]);

        $this->actingAs($driver)->post(route('driver.profile.update'), [
            'license_number' => 'ABC-123',
            'vehicle_make' => 'Chevrolet',
            'vehicle_model' => 'Spark',
            'vehicle_color' => 'Blanco',
            'vehicle_type' => 'sedan',
            'vehicle_plate' => 'ABC-1234',
            'vehicle_year' => 2020,
            'passenger_capacity' => 4,
            'has_trunk' => true,
            'rate_per_km' => 0.5,
            'is_public' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('driver_profiles', ['user_id' => $driver->id, 'is_public' => false]);
    }

    public function test_driver_can_enable_public_visibility_with_the_plus_plan(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_public' => false]);

        $plusPlan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        Subscription::factory()->for($driver)->create([
            'subscription_plan_id' => $plusPlan->id,
            'status' => 'active',
        ]);

        $this->actingAs($driver)->post(route('driver.profile.update'), [
            'license_number' => 'ABC-123',
            'vehicle_make' => 'Chevrolet',
            'vehicle_model' => 'Spark',
            'vehicle_color' => 'Blanco',
            'vehicle_type' => 'sedan',
            'vehicle_plate' => 'ABC-1234',
            'vehicle_year' => 2020,
            'passenger_capacity' => 4,
            'has_trunk' => true,
            'rate_per_km' => 0.5,
            'is_public' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('driver_profiles', ['user_id' => $driver->id, 'is_public' => true]);
    }
}
