<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subscription_plan_id' => SubscriptionPlan::factory(),
            'status' => 'active',
            'started_at' => now(),
            'expires_at' => null,
            'activated_by' => null,
            'note' => null,
        ];
    }

    public function grace(): static
    {
        return $this->state(fn () => ['status' => 'grace']);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['status' => 'expired']);
    }
}
