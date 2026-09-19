<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\NotifyDriverDisconnectedByWhatsApp;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Conectarse/desconectarse y publicar ubicación desde la app móvil (roadmap
 * Hito 5) — reusa App\Services\Driver\DriverAvailabilityUpdater, el mismo
 * servicio que ya cubre tests/Feature/Ride/VehicleCapacityTest.php y
 * tests/Feature/WhatsApp/WhatsAppDisconnectAlertTest.php, así que estos
 * tests se enfocan en el contrato JSON y lo específico del canal móvil.
 */
class MobileDriverLocationTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_a_driver_with_a_complete_profile_can_connect(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => false]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/driver/location', ['lat' => -2.19, 'lng' => -79.89, 'is_available' => true]);

        $response->assertOk()->assertJsonPath('driver.is_available', true);
        $this->assertDatabaseHas('driver_profiles', ['user_id' => $driver->id, 'is_available' => true]);
    }

    public function test_a_driver_with_an_incomplete_profile_cannot_connect(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => false, 'verification_status' => 'pending']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/driver/location', ['lat' => -2.19, 'lng' => -79.89, 'is_available' => true]);

        $response->assertForbidden();
        $this->assertDatabaseHas('driver_profiles', ['user_id' => $driver->id, 'is_available' => false]);
    }

    public function test_a_driver_with_an_incomplete_profile_can_still_disconnect(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => true, 'verification_status' => 'pending']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/driver/location', ['lat' => -2.19, 'lng' => -79.89, 'is_available' => false]);

        $response->assertOk();
        $this->assertDatabaseHas('driver_profiles', ['user_id' => $driver->id, 'is_available' => false]);
    }

    public function test_disconnecting_dispatches_the_whatsapp_disconnect_alert(): void
    {
        Queue::fake();

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => true]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->postJson('/api/v1/driver/location', ['lat' => -2.19, 'lng' => -79.89, 'is_available' => false])
            ->assertOk();

        Queue::assertPushed(NotifyDriverDisconnectedByWhatsApp::class);
    }

    public function test_a_client_without_a_driver_profile_cannot_update_location(): void
    {
        $client = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->postJson('/api/v1/driver/location', ['lat' => -2.19, 'lng' => -79.89, 'is_available' => true]);

        $response->assertForbidden();
    }

    public function test_it_requires_a_token(): void
    {
        $this->postJson('/api/v1/driver/location', [])->assertUnauthorized();
        $this->getJson('/api/v1/driver/status')->assertUnauthorized();
    }

    public function test_the_status_endpoint_reports_whether_the_driver_can_connect(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['verification_status' => 'pending']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($driver))
            ->getJson('/api/v1/driver/status');

        $response->assertOk()
            ->assertJsonPath('driver.can_connect', false)
            ->assertJsonPath(
                'driver.connection_block_reason',
                'Su información está pendiente de aprobación administrativa. Le avisaremos cuando pueda conectarse.'
            );
    }

    public function test_the_status_endpoint_reports_a_client_without_a_driver_profile(): void
    {
        $client = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($client))
            ->getJson('/api/v1/driver/status');

        $response->assertForbidden();
    }
}
