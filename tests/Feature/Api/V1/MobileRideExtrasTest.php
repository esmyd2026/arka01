<?php

namespace Tests\Feature\Api\V1;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\RatingReason;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El resto del ciclo de vida de una carrera desde la app móvil (roadmap
 * Hito 5, pasada "full backend"): negociación de precio, chat, calificación,
 * reprogramación, paradas intermedias e historial paginado. Todo reusa los
 * servicios ya extraídos (App\Services\Ride\*), así que estos tests se
 * enfocan en el contrato JSON y la autorización específica del canal móvil.
 */
class MobileRideExtrasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function pendingRequest(): array
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $rideRequest = RideRequest::factory()->for($client, 'client')->for($driver, 'driver')->for($fleet)->create([
            'status' => 'pending',
            'current_offered_price' => 5.00,
        ]);

        return [$client, $driver, $rideRequest];
    }

    private function inProgressRide(array $overrides = []): Ride
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $rideRequest = RideRequest::factory()->for($client, 'client')->for($driver, 'driver')->for($fleet)->create([
            'status' => 'accepted',
        ]);

        return Ride::factory()
            ->for($rideRequest)
            ->for($fleet)
            ->for($client, 'client')
            ->for($driver, 'driver')
            ->create(array_merge(['status' => 'in_progress'], $overrides));
    }

    // --- Negociación de precio ---

    public function test_a_driver_can_counter_offer(): void
    {
        [$client, $driver, $rideRequest] = $this->pendingRequest();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson("/api/v1/ride-requests/{$rideRequest->id}/counter", ['offered_amount' => 7.5]);

        $response->assertOk()->assertJsonPath('ride_request.status', 'negotiating');
        $this->assertSame('negotiating', $rideRequest->fresh()->status);
    }

    public function test_a_client_can_raise_their_offer(): void
    {
        [$client, $driver, $rideRequest] = $this->pendingRequest();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson("/api/v1/ride-requests/{$rideRequest->id}/raise-offer", ['offered_amount' => 8.0]);

        $response->assertOk();
        $this->assertEquals(8.0, (float) $rideRequest->fresh()->current_offered_price);
    }

    public function test_raising_the_offer_below_the_current_price_is_rejected(): void
    {
        [$client, $driver, $rideRequest] = $this->pendingRequest();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson("/api/v1/ride-requests/{$rideRequest->id}/raise-offer", ['offered_amount' => 1.0])
            ->assertUnprocessable();
    }

    // --- Chat ---

    public function test_the_driver_can_send_and_list_messages(): void
    {
        $ride = $this->inProgressRide();

        $sendResponse = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->postJson("/api/v1/rides/{$ride->id}/messages", ['body' => 'Ya estoy en camino']);

        $sendResponse->assertCreated()->assertJsonPath('body', 'Ya estoy en camino');

        $listResponse = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->client))
            ->getJson("/api/v1/rides/{$ride->id}/messages");

        $listResponse->assertOk()->assertJsonCount(1, 'messages');
    }

    public function test_a_stranger_cannot_read_the_chat(): void
    {
        $ride = $this->inProgressRide();
        $stranger = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->getJson("/api/v1/rides/{$ride->id}/messages")
            ->assertForbidden();
    }

    public function test_the_chat_is_closed_after_the_ride_is_cancelled(): void
    {
        $ride = $this->inProgressRide(['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->postJson("/api/v1/rides/{$ride->id}/messages", ['body' => 'Hola'])
            ->assertUnprocessable();
    }

    // --- Calificación ---

    public function test_the_client_can_review_a_completed_ride(): void
    {
        $ride = $this->inProgressRide(['status' => 'completed', 'completed_at' => now()]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->client))
            ->postJson("/api/v1/rides/{$ride->id}/review", ['rating' => 5]);

        $response->assertCreated()->assertJsonPath('rating', 5);
        $this->assertDatabaseHas('reviews', [
            'ride_id' => $ride->id,
            'reviewer_user_id' => $ride->client_user_id,
            'reviewee_user_id' => $ride->driver_user_id,
            'rating' => 5,
        ]);
    }

    public function test_a_low_rating_requires_a_valid_reason(): void
    {
        $ride = $this->inProgressRide(['status' => 'completed', 'completed_at' => now()]);
        $reason = RatingReason::factory()->create(['direction' => 'client_to_driver', 'is_active' => true]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->client))
            ->postJson("/api/v1/rides/{$ride->id}/review", ['rating' => 2, 'rating_reason_id' => $reason->id]);

        $response->assertCreated();
    }

    public function test_a_low_rating_without_a_reason_is_rejected(): void
    {
        $ride = $this->inProgressRide(['status' => 'completed', 'completed_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->client))
            ->postJson("/api/v1/rides/{$ride->id}/review", ['rating' => 2])
            ->assertUnprocessable();
    }

    public function test_cannot_review_the_same_ride_twice(): void
    {
        $ride = $this->inProgressRide(['status' => 'completed', 'completed_at' => now()]);
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($ride->client)];

        $this->withHeaders($headers)->postJson("/api/v1/rides/{$ride->id}/review", ['rating' => 5])->assertCreated();
        $this->withHeaders($headers)->postJson("/api/v1/rides/{$ride->id}/review", ['rating' => 4])->assertUnprocessable();
    }

    // --- Reprogramación ---

    public function test_the_client_can_propose_a_new_schedule(): void
    {
        $ride = $this->inProgressRide(['status' => 'scheduled']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->client))
            ->postJson("/api/v1/rides/{$ride->id}/reschedule", [
                'scheduled_date' => '2026-01-20',
                'scheduled_time' => '10:00',
            ]);

        $response->assertOk();
        $this->assertNotNull($ride->fresh()->pending_reschedule_at);
    }

    public function test_the_driver_can_confirm_the_new_schedule(): void
    {
        $ride = $this->inProgressRide(['status' => 'scheduled', 'pending_reschedule_at' => now()->addDays(3)]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->postJson("/api/v1/rides/{$ride->id}/reschedule/confirm");

        $response->assertOk();
        $this->assertNull($ride->fresh()->pending_reschedule_at);
        $this->assertEquals(now()->addDays(3)->timestamp, $ride->rideRequest->fresh()->scheduled_at->timestamp);
    }

    public function test_the_driver_can_reject_the_new_schedule(): void
    {
        $ride = $this->inProgressRide(['status' => 'scheduled', 'pending_reschedule_at' => now()->addDays(3)]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->postJson("/api/v1/rides/{$ride->id}/reschedule/reject");

        $response->assertOk();
        $this->assertNull($ride->fresh()->pending_reschedule_at);
    }

    public function test_the_client_cannot_confirm_a_reschedule(): void
    {
        $ride = $this->inProgressRide(['status' => 'scheduled', 'pending_reschedule_at' => now()->addDays(3)]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->client))
            ->postJson("/api/v1/rides/{$ride->id}/reschedule/confirm")
            ->assertForbidden();
    }

    // --- Paradas ---

    public function test_the_driver_can_complete_the_next_pending_stop(): void
    {
        $ride = $this->inProgressRide(['picked_up_at' => now()]);
        $stop = $ride->stops()->create([
            'sequence' => 1,
            'lat' => (float) $ride->origin_lat,
            'lng' => (float) $ride->origin_lng,
            'leg_distance_km' => 1,
            'leg_price' => 2,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->postJson("/api/v1/rides/{$ride->id}/stops/{$stop->id}/complete", [
                'lat' => (float) $ride->origin_lat,
                'lng' => (float) $ride->origin_lng,
            ]);

        $response->assertOk();
        $this->assertSame('completed', $stop->fresh()->status);
    }

    public function test_completing_a_stop_with_cancel_rest_closes_the_ride(): void
    {
        $ride = $this->inProgressRide(['picked_up_at' => now()]);
        $stop = $ride->stops()->create([
            'sequence' => 1,
            'lat' => (float) $ride->origin_lat,
            'lng' => (float) $ride->origin_lng,
            'leg_distance_km' => 1,
            'leg_price' => 2,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($ride->driver))
            ->postJson("/api/v1/rides/{$ride->id}/stops/{$stop->id}/complete", [
                'lat' => (float) $ride->origin_lat,
                'lng' => (float) $ride->origin_lng,
                'cancel_rest' => true,
            ]);

        $response->assertOk()->assertJsonPath('ride.status', 'completed');
    }

    // --- Historial ---

    public function test_the_history_endpoint_lists_finished_rides_paginated(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $rideRequest = RideRequest::factory()->for($client, 'client')->for($driver, 'driver')->for($fleet)->create(['status' => 'accepted']);

        Ride::factory()->for($rideRequest)->for($fleet)->for($client, 'client')->for($driver, 'driver')
            ->create(['status' => 'completed', 'completed_at' => now()]);

        // Una carrera activa no debe aparecer en el historial.
        $activeRequest = RideRequest::factory()->for($client, 'client')->for($driver, 'driver')->for($fleet)->create(['status' => 'accepted']);
        Ride::factory()->for($activeRequest)->for($fleet)->for($client, 'client')->for($driver, 'driver')->create(['status' => 'in_progress']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/rides/history');

        $response->assertOk()->assertJsonCount(1, 'rides')->assertJsonPath('rides.0.status', 'completed');
    }

    public function test_it_requires_a_token_for_history(): void
    {
        $this->getJson('/api/v1/rides/history')->assertUnauthorized();
    }
}
