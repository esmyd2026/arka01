<?php

namespace Tests\Feature\Ride;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\RideDispatchCandidates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Bug reportado por el usuario: si un conductor pasa la app a segundo plano,
 * cierra el navegador o pierde señal, deja de mandar su ping de ubicación
 * pero `is_available` se queda en true hasta que corre el barrido de 2
 * minutos (App\Console\Commands\SweepStaleDriverAvailability) — mientras
 * tanto, podía seguir recibiendo solicitudes como si estuviera activo.
 * DriverProfile::isStale() cierra ese hueco de inmediato, sin esperar al
 * barrido, en cada lugar que arma candidatos o muestra "disponible".
 */
class StaleDriverAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function clientWithFleetDriver(): array
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        return [$client, $driver, $fleet];
    }

    public function test_a_driver_without_a_recent_ping_is_stale(): void
    {
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create(['location_updated_at' => now()->subMinutes(3)]);

        $this->assertTrue($profile->isStale());
    }

    public function test_a_driver_with_a_recent_ping_is_not_stale(): void
    {
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create(['location_updated_at' => now()->subSeconds(30)]);

        $this->assertFalse($profile->isStale());
    }

    public function test_a_driver_who_never_pinged_is_stale(): void
    {
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create(['location_updated_at' => null]);

        $this->assertTrue($profile->isStale());
    }

    public function test_forpool_excludes_a_stale_driver_even_if_marked_available(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();
        DriverProfile::factory()->for($driver)->create([
            'is_available' => true,
            'current_lat' => -0.1807, 'current_lng' => -78.4678,
            'location_updated_at' => now()->subMinutes(5),
        ]);

        $ids = RideDispatchCandidates::forPool($fleet, $client, 'fleet', -0.1807, -78.4678);

        $this->assertSame([], $ids);
    }

    public function test_is_still_eligible_returns_false_for_a_stale_driver(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'is_available' => true,
            'current_lat' => -0.1807, 'current_lng' => -78.4678,
            'location_updated_at' => now()->subMinutes(5),
        ]);

        $this->assertFalse(RideDispatchCandidates::isStillEligible($driver->id, -0.1807, -78.4678));
    }

    public function test_store_rejects_a_directed_request_to_a_stale_driver(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        DriverProfile::factory()->for($driver)->create(['location_updated_at' => now()->subMinutes(5)]);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertSessionHasErrors('driver_user_id');

        $this->assertDatabaseMissing('ride_requests', ['client_user_id' => $client->id]);
    }

    public function test_the_request_screen_shows_a_stale_driver_as_offline(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        DriverProfile::factory()->for($driver)->create(['is_available' => true, 'location_updated_at' => now()->subMinutes(5)]);

        $response = $this->actingAs($client)->get(route('ride-requests.create'));

        $response->assertInertia(fn ($page) => $page->where(
            'fleetDrivers',
            fn ($drivers) => collect($drivers)->firstWhere('user_id', $driver->id)['status'] === 'offline'
        ));
    }

    public function test_the_public_directory_shows_a_stale_driver_as_unavailable(): void
    {
        $driver = User::factory()->create();
        // total_points suficiente para la medalla Oro (auditoría de
        // fidelización): el directorio ahora también exige haberla ganado.
        DriverProfile::factory()->for($driver)->create([
            'is_public' => true,
            'is_available' => true,
            'location_updated_at' => now()->subMinutes(5),
            'total_points' => 500,
        ]);

        $viewer = User::factory()->create();
        $response = $this->actingAs($viewer)->get(route('directory.index'));

        $response->assertInertia(fn ($page) => $page->where(
            'drivers.data',
            fn ($drivers) => collect($drivers)->firstWhere('user_id', $driver->id)['is_available'] === false
        ));
    }

    public function test_the_dashboard_fleet_widget_shows_a_stale_driver_as_offline(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        DriverProfile::factory()->for($driver)->create(['is_available' => true, 'location_updated_at' => now()->subMinutes(5)]);

        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page->where(
            'fleetDrivers',
            fn ($drivers) => collect($drivers)->firstWhere('user_id', $driver->id)['status'] === 'offline'
        ));
    }

    /**
     * Bug reportado por el usuario (caso real: el conductor Luis se veía
     * "desconectado" aunque seguía con el celular a mano): con la ventana de
     * WhatsApp de 24h todavía abierta, un conductor con GPS viejo sigue
     * tratándose como alcanzable en todos los lugares que antes lo excluían
     * solo por isStale() — DriverProfile::isReachable().
     */
    private function openWhatsAppWindowFor(User $driver): void
    {
        WhatsAppSession::query()->create(['user_id' => $driver->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);
    }

    public function test_a_stale_driver_with_an_active_whatsapp_session_is_reachable(): void
    {
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create(['location_updated_at' => now()->subMinutes(5)]);
        $this->openWhatsAppWindowFor($driver);

        $this->assertTrue($profile->isReachable(true));
        $this->assertFalse($profile->isReachable(false));
    }

    public function test_forpool_includes_a_stale_driver_with_an_active_whatsapp_session(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();
        DriverProfile::factory()->for($driver)->create([
            'is_available' => true,
            'current_lat' => -0.1807, 'current_lng' => -78.4678,
            'location_updated_at' => now()->subMinutes(5),
        ]);
        $this->openWhatsAppWindowFor($driver);

        $ids = RideDispatchCandidates::forPool($fleet, $client, 'fleet', -0.1807, -78.4678);

        $this->assertSame([$driver->id], $ids);
    }

    public function test_is_still_eligible_returns_true_for_a_stale_driver_with_an_active_whatsapp_session(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'is_available' => true,
            'current_lat' => -0.1807, 'current_lng' => -78.4678,
            'location_updated_at' => now()->subMinutes(5),
        ]);
        $this->openWhatsAppWindowFor($driver);

        $this->assertTrue(RideDispatchCandidates::isStillEligible($driver->id, -0.1807, -78.4678));
    }

    public function test_store_allows_a_directed_request_to_a_stale_driver_with_an_active_whatsapp_session(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        DriverProfile::factory()->for($driver)->create(['is_available' => true, 'location_updated_at' => now()->subMinutes(5)]);
        $this->openWhatsAppWindowFor($driver);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertSessionDoesntHaveErrors('driver_user_id');
    }

    public function test_the_request_screen_shows_a_stale_but_whatsapp_reachable_driver_as_available(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        DriverProfile::factory()->for($driver)->create(['is_available' => true, 'location_updated_at' => now()->subMinutes(5)]);
        $this->openWhatsAppWindowFor($driver);

        $response = $this->actingAs($client)->get(route('ride-requests.create'));

        $response->assertInertia(fn ($page) => $page->where(
            'fleetDrivers',
            fn ($drivers) => collect($drivers)->firstWhere('user_id', $driver->id)['status'] === 'available'
        ));
    }

    public function test_the_public_directory_shows_a_stale_but_whatsapp_reachable_driver_as_available(): void
    {
        $driver = User::factory()->create();
        // total_points suficiente para la medalla Oro (auditoría de
        // fidelización): el directorio ahora también exige haberla ganado.
        DriverProfile::factory()->for($driver)->create([
            'is_public' => true,
            'is_available' => true,
            'location_updated_at' => now()->subMinutes(5),
            'total_points' => 500,
        ]);
        $this->openWhatsAppWindowFor($driver);

        $viewer = User::factory()->create();
        $response = $this->actingAs($viewer)->get(route('directory.index'));

        $response->assertInertia(fn ($page) => $page->where(
            'drivers.data',
            fn ($drivers) => collect($drivers)->firstWhere('user_id', $driver->id)['is_available'] === true
        ));
    }

    public function test_the_dashboard_fleet_widget_shows_a_stale_but_whatsapp_reachable_driver_as_available(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();
        DriverProfile::factory()->for($driver)->create(['is_available' => true, 'location_updated_at' => now()->subMinutes(5)]);
        $this->openWhatsAppWindowFor($driver);

        $response = $this->actingAs($client)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page->where(
            'fleetDrivers',
            fn ($drivers) => collect($drivers)->firstWhere('user_id', $driver->id)['status'] === 'available'
        ));
    }

    /**
     * Bug reportado por el usuario (contradicción real vista en pantalla:
     * la lista de candidatos mostraba al conductor "En carrera", pero el
     * mensaje de error decía "ninguno conectado"): un conductor EN CARRERA
     * ya demostró que está conectado —hay una Ride real en curso a su
     * nombre— aunque su ping general de ubicación se haya quedado viejo
     * mientras maneja. El motivo real tiene que seguir siendo "ocupado",
     * no "desconectado".
     */
    public function test_a_busy_driver_with_a_stale_ping_still_counts_as_connected(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();
        DriverProfile::factory()->for($driver)->create([
            'is_available' => true,
            'current_lat' => -0.1807,
            'current_lng' => -78.4678,
            'location_updated_at' => now()->subMinutes(5),
        ]);
        Ride::factory()->create(['driver_user_id' => $driver->id, 'status' => 'in_progress']);

        $this->assertTrue(RideDispatchCandidates::isEmptyOnlyBecauseEveryoneIsBusy(
            $fleet, $client, 'fleet', -0.1807, -78.4678,
        ));
        $this->assertSame(
            'Todos sus conductores disponibles están en otra carrera ahora mismo.',
            RideDispatchCandidates::explainEmptyPool($fleet, $client, 'fleet', -0.1807, -78.4678),
        );
    }

    /**
     * Mismo bug, de punta a punta: la solicitud queda `waiting` (lista de
     * espera) en vez de rechazarse con un mensaje que no tiene nada que ver.
     */
    public function test_requesting_a_ride_to_a_busy_stale_fleet_creates_a_waiting_request(): void
    {
        Queue::fake();

        [$client, $driver] = $this->clientWithFleetDriver();
        DriverProfile::factory()->for($driver)->create([
            'is_available' => true,
            'current_lat' => -0.1807,
            'current_lng' => -78.4678,
            'location_updated_at' => now()->subMinutes(5),
        ]);
        Ride::factory()->create(['driver_user_id' => $driver->id, 'status' => 'in_progress']);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'dispatch_pool' => 'fleet',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('ride_requests', ['client_user_id' => $client->id, 'status' => 'waiting']);
    }
}
