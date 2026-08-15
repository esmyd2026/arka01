<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `auth.isDriver` / `auth.isClient` / `auth.hasFleet` compartidos por Inertia
 * (sección 3.1): la navegación del frontend (AuthenticatedLayout.vue) decide
 * qué mostrar en base a estos flags, así que tienen que reflejar el estado
 * real. Cada cuenta es cliente o conductor, nunca las dos.
 */
class NavigationRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_brand_new_user_is_neither_driver_nor_fleet_owner(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('auth.isDriver', false)
            ->where('auth.isClient', true)
            ->where('auth.hasFleet', false)
        );
    }

    public function test_a_driver_with_no_fleet_of_their_own_is_flagged_as_driver_only(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->actingAs($driver)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('auth.isDriver', true)
            ->where('auth.isClient', false)
            ->where('auth.hasFleet', false)
        );
    }

    /**
     * Cada cuenta es cliente o conductor, nunca las dos (consideración
     * agregada al alcance) — un conductor ya no puede tener flota propia.
     */
    public function test_a_driver_cannot_create_or_access_a_fleet(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($driver)->get(route('fleet.index'))->assertRedirect(route('dashboard'));

        $this->assertDatabaseMissing('fleets', ['owner_user_id' => $driver->id]);

        $this->actingAs($driver)
            ->post(route('fleet.store'), ['name' => 'Otra flota'])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseMissing('fleets', ['owner_user_id' => $driver->id]);
    }

    /**
     * Pedido explícito del usuario ("pasarme a conductor... fácil"): un dueño
     * de flota SÍ puede activarse como conductor con la misma cuenta — antes
     * quedaba bloqueado de raíz (ver git blame), ahora cambia de rol en vez
     * de sumar los dos. La flota que ya tenía como cliente queda guardada tal
     * cual, no se borra (ver tests/Feature/Driver/RoleSwitchingTest.php para
     * el flujo completo de ida y vuelta).
     */
    public function test_a_fleet_owner_can_switch_to_the_driver_role(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $this->actingAs($client)->get(route('driver.profile.edit'))->assertOk();

        $this->actingAs($client)
            ->post(route('driver.profile.update'), [
                'license_number' => 'ABC123',
                'vehicle_make' => 'Chevrolet',
                'vehicle_model' => 'Spark',
                'vehicle_color' => 'Blanco',
                'vehicle_type' => 'sedan',
                'vehicle_plate' => 'ABC-1234',
                'vehicle_year' => 2020,
                'passenger_capacity' => 4,
                'has_trunk' => true,
                'rate_per_km' => 0.4,
                'profile_photo' => UploadedFile::fake()->image('perfil.jpg'),
                'identity_document' => UploadedFile::fake()->image('cedula.jpg'),
                'license_photo' => UploadedFile::fake()->image('licencia.jpg'),
                'police_record' => UploadedFile::fake()->create('antecedentes.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('driver_profiles', ['user_id' => $client->id]);
        $this->assertDatabaseHas('fleets', ['id' => $fleet->id, 'owner_user_id' => $client->id]);
        $this->assertTrue($client->fresh()->isDriver());
        $this->assertFalse($client->fresh()->isClient());
    }

    /**
     * `auth.plans` (consideración agregada al alcance): el plan vigente de
     * cada rol activo, para mostrarlo debajo de la calificación en el menú de
     * cuenta — mismo criterio de "qué rol mostrar" que isDriver/hasFleet.
     */
    public function test_auth_shares_the_current_plan_name_of_each_active_role(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page
            ->where('auth.plans.driver', null)
            ->where('auth.plans.client', 'Gratis')
        );
    }
}
