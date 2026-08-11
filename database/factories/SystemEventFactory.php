<?php

namespace Database\Factories;

use App\Models\SystemEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SystemEvent>
 */
class SystemEventFactory extends Factory
{
    protected $model = SystemEvent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'severity' => 'error',
            'module' => 'whatsapp',
            'event_type' => 'whatsapp_send_failed',
            'channel' => 'whatsapp',
            'status' => 'failed',
            'message' => $this->faker->sentence(),
            'attempts' => 1,
            'last_attempt_at' => now(),
        ];
    }
}
