<?php

namespace Tests\Feature\Directory;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Directorio de conductores públicos (sección 3.4): la red de respaldo
 * cuando nadie de la flota personal está disponible.
 *
 * Pedido explícito del usuario ("imaginate un mapa... casi cubriendo toda
 * la pantalla"): en la web esta pantalla pasó de ser una lista paginada con
 * filtro por sector a un mapa de "conductores cerca de mí" — el render
 * inicial de esta página ya no trae datos de conductores (los pide
 * Directory/Index.vue por fetch() ni bien tiene la ubicación, ver
 * DriverNearbyMapTest). Lo que este archivo cubría antes (medalla mínima,
 * orden por medalla, foto de vehículo oculta) sigue siendo responsabilidad
 * real de App\Services\Driver\DriverDirectoryFinder::browse() — ese método
 * lo sigue usando la app móvil tal cual, así que esa cobertura se movió a
 * tests\Feature\Api\V1\MobileDirectoryTest.
 */
class DriverDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_map_page_renders_for_a_client(): void
    {
        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->get(route('directory.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Directory/Index'));
    }

    /**
     * Bug real reportado por el usuario (perfil público de un conductor
     * mostrando la insignia "Cliente"): sin este guard, un conductor que
     * pisara esta pantalla por URL directa terminaba con una flota propia
     * fantasma (el botón "Agregar a mi flota" la crea sola) — mismo criterio
     * que RideRequestController::create().
     */
    public function test_a_driver_is_redirected_and_does_not_get_a_phantom_fleet(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->actingAs($driver)->get(route('directory.index'));

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseMissing('fleets', ['owner_user_id' => $driver->id]);
    }

    public function test_can_invite_a_driver_found_in_the_directory(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        // Este test verifica el status 'pending' de la invitación en sí — la
        // aprobación automática (default) no es lo que se está probando acá.
        DriverProfile::factory()->for($driver)->create(['is_public' => true, 'requires_fleet_invitation_approval' => true]);

        // Mismo endpoint que "Mi Flota" (Fase 1) — el directorio no duplica lógica de invitación.
        $this->actingAs($client)
            ->post(route('fleet.invitations.store', $fleet), ['driver_user_id' => $driver->id])
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_invitations', [
            'driver_user_id' => $driver->id,
            'invited_by' => $client->id,
            'status' => 'pending',
        ]);
    }
}
