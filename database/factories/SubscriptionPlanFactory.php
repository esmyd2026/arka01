<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_type' => 'driver',
            'code' => $this->faker->unique()->word(),
            'name' => $this->faker->words(2, true),
            'monthly_price' => 0,
            'max_clients' => 3,
            'public_visibility' => false,
            'priority_listing' => false,
            'verified_badge' => false,
            'max_fleets' => null,
            'max_drivers_per_fleet' => null,
            'sort_order' => 1,
        ];
    }

    /**
     * Variante de plan para clientes (limita flotas, no clientes de confianza).
     */
    public function forClients(): static
    {
        return $this->state(fn () => [
            'owner_type' => 'client',
            'max_clients' => null,
            'max_fleets' => 1,
            'max_drivers_per_fleet' => 20,
        ]);
    }
}
