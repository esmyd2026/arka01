<?php

namespace Tests\Feature;

use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\DriverProfile;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CooperativeModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cooperative_account_is_created_from_registration(): void
    {
        $response = $this->post('/register', [
            'account_type' => 'cooperativa',
            'name' => 'Cooperativa Norte',
            'email' => 'cooperativa@example.com',
            'country_code' => '+593',
            'phone_local' => '997654321',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $user = User::query()->where('email', 'cooperativa@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->fresh()->isCooperative());
        $this->assertDatabaseHas('cooperatives', ['user_id' => $user->id, 'status' => 'pending']);
        $response->assertRedirect(route('cooperative.profile.edit'));
    }

    public function test_driver_must_accept_a_cooperative_invitation(): void
    {
        Notification::fake();

        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $cooperativeUser->id,
            'name' => 'Coop Uno',
        ]);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $driver = User::factory()->create();
        DriverProfile::factory()->create(['user_id' => $driver->id, 'driver_type' => 'independent']);

        $this->actingAs($cooperativeUser)
            ->post(route('cooperative.drivers.invite'), ['driver_user_id' => $driver->id])
            ->assertRedirect();

        $membership = CooperativeDriverMembership::query()->firstOrFail();
        $this->assertSame('pending', $membership->status);

        $this->actingAs($driver)
            ->post(route('cooperative-driver-invitations.respond', $membership), ['decision' => 'accept'])
            ->assertRedirect();

        $this->assertSame('accepted', $membership->fresh()->status);
        $this->assertSame('public_transport', $driver->driverProfile->fresh()->driver_type);
    }

    public function test_a_client_can_request_a_ride_from_an_attached_cooperative(): void
    {
        Bus::fake();
        Notification::fake();

        $client = User::factory()->create();
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $cooperativeUser->id,
            'name' => 'Coop Centro',
            'response_timeout_seconds' => 45,
        ]);
        $cooperative->forceFill(['status' => 'approved'])->save();
        ClientCooperative::query()->create(['client_user_id' => $client->id, 'cooperative_id' => $cooperative->id]);

        $driver = User::factory()->create();
        DriverProfile::factory()->create([
            'user_id' => $driver->id,
            'driver_type' => 'public_transport',
            'current_lat' => -2.1700,
            'current_lng' => -79.9000,
            'passenger_capacity' => 4,
        ]);
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeUser->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $response = $this->actingAs($client)->post(route('ride-requests.store'), [
            'cooperative_id' => $cooperative->id,
            'origin_lat' => -2.1701,
            'origin_lng' => -79.9001,
            'origin_address' => 'Origen de prueba',
            'destination_lat' => -2.1800,
            'destination_lng' => -79.9100,
            'destination_address' => 'Destino de prueba',
            'passenger_count' => 1,
            'needs_trunk' => false,
            'payment_method' => 'efectivo',
        ]);

        $response->assertRedirect(route('rides.index'));
        $rideRequest = RideRequest::query()->latest('id')->firstOrFail();
        $this->assertSame($cooperative->id, $rideRequest->cooperative_id);
        $this->assertSame($driver->id, $rideRequest->driver_user_id);
        $this->assertSame('cooperative', $rideRequest->dispatch_pool);
        $this->assertSame('awaiting_driver', $rideRequest->cooperative_assignment_status);
        $this->assertNotNull($rideRequest->cooperative_offer_expires_at);
    }

    public function test_a_client_cannot_request_from_an_unattached_cooperative(): void
    {
        $client = User::factory()->create();
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Ajena']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'cooperative_id' => $cooperative->id,
            'origin_lat' => -2.17,
            'origin_lng' => -79.90,
            'destination_lat' => -2.18,
            'destination_lng' => -79.91,
        ])->assertSessionHasErrors('cooperative_id');

        $this->assertDatabaseCount('ride_requests', 0);
    }
}
