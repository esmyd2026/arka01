<?php

namespace Database\Factories;

use App\Models\WhatsAppSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppSetting>
 */
class WhatsAppSettingFactory extends Factory
{
    protected $model = WhatsAppSetting::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token' => $this->faker->uuid(),
            'phone_number_id' => (string) $this->faker->numberBetween(100000000, 999999999),
            'verification_template' => 'verificacion_arka01',
            'business_number' => '593'.$this->faker->numerify('#########'),
            'webhook_verify_token' => $this->faker->uuid(),
            'app_secret' => $this->faker->sha256(),
        ];
    }
}
