<?php

namespace Database\Factories;

use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FleetMember>
 */
class FleetMemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fleet_id' => Fleet::factory(),
            'driver_user_id' => User::factory(),
            'added_by' => User::factory(),
            'joined_at' => now(),
            'left_at' => null,
        ];
    }
}
