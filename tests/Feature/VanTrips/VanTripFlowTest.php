<?php

namespace Tests\Feature\VanTrips;

use App\Models\City;
use App\Models\DriverProfile;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\VanTrip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nuevo servicio para conductores tipo VAN/buseta/microbús/turístico (pedido
 * explícito del usuario): publicar viajes programados puntuales, gateado por
 * plan, y que los clientes reserven asientos con precio fijo.
 */
class VanTripFlowTest extends TestCase
{
    use RefreshDatabase;

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

        $response = $this->actingAs($driver)->post(route('van-trips.store'), [
            'origin_city_id' => $origin->id,
            'destination_city_id' => $destination->id,
            'travel_date' => now()->addDays(3)->toDateString(),
            'departure_time' => '06:30',
            'total_seats' => 15,
            'price_per_seat' => 12.5,
            'description' => 'Salida turística',
            'included_services' => ['Aire acondicionado', 'WiFi'],
            'luggage_allowance' => '1 maleta grande por pasajero',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('van_trips', [
            'driver_user_id' => $driver->id,
            'origin_city_id' => $origin->id,
            'destination_city_id' => $destination->id,
            'total_seats' => 15,
            'status' => 'open',
        ]);
    }

    // Punto exacto de salida/llegada (pedido explícito del usuario): opcional,
    // sirve para calcular la distancia real y sugerir un costo aproximado.

    public function test_publishing_with_exact_coordinates_stores_the_calculated_distance(): void
    {
        [$origin, $destination] = $this->citiesPair();
        $driver = $this->driverWithPlan('pro');

        $response = $this->actingAs($driver)->post(route('van-trips.store'), [
            'origin_city_id' => $origin->id,
            'destination_city_id' => $destination->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'origin_address' => 'Quito centro',
            'destination_lat' => -2.1894,
            'destination_lng' => -79.8890,
            'destination_address' => 'Guayaquil centro',
            'travel_date' => now()->addDays(3)->toDateString(),
            'departure_time' => '06:30',
            'total_seats' => 15,
            'price_per_seat' => 12.5,
        ]);

        $response->assertRedirect();

        $trip = VanTrip::query()->where('driver_user_id', $driver->id)->firstOrFail();
        $this->assertNotNull($trip->distance_km);
        // Quito-Guayaquil en línea recta ronda los 250 km — alcanza con
        // confirmar que se calculó algo razonable, no el número exacto.
        $this->assertGreaterThan(200, $trip->distance_km);
        $this->assertLessThan(300, $trip->distance_km);
        $this->assertSame('Quito centro', $trip->origin_address);
    }

    public function test_publishing_without_coordinates_still_works_and_leaves_distance_null(): void
    {
        [$origin, $destination] = $this->citiesPair();
        $driver = $this->driverWithPlan('pro');

        $this->actingAs($driver)->post(route('van-trips.store'), [
            'origin_city_id' => $origin->id,
            'destination_city_id' => $destination->id,
            'travel_date' => now()->addDays(3)->toDateString(),
            'departure_time' => '06:30',
            'total_seats' => 15,
            'price_per_seat' => 12.5,
        ])->assertRedirect();

        $trip = VanTrip::query()->where('driver_user_id', $driver->id)->firstOrFail();
        $this->assertNull($trip->distance_km);
    }

    public function test_publishing_with_only_half_a_coordinate_pair_fails_validation(): void
    {
        [$origin, $destination] = $this->citiesPair();
        $driver = $this->driverWithPlan('pro');

        $this->actingAs($driver)->post(route('van-trips.store'), [
            'origin_city_id' => $origin->id,
            'destination_city_id' => $destination->id,
            'origin_lat' => -0.1807,
            // Falta origin_lng a propósito.
            'travel_date' => now()->addDays(3)->toDateString(),
            'departure_time' => '06:30',
            'total_seats' => 15,
            'price_per_seat' => 12.5,
        ])->assertSessionHasErrors('origin_lng');

        $this->assertDatabaseCount('van_trips', 0);
    }

    public function test_the_index_screen_exposes_the_drivers_rate_per_km_for_the_estimate(): void
    {
        $driver = $this->driverWithPlan('pro');
        $driver->driverProfile()->update(['rate_per_km' => 0.42]);

        $response = $this->actingAs($driver)->get(route('van-trips.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('driverRatePerKm', '0.42')
        );
    }

    public function test_a_driver_without_the_plan_feature_cannot_publish(): void
    {
        [$origin, $destination] = $this->citiesPair();
        $driver = $this->driverWithPlan('basico'); // plan sin van_trips_enabled

        $this->actingAs($driver)->post(route('van-trips.store'), [
            'origin_city_id' => $origin->id,
            'destination_city_id' => $destination->id,
            'travel_date' => now()->addDays(3)->toDateString(),
            'departure_time' => '06:30',
            'total_seats' => 15,
            'price_per_seat' => 12.5,
        ])->assertSessionHasErrors('trip');

        $this->assertDatabaseCount('van_trips', 0);
    }

    public function test_a_plain_client_cannot_publish_a_trip(): void
    {
        [$origin, $destination] = $this->citiesPair();
        $client = User::factory()->create();

        $this->actingAs($client)->post(route('van-trips.store'), [
            'origin_city_id' => $origin->id,
            'destination_city_id' => $destination->id,
            'travel_date' => now()->addDays(3)->toDateString(),
            'departure_time' => '06:30',
            'total_seats' => 15,
            'price_per_seat' => 12.5,
        ])->assertSessionHasErrors('trip');
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

        VanTrip::query()->create([
            'driver_user_id' => $driver->id, 'origin_city_id' => $origin->id, 'destination_city_id' => $destination->id,
            'travel_date' => now()->addDay(), 'departure_time' => '08:00', 'total_seats' => 10, 'price_per_seat' => 10, 'status' => 'cancelled',
        ]);

        $seeker = User::factory()->create();
        $response = $this->actingAs($seeker)->get(route('van-trips.browse'));

        $response->assertInertia(fn ($page) => $page
            ->component('VanTrips/Browse')
            ->has('trips', 1)
            ->where('trips.0.id', $open->id)
        );
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

        $this->actingAs($client)
            ->post(route('van-trip-reservations.store', $trip), ['seats_reserved' => 3])
            ->assertRedirect();

        $this->assertDatabaseHas('van_trip_reservations', [
            'van_trip_id' => $trip->id, 'client_user_id' => $client->id, 'seats_reserved' => 3, 'status' => 'confirmed',
        ]);
        $this->assertSame(7, $trip->fresh()->seatsAvailable());
    }

    public function test_cannot_reserve_more_seats_than_available(): void
    {
        [$origin, $destination] = $this->citiesPair();
        $driver = $this->driverWithPlan();

        $trip = VanTrip::query()->create([
            'driver_user_id' => $driver->id, 'origin_city_id' => $origin->id, 'destination_city_id' => $destination->id,
            'travel_date' => now()->addDay(), 'departure_time' => '07:00', 'total_seats' => 2, 'price_per_seat' => 10, 'status' => 'open',
        ]);

        $client = User::factory()->create();

        $this->actingAs($client)
            ->post(route('van-trip-reservations.store', $trip), ['seats_reserved' => 3])
            ->assertSessionHasErrors('seats_reserved');

        $this->assertDatabaseCount('van_trip_reservations', 0);
    }

    public function test_the_driver_cannot_reserve_their_own_trip(): void
    {
        [$origin, $destination] = $this->citiesPair();
        $driver = $this->driverWithPlan();

        $trip = VanTrip::query()->create([
            'driver_user_id' => $driver->id, 'origin_city_id' => $origin->id, 'destination_city_id' => $destination->id,
            'travel_date' => now()->addDay(), 'departure_time' => '07:00', 'total_seats' => 10, 'price_per_seat' => 10, 'status' => 'open',
        ]);

        $this->actingAs($driver)
            ->post(route('van-trip-reservations.store', $trip), ['seats_reserved' => 1])
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

        $this->actingAs($client)->post(route('van-trip-reservations.cancel', $reservation))->assertRedirect();

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

        $this->actingAs($driver)->post(route('van-trips.cancel', $trip))->assertRedirect();

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

        $this->actingAs($stranger)->post(route('van-trips.cancel', $trip))->assertForbidden();
    }
}
