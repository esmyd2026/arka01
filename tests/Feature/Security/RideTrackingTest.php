<?php

namespace Tests\Feature\Security;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Seguimiento en vivo compartible (sección 8): enlace de solo lectura, sin
 * cuenta, protegido por firma temporal en vez de login.
 */
class RideTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_ride_participant_can_generate_a_tracking_link(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($client)->getJson(route('rides.tracking-link', $ride));

        $response->assertOk();
        $this->assertStringContainsString('/seguimiento/'.$ride->public_id, $response->json('url'));
    }

    public function test_a_stranger_cannot_generate_a_tracking_link(): void
    {
        $ride = Ride::factory()->create(['status' => 'in_progress']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getJson(route('rides.tracking-link', $ride))->assertForbidden();
    }

    public function test_the_public_tracking_page_works_without_login_when_the_link_is_valid(): void
    {
        $driver = User::factory()->create(['name' => 'Pedro Chofer']);
        DriverProfile::factory()->for($driver)->create([
            'vehicle_plate' => 'ABC-123',
            'current_lat' => -0.18,
            'current_lng' => -78.47,
        ]);
        $ride = Ride::factory()->create(['driver_user_id' => $driver->id, 'status' => 'in_progress']);

        $url = URL::temporarySignedRoute('public.rides.track', now()->addHours(24), ['ride' => $ride->public_id]);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Public/RideTracking')
            ->where('ride.driver_name', 'Pedro Chofer')
            // Confidencialidad (pedido explícito del usuario): este enlace es
            // público, la placa viaja tapada — ver DriverProfile::maskedPlate().
            ->where('ride.vehicle_plate', 'Axxx23')
        );
    }

    public function test_an_expired_or_tampered_tracking_link_is_rejected(): void
    {
        $ride = Ride::factory()->create(['status' => 'in_progress']);

        // Firma vencida: cualquier momento en el pasado alcanza para que
        // ValidateSignature la rechace.
        $expiredUrl = URL::temporarySignedRoute('public.rides.track', now()->subMinute(), ['ride' => $ride->public_id]);

        $this->get($expiredUrl)->assertForbidden();
    }

    public function test_the_status_endpoint_hides_the_drivers_location_once_the_ride_is_no_longer_in_progress(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['current_lat' => -0.18, 'current_lng' => -78.47]);
        $ride = Ride::factory()->create(['driver_user_id' => $driver->id, 'status' => 'completed']);

        $url = URL::temporarySignedRoute('public.rides.track.status', now()->addHours(24), ['ride' => $ride->public_id]);

        $response = $this->getJson($url);

        $response->assertOk();
        $response->assertJsonPath('driver_lat', null);
    }

    public function test_a_signed_tracking_link_still_rejects_a_numeric_ride_id(): void
    {
        $ride = Ride::factory()->create(['status' => 'in_progress']);
        $numericUrl = URL::temporarySignedRoute('public.rides.track', now()->addHours(24), [
            'ride' => $ride->id,
        ]);

        $this->get($numericUrl)->assertNotFound();
    }
}
