<?php

namespace Database\Factories;

use App\Models\DriverBankAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverBankAccount>
 */
class DriverBankAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'account_holder_name' => $this->faker->name(),
            'identity_number' => $this->faker->numerify('##########'),
            'bank_name' => $this->faker->randomElement(['Banco Pichincha', 'Banco Guayaquil', 'Produbanco', 'Banco del Pacífico']),
            'account_type' => $this->faker->randomElement(['ahorros', 'corriente']),
            'account_number' => $this->faker->numerify('##########'),
            'is_favorite' => false,
        ];
    }
}
