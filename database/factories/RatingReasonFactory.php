<?php

namespace Database\Factories;

use App\Models\RatingReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RatingReason>
 */
class RatingReasonFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'direction' => $this->faker->randomElement(['client_to_driver', 'driver_to_client']),
            'text' => $this->faker->sentence(3),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
