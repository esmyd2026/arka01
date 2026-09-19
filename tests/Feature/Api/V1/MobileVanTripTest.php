<?php

namespace Tests\Feature\Api\V1;

use App\Models\City;
use App\Models\DriverProfile;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\VanTrip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Viajes tipo VAN/buseta desde la app móvil (roadmap Hito 5B, paridad con
 * la web). Reusa App\Services\VanTrip\VanTripManager — mismo servicio que
 * tests\Feature\VanTrips\VanTripFlowTest (web).
 */
class MobileVanTripTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function citiesPair(): array
    {
        return [
            City::query()->create(['name' => 'Quito', 'is_active' => true]),
            City::query()->create(['name' => 'Guayaquil', 'is_active' => true]),
        ];
    }

    private function driverWithPlan(string $planCode = 'pro'): User
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', $planCode)->firstOrFail();
        Subscription::factory()->for($driver)->create(['subscription_plan_id' => $plan->id, 'status' => 'active']);

        return $driver;
    }

    public function test_a_driver_with_a_van_trips_plan_can_publish_a_trip(): void
    {
        [$origin, $destination] = $this->citiesPair();
        $driver = $this->driverWithPlan('pro');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/van-trips', [
                'origin_city_id' => $origin->id,
                'destination_city_id' => $destination->id,
                'travel_date' => now()->addDays(3)->toDateString(),
                'departure_time' => '06:30',
                'total_seats' => 15,
                'price_per_seat' => 12.5,
                'description' => 'Salida turística',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('van_trips', [
            'driver_user_id' => $driver->id,
            'origin_city_id' => $origin->id,
            'destination_city_id' => $destination->id,
            'total_seats' => 15,
            'status' => 'open',
        ]);
    }

    public function test_a_driver_without_the_plan_feature_cannot_publish(): void
    {
        [$origin, $destination] = $this->citiesPair();
        $driver = $this->driverWithPlan('basico');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/van-trips', [
                'origin_city_id' => $origin->id,
                'destination_city_id' => $destination->id,
                'travel_date' => now()->addDays(3)->toDateString(),
                'departure_time' => '06:30',
                'total_seats' => 15,
                'price_per_seat' => 12.5,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('trip');

        $this->assertDatabaseCount('van_trips', 0);
    }

    public function test_browse_only_shows_open_trips_with_seats_left(): void
    {
        [$origin, $destination] = $this->citiesPair();
        $driver = $this->driverWithPlan();

        $full = VanTrip::query()->create([
            'driver_user_id' => $driver->id, 'origin_city_id' => $origin->id, 'destination_city_id' => $destination->id,
            'travel_date' => now()->addDay(), 'departure_time' => '06:00', 'total_seats' => 1, 'price_per_seat' => 10, 'status' => 'open',
        ]);
        $full->reservations()->create(['client_user_id' => User::factory()->create()->id, 'seats_reserved' => 1, 'status' => 'confirmed', 'reserved_at' => now()]);

        $open = VanTrip::query()->create([
            'driver_user_id' => $driver->id, 'origin_city_id' => $origin->id, 'destination_city_id' => $destination->id,
            'travel_date' => now()->addDay(), 'departure_time' => '07:00', 'total_seats' => 10, 'price_per_seat' => 10, 'status' => 'open',
        ]);

        $seeker = User::factory()->create();
        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($seeker))
            ->getJson('/api/v1/van-trips/browse');

        $response->assertOk()
            ->assertJsonCount(1, 'trips')
            ->assertJsonPath('trips.0.id', $open->id);
    }

    public function test_a_client_can_reserve_seats(): void
    {
        [$origin, $destination] = $this->citiesPair();
        $driver = $this->driverWithPlan();

        $trip = VanTrip::query()->create([
            'driver_user_id' => $driver->id, 'origin_city_id' => $origin->id, 'destination_city_id' => $destination->id,
            'travel_date' => now()->addDay(), 'departure_time' => '07:00', 'total_seats' => 10, 'price_per_seat' => 10, 'status' => 'open',
        ]);
        $client = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson("/api/v1/van-trips/{$trip->id}/reservations", ['seats_reserved' => 3])
            ->assertCreated();

        $this->assertDatabaseHas('van_trip_reservations', [
            'van_trip_id' => $trip->id, 'client_user_id' => $client->id, 'seats_reserved' => 3, 'status' => 'confirmed',
        ]);
        $this->assertSame(7, $trip->fresh()->seatsAvailable());
    }

    public function test_the_driver_cannot_reserve_their_own_trip(): void
    {
        [$origin, $destination] = $this->citiesPair();
        $driver = $this->driverWithPlan();

        $trip = VanTrip::query()->create([
            'driver_user_id' => $driver->id, 'origin_city_id' => $origin->id, 'destination_city_id' => $destination->id,
            'travel_date' => now()->addDay(), 'departure_time' => '07:00', 'total_seats' => 10, 'price_per_seat' => 10, 'status' => 'open',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson("/api/v1/van-trips/{$trip->id}/reservations", ['seats_reserved' => 1])
            ->assertForbidden();
    }

    public function test_a_client_can_cancel_their_own_reservation_and_free_the_seats(): void
    {
        [$origin, $destination] = $this->citiesPair();
        $driver = $this->driverWithPlan();

        $trip = VanTrip::query()->create([
            'driver_user_id' => $driver->id, 'origin_city_id' => $origin->id, 'destination_city_id' => $destination->id,
            'travel_date' => now()->addDay(), 'departure_time' => '07:00', 'total_seats' => 10, 'price_per_seat' => 10, 'status' => 'open',
        ]);
        $client = User::factory()->create();
        $reservation = $trip->reservations()->create(['client_user_id' => $client->id, 'seats_reserved' => 4, 'status' => 'confirmed', 'reserved_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson("/api/v1/van-trip-reservations/{$reservation->id}/cancel")
            ->assertOk();

        $this->assertSame('cancelled', $reservation->fresh()->status);
        $this->assertSame(10, $trip->fresh()->seatsAvailable());
    }

    public function test_the_driver_can_cancel_their_own_trip(): void
    {
        [$origin, $destination] = $this->citiesPair();
        $driver = $this->driverWithPlan();

        $trip = VanTrip::query()->create([
            'driver_user_id' => $driver->id, 'origin_city_id' => $origin->id, 'destination_city_id' => $destination->id,
            'travel_date' => now()->addDay(), 'departure_time' => '07:00', 'total_seats' => 10, 'price_per_seat' => 10, 'status' => 'open',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson("/api/v1/van-trips/{$trip->id}/cancel")
            ->assertOk();

        $this->assertSame('cancelled', $trip->fresh()->status);
    }

    public function test_a_stranger_cannot_cancel_someone_elses_trip(): void
    {
        [$origin, $destination] = $this->citiesPair();
        $driver = $this->driverWithPlan();
        $stranger = User::factory()->create();

        $trip = VanTrip::query()->create([
            'driver_user_id' => $driver->id, 'origin_city_id' => $origin->id, 'destination_city_id' => $destination->id,
            'travel_date' => now()->addDay(), 'departure_time' => '07:00', 'total_seats' => 10, 'price_per_seat' => 10, 'status' => 'open',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/v1/van-trips/{$trip->id}/cancel")
            ->assertForbidden();
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/van-trips')->assertUnauthorized();
    }
}
