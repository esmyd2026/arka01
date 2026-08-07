<?php

namespace Tests\Feature\Fleet;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Review;
use App\Models\Ride;
use App\Models\User;
use App\Notifications\RideRequestedPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "Deshabilitar" a un cliente puntual (pedido explícito del usuario): el
 * conductor sigue siendo parte de su flota, solo deja de recibir sus
 * solicitudes de carrera — sin tener que cortar la relación entera (eso ya
 * existe: salir de la flota).
 */
class DisableClientRequestsTest extends TestCase
{
    use RefreshDatabase;

    private function clientWithFleetDriver(): array
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['rate_per_km' => 0.5]);

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $member = FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        return [$client, $driver, $fleet, $member];
    }

    public function test_the_driver_can_toggle_requests_off_and_on_for_a_client(): void
    {
        [, $driver, , $member] = $this->clientWithFleetDriver();

        $this->actingAs($driver)->post(route('driver.fleets.toggle-requests', $member))->assertRedirect();
        $this->assertTrue($member->fresh()->requests_disabled);

        $this->actingAs($driver)->post(route('driver.fleets.toggle-requests', $member))->assertRedirect();
        $this->assertFalse($member->fresh()->requests_disabled);
    }

    public function test_a_stranger_cannot_toggle_someone_elses_membership(): void
    {
        [, , , $member] = $this->clientWithFleetDriver();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post(route('driver.fleets.toggle-requests', $member))->assertForbidden();
    }

    public function test_a_directed_request_to_a_disabled_driver_is_rejected(): void
    {
        [$client, $driver, , $member] = $this->clientWithFleetDriver();
        $member->update(['requests_disabled' => true]);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertSessionHasErrors('driver_user_id');
    }

    public function test_a_whole_fleet_request_does_not_notify_a_driver_who_disabled_that_client(): void
    {
        Notification::fake();
        Queue::fake();

        [$client, $disabledDriver, $fleet] = $this->clientWithFleetDriver();
        FleetMember::query()
            ->where('fleet_id', $fleet->id)
            ->where('driver_user_id', $disabledDriver->id)
            ->update(['requests_disabled' => true]);

        $activeDriver = User::factory()->create();
        DriverProfile::factory()->for($activeDriver)->create(['rate_per_km' => 0.5]);
        FleetMember::factory()->for($fleet)->for($activeDriver, 'driver')->create(['added_by' => $client->id]);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertSessionHasNoErrors();

        Notification::assertNotSentTo($disabledDriver, RideRequestedPushNotification::class);
        Notification::assertSentTo($activeDriver, RideRequestedPushNotification::class);
    }

    /**
     * "Mis clientes de confianza" (pedido explícito del usuario): foto,
     * puntos, cuántas carreras le hizo, su último viaje y su categoría —
     * antes solo se veía el nombre.
     */
    public function test_the_invitations_screen_shows_an_enriched_client_card(): void
    {
        [$client, $driver, , $member] = $this->clientWithFleetDriver();

        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
            'completed_at' => now()->subDay(),
        ]);

        Review::query()->create([
            'ride_id' => $ride->id,
            'reviewer_user_id' => $driver->id,
            'reviewee_user_id' => $client->id,
            'rating' => 5,
        ]);

        $response = $this->actingAs($driver)->get(route('driver.invitations.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('activeMemberships.0.id', $member->id)
            ->where('activeMemberships.0.client_rating', fn ($rating) => (float) $rating === 5.0)
            ->where('activeMemberships.0.client_review_count', 1)
            ->where('activeMemberships.0.client_category', 'plata')
            ->where('activeMemberships.0.rides_together_count', 1)
            ->where('activeMemberships.0.requests_disabled', false)
            ->has('activeMemberships.0.last_ride_at')
        );
    }
}
