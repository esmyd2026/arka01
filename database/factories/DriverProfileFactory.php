<?php

namespace Database\Factories;

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DriverProfile>
 */
class DriverProfileFactory extends Factory
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
            'license_number' => fake()->unique()->bothify('LIC-#####'),
            'driver_type' => 'independent',
            'vehicle_make' => fake()->randomElement(['Chevrolet', 'Kia', 'Toyota', 'Hyundai']),
            'vehicle_model' => fake()->word(),
            'vehicle_color' => fake()->safeColorName(),
            'vehicle_type' => 'sedan',
            'vehicle_plate' => strtoupper(fake()->bothify('???-####')),
            'vehicle_year' => fake()->numberBetween(2005, (int) date('Y')),
            'passenger_capacity' => fake()->numberBetween(1, 4),
            'has_trunk' => fake()->boolean(70),
            'rate_per_km' => fake()->randomFloat(2, 0.3, 1.5),
            'accepts_cash' => true,
            'accepts_transfer' => fake()->boolean(),
            'is_available' => true,
            // En producción, `is_available` y `location_updated_at` siempre
            // se escriben juntos (DriverLocationController::update()) — sin
            // esto, un perfil de test "disponible" quedaría "stale" desde el
            // vamos (DriverProfile::isStale()) y ningún test de despacho
            // encontraría candidatos.
            'location_updated_at' => now(),
            'is_public' => false,
            'verification_status' => 'approved',
            'identity_document_path' => 'driver-documents/test-identity.pdf',
            'license_photo_path' => 'driver-documents/test-license.jpg',
            'police_record_path' => 'driver-documents/test-police-record.pdf',
            // El código de invitación se genera solo en el evento "creating" del
            // modelo (App\Models\DriverProfile::booted), no hace falta acá.
        ];
    }
}
