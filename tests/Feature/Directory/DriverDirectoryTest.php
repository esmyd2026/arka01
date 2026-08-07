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
 */
class DriverDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_only_lists_public_drivers(): void
    {
        $viewer = User::factory()->create();

        // total_points suficiente para la medalla Oro (auditoría de
        // fidelización, pedido explícito del usuario): desde esta pasada, el
        // directorio también exige haber ganado la medalla marcada como
        // "aparece en público", no solo tener el plan que lo permite.
        $publicDriver = User::factory()->create(['name' => 'Conductor Público']);
        DriverProfile::factory()->for($publicDriver)->create(['is_public' => true, 'total_points' => 500]);

        $privateDriver = User::factory()->create(['name' => 'Conductor Privado']);
        DriverProfile::factory()->for($privateDriver)->create(['is_public' => false]);

        $response = $this->actingAs($viewer)->get(route('directory.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Directory/Index')
            ->has('drivers.data', 1)
            ->where('drivers.data.0.name', 'Conductor Público')
        );
    }

    /**
     * Bug real reportado por el usuario (perfil público de un conductor
     * mostrando la insignia "Cliente"): sin este guard, un conductor que
     * pisara esta pantalla por URL directa terminaba con una flota propia
     * fantasma (el bloque de abajo la crea sola para armar el botón
     * "Invitar") — mismo criterio que RideRequestController::create().
     */
    public function test_a_driver_is_redirected_and_does_not_get_a_phantom_fleet(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->actingAs($driver)->get(route('directory.index'));

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseMissing('fleets', ['owner_user_id' => $driver->id]);
    }

    // Nota: existía acá un test "el conductor no se ve a sí mismo en el
    // directorio" — quedó imposible de reproducir (y de más) desde que
    // test_a_driver_is_redirected_and_does_not_get_a_phantom_fleet() cierra
    // el acceso de un conductor a esta pantalla del todo: ya no puede llegar
    // a verse ni a sí mismo ni a nadie. El `reject()` de autoexclusión sigue
    // en el controller como defensa extra, sin un camino real para probarlo.

    /**
     * Fidelización por puntos (pedido explícito del usuario): un conductor
     * con plan que habilita el directorio pero sin medalla suficiente (por
     * debajo de Oro, la semilla de App\Models\DriverTier) no aparece —
     * la visibilidad ahora también se gana con carreras completadas, no
     * solo se paga.
     */
    public function test_a_driver_below_the_public_eligible_tier_does_not_appear_even_if_public(): void
    {
        $viewer = User::factory()->create();

        $belowTier = User::factory()->create(['name' => 'Todavía No Llega']);
        DriverProfile::factory()->for($belowTier)->create(['is_public' => true, 'total_points' => 100]);

        $atTier = User::factory()->create(['name' => 'Ya Llegó']);
        DriverProfile::factory()->for($atTier)->create(['is_public' => true, 'total_points' => 500]);

        $response = $this->actingAs($viewer)->get(route('directory.index'));

        $response->assertInertia(fn ($page) => $page
            ->component('Directory/Index')
            ->has('drivers.data', 1)
            ->where('drivers.data.0.name', 'Ya Llegó')
        );
    }

    /**
     * Diamante por encima de Oro (pedido explícito del usuario: "mejoralo
     * como veas conveniente" — la medalla más alta se ve primero).
     */
    public function test_a_diamond_driver_is_listed_before_a_gold_driver(): void
    {
        $viewer = User::factory()->create();

        $gold = User::factory()->create(['name' => 'Conductor Oro']);
        DriverProfile::factory()->for($gold)->create(['is_public' => true, 'total_points' => 500]);

        $diamond = User::factory()->create(['name' => 'Conductor Diamante']);
        DriverProfile::factory()->for($diamond)->create(['is_public' => true, 'total_points' => 1000]);

        $response = $this->actingAs($viewer)->get(route('directory.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('drivers.data.0.name', 'Conductor Diamante')
            ->where('drivers.data.1.name', 'Conductor Oro')
        );
    }

    public function test_can_invite_a_driver_found_in_the_directory(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_public' => true]);

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
