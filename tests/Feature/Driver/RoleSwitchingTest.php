<?php

namespace Tests\Feature\Driver;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: "necesito manejar la opción de que de
 * cliente se pueda pasar a cuenta como conductor... pero cuando una persona
 * tenga una cuenta como cliente o conductor le indique en un botón en su
 * perfil pasarme a conductor o de conductor pasarme a ser cliente" — ver
 * App\Http\Controllers\DriverProfileController::deactivate()/reactivate() y
 * App\Models\DriverProfile::isDeactivated().
 */
class RoleSwitchingTest extends TestCase
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
            'has_insurance' => true,
        ];
    }

    public function test_a_driver_can_switch_to_client_without_losing_their_data(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'total_points' => 750,
            'verification_status' => 'approved',
            'vehicle_plate' => 'XYZ-999',
        ]);

        $this->assertTrue($driver->isDriver());

        $response = $this->actingAs($driver)->post(route('driver.profile.deactivate'));

        $response->assertRedirect(route('dashboard'));
        $this->assertFalse($driver->fresh()->isDriver());
        $this->assertTrue($driver->fresh()->isClient());

        // Nada se borró — sigue todo guardado, listo para reactivar.
        $this->assertDatabaseHas('driver_profiles', [
            'user_id' => $driver->id,
            'total_points' => 750,
            'verification_status' => 'approved',
            'vehicle_plate' => 'XYZ-999',
            'is_available' => false,
        ]);
    }

    public function test_deactivating_is_blocked_while_a_ride_is_in_progress(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        Ride::factory()->create(['driver_user_id' => $driver->id, 'status' => 'in_progress']);

        $this->actingAs($driver)->post(route('driver.profile.deactivate'))->assertSessionHasErrors('driver');

        $this->assertTrue($driver->fresh()->isDriver());
    }

    public function test_a_regular_client_cannot_deactivate_a_driver_profile_that_does_not_exist(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->post(route('driver.profile.deactivate'))->assertNotFound();
    }

    public function test_a_deactivated_driver_can_reactivate_with_a_single_click(): void
    {
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create([
            'total_points' => 500,
            'deactivated_at' => now()->subDay(),
        ]);

        $this->assertFalse($driver->isDriver());

        $response = $this->actingAs($driver)->post(route('driver.profile.reactivate'));

        $response->assertRedirect(route('driver.profile.edit'));
        $this->assertTrue($driver->fresh()->isDriver());
        $this->assertSame(500, $profile->fresh()->total_points);
        $this->assertNull($profile->fresh()->deactivated_at);
    }

    public function test_saving_the_driver_form_while_deactivated_reactivates_it(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['deactivated_at' => now()->subDay()]);

        $this->actingAs($driver)
            ->post(route('driver.profile.update'), $this->validVehiclePayload())
            ->assertSessionHasNoErrors();

        $this->assertTrue($driver->fresh()->isDriver());
    }

    public function test_a_client_with_a_fleet_can_become_a_driver_and_the_fleet_stays_intact(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $otherDriver = User::factory()->create();
        DriverProfile::factory()->for($otherDriver)->create();
        FleetMember::factory()->for($fleet)->for($otherDriver, 'driver')->create(['added_by' => $client->id]);

        $this->actingAs($client)
            ->post(route('driver.profile.update'), array_merge($this->validVehiclePayload(), [
                'profile_photo' => UploadedFile::fake()->image('perfil.jpg'),
                'identity_document' => UploadedFile::fake()->image('cedula.jpg'),
                'license_photo' => UploadedFile::fake()->image('licencia.jpg'),
                'vehicle_registration' => UploadedFile::fake()->create('matricula.pdf', 100, 'application/pdf'),
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($client->fresh()->isDriver());
        $this->assertFalse($client->fresh()->isClient());
        // La flota y su miembro no se tocaron.
        $this->assertDatabaseHas('fleets', ['id' => $fleet->id, 'owner_user_id' => $client->id]);
        $this->assertDatabaseHas('fleet_members', ['fleet_id' => $fleet->id, 'driver_user_id' => $otherDriver->id, 'left_at' => null]);
    }

    public function test_becoming_a_driver_is_blocked_while_the_client_has_a_ride_in_progress(): void
    {
        $client = User::factory()->create();
        Ride::factory()->create(['client_user_id' => $client->id, 'status' => 'in_progress']);

        $this->actingAs($client)
            ->post(route('driver.profile.update'), $this->validVehiclePayload())
            ->assertSessionHasErrors('vehicle_make');

        $this->assertDatabaseMissing('driver_profiles', ['user_id' => $client->id]);
    }

    public function test_a_deactivated_driver_does_not_appear_in_the_public_directory(): void
    {
        $viewer = User::factory()->create();
        $driver = User::factory()->create(['name' => 'Conductor Pausado']);
        DriverProfile::factory()->for($driver)->create([
            'is_public' => true,
            'total_points' => 1000,
            'deactivated_at' => now(),
        ]);

        $response = $this->actingAs($viewer)->get(route('directory.index'));

        $response->assertInertia(fn ($page) => $page->has('drivers.data', 0));
    }
}
