<?php

namespace Tests\Feature\Admin;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: "ver las transaciones que se estan
 * ejecutando ahorita... cliente esperando conductor de tal lado a tal lado
 * por tanto y que salga las unidades cercanas... y las demas transaciones
 * tambien" — panel nuevo, distinto de /admin/operaciones (demanda histórica
 * agregada, sin detalle por transacción).
 */
class LiveOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_see_the_live_operations_panel(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.live-operations.index'))->assertForbidden();
    }

    public function test_a_pending_request_without_a_driver_is_reported_as_searching(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create(['name' => 'Gabriela Parrales']);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        RideRequest::factory()->for($fleet)->create([
            'client_user_id' => $client->id,
            'driver_user_id' => null,
            'status' => 'pending',
            'origin_address' => 'Gral. Calicuchima 428',
            'destination_address' => 'Av. Kennedy',
            'current_offered_price' => 6.90,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.live-operations.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('waitingRequests.0.client.name', 'Gabriela Parrales')
            ->where('waitingRequests.0.phase', 'searching')
            ->where('waitingRequests.0.assigned_driver', null)
            ->where('waitingRequests.0.origin_address', 'Gral. Calicuchima 428')
            ->where('waitingRequests.0.destination_address', 'Av. Kennedy')
            ->where('waitingRequests.0.price', 6.90)
            ->where('stats.waiting', 1)
        );
    }

    public function test_a_request_already_offered_to_a_driver_is_reported_as_awaiting_driver(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();
        $driver = User::factory()->create(['name' => 'Juan Conductor']);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        RideRequest::factory()->for($fleet)->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
            'current_offer_expires_at' => now()->addSeconds(20),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.live-operations.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('waitingRequests.0.phase', 'awaiting_driver')
            ->where('waitingRequests.0.assigned_driver.name', 'Juan Conductor')
            ->has('waitingRequests.0.offer_expires_at')
        );
    }

    /**
     * "que salga las unidas cercadas" (pedido explícito del usuario, casi
     * textual): un conductor disponible y ubicado cerca del origen aparece
     * en la lista; uno lejos, ocupado, o sin ubicación reciente, no.
     */
    public function test_nearby_drivers_only_include_ones_that_are_actually_available_and_close(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        RideRequest::factory()->for($fleet)->create([
            'client_user_id' => $client->id,
            'driver_user_id' => null,
            'status' => 'pending',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
        ]);

        $near = User::factory()->create(['name' => 'Cerca']);
        DriverProfile::factory()->for($near)->create(['current_lat' => -0.1810, 'current_lng' => -78.4680]);

        $far = User::factory()->create(['name' => 'Lejos']);
        DriverProfile::factory()->for($far)->create(['current_lat' => -0.5000, 'current_lng' => -78.9000]);

        $busy = User::factory()->create(['name' => 'Ocupado']);
        DriverProfile::factory()->for($busy)->create(['current_lat' => -0.1811, 'current_lng' => -78.4681]);
        Ride::factory()->create(['driver_user_id' => $busy->id, 'status' => 'in_progress']);

        $stale = User::factory()->create(['name' => 'Desconectado']);
        DriverProfile::factory()->for($stale)->create([
            'current_lat' => -0.1812, 'current_lng' => -78.4682, 'location_updated_at' => now()->subHours(2),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.live-operations.index'));

        $response->assertInertia(function ($page) {
            $names = collect($page->toArray()['props']['waitingRequests'][0]['nearby_drivers'])->pluck('name');
            $this->assertTrue($names->contains('Cerca'));
            $this->assertFalse($names->contains('Lejos'));
            $this->assertFalse($names->contains('Ocupado'));
            $this->assertFalse($names->contains('Desconectado'));
        });
    }

    /**
     * Sub-estado de una carrera en curso (pedido explícito del usuario:
     * "esta en curso una carrera") — sale de qué timestamps ya tiene, no de
     * una columna de estado más granular (todas quedan 'in_progress' hasta
     * completarse).
     */
    public function test_an_active_ride_reports_its_phase_from_its_timestamps(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $justAccepted = Ride::factory()->create(['status' => 'in_progress']);
        $headingToPassenger = Ride::factory()->create(['status' => 'in_progress', 'heading_to_passenger_at' => now()]);
        $arrived = Ride::factory()->create(['status' => 'in_progress', 'heading_to_passenger_at' => now(), 'arrived_at' => now()]);
        $pickedUp = Ride::factory()->create(['status' => 'in_progress', 'heading_to_passenger_at' => now(), 'arrived_at' => now(), 'picked_up_at' => now()]);

        $response = $this->actingAs($admin)->get(route('admin.live-operations.index'));

        $response->assertInertia(function ($page) use ($justAccepted, $headingToPassenger, $arrived, $pickedUp) {
            $phases = collect($page->toArray()['props']['activeRides'])->pluck('phase', 'id');

            $this->assertSame('accepted', $phases[$justAccepted->id]);
            $this->assertSame('heading_to_passenger', $phases[$headingToPassenger->id]);
            $this->assertSame('arrived_waiting_pickup', $phases[$arrived->id]);
            $this->assertSame('en_route_to_destination', $phases[$pickedUp->id]);
        });
    }

    /**
     * Una programada para más tarde no es "está pasando ahora" (pedido
     * explícito del usuario: "las transaciones que se estan ejecutando
     * ahorita") — a menos que su hora ya haya llegado.
     */
    public function test_a_future_scheduled_request_is_excluded_but_a_due_one_is_included(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        RideRequest::factory()->for($fleet)->create([
            'client_user_id' => $client->id, 'driver_user_id' => null, 'status' => 'pending',
            'is_scheduled' => true, 'scheduled_at' => now()->addHours(3),
        ]);
        $due = RideRequest::factory()->for($fleet)->create([
            'client_user_id' => $client->id, 'driver_user_id' => null, 'status' => 'pending',
            'is_scheduled' => true, 'scheduled_at' => now()->subMinutes(5),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.live-operations.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('waitingRequests', 1)
            ->where('waitingRequests.0.id', $due->id)
        );
    }
}
