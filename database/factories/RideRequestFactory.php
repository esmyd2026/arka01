<?php

namespace Database\Factories;

use App\Models\Fleet;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RideRequest>
 */
class RideRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Coordenadas alrededor de Quito, a modo de dato de ejemplo razonable
        // para un proyecto pensado para Ecuador (sección 0 del alcance).
        $originLat = -0.1807 + $this->faker->randomFloat(4, -0.05, 0.05);
        $originLng = -78.4678 + $this->faker->randomFloat(4, -0.05, 0.05);

        return [
            'fleet_id' => Fleet::factory(),
            'client_user_id' => User::factory(),
            'driver_user_id' => null,
            'origin_lat' => $originLat,
            'origin_lng' => $originLng,
            'origin_address' => $this->faker->streetAddress(),
            'destination_lat' => $originLat + $this->faker->randomFloat(4, -0.03, 0.03),
            'destination_lng' => $originLng + $this->faker->randomFloat(4, -0.03, 0.03),
            'destination_address' => $this->faker->streetAddress(),
            'distance_km' => $this->faker->randomFloat(2, 1, 15),
            'status' => 'pending',
            // Precio de arranque de la negociación (sección 5): por defecto,
            // como si el cliente hubiera aceptado el sugerido tal cual.
            'current_offered_price' => $this->faker->randomFloat(2, 2, 15),
            'negotiation_round' => 0,
            'last_offer_made_by' => 'client',
            'requested_at' => now(),
        ];
    }
}
