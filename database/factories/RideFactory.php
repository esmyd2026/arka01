<?php

namespace Database\Factories;

use App\Models\Fleet;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ride>
 */
class RideFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $originLat = -0.1807 + $this->faker->randomFloat(4, -0.05, 0.05);
        $originLng = -78.4678 + $this->faker->randomFloat(4, -0.05, 0.05);
        $distanceKm = $this->faker->randomFloat(2, 1, 15);
        $ratePerKm = $this->faker->randomFloat(2, 0.3, 1.5);

        return [
            'ride_request_id' => RideRequest::factory(),
            'fleet_id' => Fleet::factory(),
            'client_user_id' => User::factory(),
            'driver_user_id' => User::factory(),
            'origin_lat' => $originLat,
            'origin_lng' => $originLng,
            'origin_address' => $this->faker->streetAddress(),
            'destination_lat' => $originLat + $this->faker->randomFloat(4, -0.03, 0.03),
            'destination_lng' => $originLng + $this->faker->randomFloat(4, -0.03, 0.03),
            'destination_address' => $this->faker->streetAddress(),
            'distance_km' => $distanceKm,
            'rate_per_km_snapshot' => $ratePerKm,
            'price' => round($distanceKm * $ratePerKm, 2),
            'status' => 'in_progress',
            'started_at' => now(),
        ];
    }
}
