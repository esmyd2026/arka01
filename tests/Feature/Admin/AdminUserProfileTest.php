<?php

namespace Tests\Feature\Admin;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Review;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: "en el Administrador debe existir una opción
 * para consultar el perfil completo tanto del conductor como del cliente,
 * mostrando toda la información relevante sin necesidad de navegar por
 * diferentes pantallas".
 */
class AdminUserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_view_a_full_profile(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $other = User::factory()->create();

        $this->actingAs($user)->get(route('admin.users.show', $other))->assertForbidden();
    }

    public function test_an_admin_can_view_a_drivers_full_profile(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['verification_status' => 'approved']);

        $response = $this->actingAs($admin)->get(route('admin.users.show', $driver));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/UserProfile')
            ->where('profileUser.id', $driver->id)
            ->where('driverPlan.plan_code', 'gratis')
            ->where('clientPlan', null)
        );
    }

    public function test_an_admin_can_view_a_clients_full_profile(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();
        Fleet::factory()->for($client, 'owner')->create();

        $response = $this->actingAs($admin)->get(route('admin.users.show', $client));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/UserProfile')
            ->has('fleetsOwned', 1)
            ->where('driverPlan', null)
        );
    }

    // Eliminar cuenta (pedido explícito del usuario): borra archivos y, por
    // el cascade que ya tienen las FKs a users.id, todo lo demás — historial
    // de carreras, flotas/membresías, reseñas, suscripciones, etc.

    public function test_a_regular_user_cannot_delete_an_account(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $client = User::factory()->create();

        $this->actingAs($user)->delete(route('admin.users.destroy', $client), [
            'confirm_email' => $client->email,
        ])->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $client->id]);
    }

    public function test_an_admin_cannot_delete_another_admin_account(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $otherAdmin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->delete(route('admin.users.destroy', $otherAdmin), [
            'confirm_email' => $otherAdmin->email,
        ])->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $otherAdmin->id]);
    }

    public function test_deleting_without_the_matching_confirmation_email_fails(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();

        $this->actingAs($admin)->delete(route('admin.users.destroy', $client), [
            'confirm_email' => 'correo-que-no-coincide@example.com',
        ])->assertSessionHasErrors('confirm_email');

        $this->assertDatabaseHas('users', ['id' => $client->id]);
    }

    public function test_an_admin_can_delete_a_client_account_and_it_cascades(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $ride = Ride::factory()->create(['fleet_id' => $fleet->id, 'client_user_id' => $client->id, 'driver_user_id' => $driver->id]);
        Review::factory()->create(['ride_id' => $ride->id, 'reviewer_user_id' => $driver->id, 'reviewee_user_id' => $client->id]);

        $response = $this->actingAs($admin)->delete(route('admin.users.destroy', $client), [
            'confirm_email' => $client->email,
        ]);

        $response->assertRedirect(route('admin.clients.index'));
        $this->assertDatabaseMissing('users', ['id' => $client->id]);
        $this->assertDatabaseMissing('fleets', ['id' => $fleet->id]);
        $this->assertDatabaseMissing('rides', ['id' => $ride->id]);
        $this->assertDatabaseMissing('reviews', ['reviewee_user_id' => $client->id]);
        // El conductor y su reseña hecha (del otro lado) no tenían nada que
        // ver con la cuenta borrada — sobreviven.
        $this->assertDatabaseHas('users', ['id' => $driver->id]);

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'user.delete',
            'module' => 'usuarios',
        ]);
    }

    public function test_an_admin_can_delete_a_driver_account_and_purges_their_files(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();

        $licensePath = UploadedFile::fake()->image('licencia.jpg')->store('driver-documents', 'local');
        $vehiclePath = UploadedFile::fake()->image('vehiculo.jpg')->store('driver-documents', 'public');
        DriverProfile::factory()->for($driver)->create([
            'license_photo_path' => $licensePath,
            'vehicle_photo_path' => $vehiclePath,
        ]);

        $response = $this->actingAs($admin)->delete(route('admin.users.destroy', $driver), [
            'confirm_email' => $driver->email,
        ]);

        $response->assertRedirect(route('admin.drivers.index'));
        $this->assertDatabaseMissing('users', ['id' => $driver->id]);
        $this->assertDatabaseMissing('driver_profiles', ['user_id' => $driver->id]);
        Storage::disk('local')->assertMissing($licensePath);
        Storage::disk('public')->assertMissing($vehiclePath);
    }
}
