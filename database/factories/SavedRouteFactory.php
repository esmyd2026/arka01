<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SavedRouteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_user_id' => User::factory(),
            'alias' => null,
            'origin_lat' => $this->faker->latitude(-2.3, -1.9),
            'origin_lng' => $this->faker->longitude(-79.95, -79.8),
            'origin_address' => $this->faker->streetAddress(),
            'destination_lat' => $this->faker->latitude(-2.3, -1.9),
            'destination_lng' => $this->faker->longitude(-79.95, -79.8),
            'destination_address' => $this->faker->streetAddress(),
        ];
    }
}
