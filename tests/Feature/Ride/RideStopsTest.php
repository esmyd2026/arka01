<?php

namespace Tests\Feature\Ride;

use App\Models\DriverBankAccount;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use App\Services\PriceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Paradas adicionales en una carrera (pedido explícito del usuario: "agregar
 * una parada adicional... hasta 4 paradas", cada una cobrada por separado —
 * "si no llegan a una parada puedan pagarle cada parada y cancelar la otra o
 * iniciar la siguiente parada"). Ver el plan en
 * C:\Users\User\.claude\plans\validated-exploring-twilight.md.
 */
class RideStopsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));
    }

    private function clientWithFleetDriver(): array
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['rate_per_km' => 0.50, 'is_available' => true]);

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        return [$client, $driver, $fleet];
    }

    private function payloadWithStops(int $driverId): array
    {
        return [
            'driver_user_id' => $driverId,
            'origin_lat' => -0.1800,
            'origin_lng' => -78.4670,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.4800,
            'stops' => [
                ['lat' => -0.1850, 'lng' => -78.4700, 'address' => 'Parada 1'],
                ['lat' => -0.1900, 'lng' => -78.4750, 'address' => 'Parada 2'],
            ],
        ];
    }

    public function test_a_client_can_request_a_ride_with_stops(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $this->actingAs($client)->post(route('ride-requests.store'), $this->payloadWithStops($driver->id))->assertRedirect();

        $rideRequest = RideRequest::query()->firstOrFail();
        $this->assertNotNull($rideRequest->stops_price);
        $this->assertCount(2, $rideRequest->stops);
        $this->assertSame(1, $rideRequest->stops[0]->sequence);
        $this->assertSame(2, $rideRequest->stops[1]->sequence);
        $this->assertSame('Parada 1', $rideRequest->stops[0]->address);

        // La suma de los tramos (redondeados hacia arriba) debe coincidir
        // exactamente con stops_price guardado.
        $expected = PriceCalculator::roundUpToDime(
            (float) $rideRequest->stops[0]->leg_price + (float) $rideRequest->stops[1]->leg_price
        );
        $this->assertSame($expected, (float) $rideRequest->stops_price);
    }

    public function test_the_client_requests_page_exposes_every_pending_stop_in_order(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $this->actingAs($client)
            ->post(route('ride-requests.store'), $this->payloadWithStops($driver->id))
            ->assertRedirect();

        $this->actingAs($client)
            ->get(route('rides.index'))
            ->assertInertia(fn ($page) => $page
                ->has('pendingRequestsAsClient.0.stops', 2)
                ->where('pendingRequestsAsClient.0.stops.0.sequence', 1)
                ->where('pendingRequestsAsClient.0.stops.0.address', 'Parada 1')
                ->where('pendingRequestsAsClient.0.stops.1.sequence', 2)
                ->where('pendingRequestsAsClient.0.stops.1.address', 'Parada 2')
            );
    }

    public function test_a_fifth_stop_is_rejected(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $payload = $this->payloadWithStops($driver->id);
        $payload['stops'][] = ['lat' => -0.1920, 'lng' => -78.4760];
        $payload['stops'][] = ['lat' => -0.1940, 'lng' => -78.4770];
        $payload['stops'][] = ['lat' => -0.1960, 'lng' => -78.4780];

        $this->actingAs($client)->post(route('ride-requests.store'), $payload)->assertSessionHasErrors('stops');
    }

    private function acceptedRideWithStops(): array
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $this->actingAs($client)->post(route('ride-requests.store'), $this->payloadWithStops($driver->id));
        $rideRequest = RideRequest::query()->firstOrFail();

        $this->actingAs($driver)->post(route('ride-requests.accept', $rideRequest->id))->assertRedirect();

        return [$client, $driver, Ride::query()->firstOrFail()];
    }

    public function test_accepting_a_request_with_stops_copies_them_to_the_ride(): void
    {
        [, , $ride] = $this->acceptedRideWithStops();

        $this->assertCount(2, $ride->stops);
        $this->assertSame('pending', $ride->stops[0]->status);
        $this->assertSame('pending', $ride->stops[1]->status);
        $this->assertNotNull($ride->stops_price);
    }

    public function test_the_ride_page_exposes_the_stops_to_the_driver(): void
    {
        [, $driver, $ride] = $this->acceptedRideWithStops();

        $this->actingAs($driver)
            ->get(route('rides.show', $ride->id))
            ->assertInertia(fn ($page) => $page
                ->has('ride.stops', 2)
                ->where('ride.stops.0.status', 'pending')
            );
    }

    public function test_a_transfer_ride_with_stops_exposes_the_driver_bank_account_to_the_client(): void
    {
        [$client, $driver, $ride] = $this->acceptedRideWithStops();
        $ride->update(['payment_method' => 'transferencia', 'picked_up_at' => now()]);
        DriverBankAccount::factory()->for($driver, 'driver')->create(['is_favorite' => true]);

        $this->actingAs($client)
            ->get(route('rides.show', $ride))
            ->assertInertia(fn ($page) => $page
                ->has('ride.stops', 2)
                ->has('driverBankAccounts', 1)
            );
    }

    private function markPickedUp(User $driver, Ride $ride): void
    {
        $this->actingAs($driver)->post(route('rides.arrived', $ride->id));
        $this->actingAs($driver)->post(route('rides.picked-up', $ride->id));
    }

    public function test_completing_a_stop_in_order_keeps_the_ride_in_progress(): void
    {
        [, $driver, $ride] = $this->acceptedRideWithStops();
        $this->markPickedUp($driver, $ride);
        $firstStop = $ride->stops[0];

        $this->actingAs($driver)
            ->post(route('rides.stops.complete', [$ride->id, $firstStop->id]))
            ->assertRedirect();

        $ride->refresh();
        $this->assertSame('in_progress', $ride->status);
        $this->assertSame('completed', $firstStop->fresh()->status);
        $this->assertSame('pending', $ride->stops[1]->status);
    }

    public function test_completing_a_stop_publishes_that_position_as_the_origin_of_the_next_leg(): void
    {
        [, $driver, $ride] = $this->acceptedRideWithStops();
        $this->markPickedUp($driver, $ride);
        $firstStop = $ride->stops[0];

        $this->actingAs($driver)
            ->post(route('rides.stops.complete', [$ride->id, $firstStop->id]), [
                'lat' => (float) $firstStop->lat,
                'lng' => (float) $firstStop->lng,
                'cancel_rest' => false,
            ])
            ->assertRedirect();

        $profile = $driver->driverProfile->fresh();
        $this->assertSame((float) $firstStop->lat, (float) $profile->current_lat);
        $this->assertSame((float) $firstStop->lng, (float) $profile->current_lng);
    }

    public function test_completing_a_stop_out_of_order_is_rejected(): void
    {
        [, $driver, $ride] = $this->acceptedRideWithStops();
        $this->markPickedUp($driver, $ride);
        $secondStop = $ride->stops[1];

        $this->actingAs($driver)
            ->post(route('rides.stops.complete', [$ride->id, $secondStop->id]))
            ->assertSessionHasErrors('ride');

        $this->assertSame('pending', $secondStop->fresh()->status);
    }

    public function test_only_the_driver_can_complete_a_stop(): void
    {
        [$client, $driver, $ride] = $this->acceptedRideWithStops();
        $this->markPickedUp($driver, $ride);

        $this->actingAs($client)
            ->post(route('rides.stops.complete', [$ride->id, $ride->stops[0]->id]))
            ->assertForbidden();
    }

    public function test_completing_a_stop_requires_the_client_to_already_be_picked_up(): void
    {
        [, $driver, $ride] = $this->acceptedRideWithStops();

        $this->actingAs($driver)
            ->post(route('rides.stops.complete', [$ride->id, $ride->stops[0]->id]))
            ->assertSessionHasErrors('ride');
    }

    public function test_completing_a_stop_far_away_requires_a_reason(): void
    {
        [, $driver, $ride] = $this->acceptedRideWithStops();
        $this->markPickedUp($driver, $ride);
        $firstStop = $ride->stops[0];

        $this->actingAs($driver)
            ->post(route('rides.stops.complete', [$ride->id, $firstStop->id]), [
                // Bien lejos de -0.1850, -78.4700 (más de 1.5 km).
                'lat' => -0.1350,
                'lng' => -78.4700,
            ])
            ->assertSessionHasErrors('completion_reason');

        $this->assertSame('pending', $firstStop->fresh()->status);
    }

    public function test_completing_a_stop_far_away_succeeds_with_a_reason(): void
    {
        [, $driver, $ride] = $this->acceptedRideWithStops();
        $this->markPickedUp($driver, $ride);
        $firstStop = $ride->stops[0];

        $this->actingAs($driver)
            ->post(route('rides.stops.complete', [$ride->id, $firstStop->id]), [
                'lat' => -0.1350,
                'lng' => -78.4700,
                'completion_reason' => 'Otro motivo',
                'completion_note' => 'El acceso estaba cerrado.',
            ])
            ->assertRedirect();

        $firstStop->refresh();
        $this->assertSame('completed', $firstStop->status);
        $this->assertSame('Otro motivo', $firstStop->completion_reason);
        $this->assertSame('El acceso estaba cerrado.', $firstStop->completion_note);
    }

    public function test_completing_a_stop_with_cancel_rest_closes_the_ride_early(): void
    {
        [, $driver, $ride] = $this->acceptedRideWithStops();
        $this->markPickedUp($driver, $ride);
        $firstStop = $ride->stops[0];
        $secondStop = $ride->stops[1];

        $this->actingAs($driver)
            ->post(route('rides.stops.complete', [$ride->id, $firstStop->id]), ['cancel_rest' => true])
            ->assertRedirect();

        $ride->refresh();
        $this->assertSame('completed', $ride->status);
        $this->assertSame('completed', $firstStop->fresh()->status);
        $this->assertSame('cancelled', $secondStop->fresh()->status);
        // Solo el tramo 1 se cobró — ni el tramo 2 ni el precio del tramo
        // final (que nunca sucedió) entran en settled_price.
        $this->assertSame((float) $firstStop->leg_price, (float) $ride->settled_price);
    }

    public function test_completing_the_ride_is_blocked_while_a_stop_is_pending(): void
    {
        [, $driver, $ride] = $this->acceptedRideWithStops();
        $this->markPickedUp($driver, $ride);

        $this->actingAs($driver)
            ->post(route('rides.complete', $ride->id))
            ->assertSessionHasErrors('ride');

        $this->assertSame('in_progress', $ride->fresh()->status);
    }

    public function test_completing_all_stops_then_the_final_leg_settles_the_full_total(): void
    {
        [, $driver, $ride] = $this->acceptedRideWithStops();
        $this->markPickedUp($driver, $ride);

        foreach ($ride->stops as $stop) {
            $this->actingAs($driver)->post(route('rides.stops.complete', [$ride->id, $stop->id]))->assertRedirect();
        }

        $this->actingAs($driver)->post(route('rides.complete', $ride->id))->assertRedirect();

        $ride->refresh();
        $this->assertSame('completed', $ride->status);
        $expected = (float) $ride->stops()->sum('leg_price') + (float) $ride->price;
        $this->assertSame($expected, (float) $ride->settled_price);
        $this->assertSame($expected, $ride->quotedTotal());
        $this->assertSame($expected, $ride->chargedTotal());
    }

    public function test_a_ride_without_stops_is_unaffected(): void
    {
        [$client, $driver, $fleet] = $this->clientWithFleetDriver();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1800,
            'origin_lng' => -78.4670,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.4800,
        ])->assertRedirect();

        $rideRequest = RideRequest::query()->firstOrFail();
        $this->assertNull($rideRequest->stops_price);
        $this->assertCount(0, $rideRequest->stops);

        $this->actingAs($driver)->post(route('ride-requests.accept', $rideRequest->id))->assertRedirect();
        $ride = Ride::query()->firstOrFail();
        $this->assertNull($ride->stops_price);
        $this->assertCount(0, $ride->stops);

        $this->markPickedUp($driver, $ride);
        $this->actingAs($driver)->post(route('rides.complete', $ride->id))->assertRedirect();

        $ride->refresh();
        $this->assertSame('completed', $ride->status);
        $this->assertSame((float) $ride->price, (float) $ride->settled_price);
        $this->assertSame((float) $ride->price, $ride->quotedTotal());
        $this->assertSame((float) $ride->price, $ride->chargedTotal());
    }
}
