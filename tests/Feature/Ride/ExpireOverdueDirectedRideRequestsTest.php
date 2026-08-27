<?php

namespace Tests\Feature\Ride;

use App\Events\RideRequestExpired;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\RideRequest;
use App\Models\User;
use App\Notifications\RideRequestExpiredPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Bug encontrado en una auditoría del flujo completo (pedido explícito del
 * usuario: "revisa todo el flujo de una carrera... las que son solicitadas
 * a un conductor en especifico"): una solicitud INMEDIATA dirigida a un
 * conductor puntual nunca vencía — ver
 * App\Console\Commands\ExpireOverdueDirectedRideRequests.
 */
class ExpireOverdueDirectedRideRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));
    }

    private function directedRequest(array $overrides = []): RideRequest
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        return RideRequest::factory()->create(array_merge([
            'client_user_id' => $client->id,
            'fleet_id' => $fleet->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
            'is_scheduled' => false,
            'dispatch_pool' => null,
            'cooperative_id' => null,
            'current_offer_expires_at' => now()->subMinute(),
        ], $overrides));
    }

    public function test_it_expires_a_directed_request_the_driver_never_answered(): void
    {
        Event::fake([RideRequestExpired::class]);
        Notification::fake();

        $rideRequest = $this->directedRequest();

        $this->artisan('rides:expire-overdue-directed-requests')->assertSuccessful();

        $fresh = $rideRequest->fresh();
        $this->assertSame('expired', $fresh->status);
        $this->assertNotNull($fresh->responded_at);
        Event::assertDispatched(RideRequestExpired::class, fn ($event) => $event->rideRequest->id === $rideRequest->id);
        Notification::assertSentTo($rideRequest->client, RideRequestExpiredPushNotification::class);
    }

    public function test_it_ignores_requests_still_within_their_time_window(): void
    {
        Notification::fake();

        $rideRequest = $this->directedRequest(['current_offer_expires_at' => now()->addMinutes(2)]);

        $this->artisan('rides:expire-overdue-directed-requests')->assertSuccessful();

        $this->assertSame('pending', $rideRequest->fresh()->status);
        Notification::assertNotSentTo($rideRequest->client, RideRequestExpiredPushNotification::class);
    }

    public function test_it_ignores_requests_that_are_no_longer_pending(): void
    {
        Notification::fake();

        $rideRequest = $this->directedRequest(['status' => 'accepted']);

        $this->artisan('rides:expire-overdue-directed-requests')->assertSuccessful();

        $this->assertSame('accepted', $rideRequest->fresh()->status);
        Notification::assertNotSentTo($rideRequest->client, RideRequestExpiredPushNotification::class);
    }

    /** La bolsa (fleet/public/both) ya tiene su propio mecanismo (el Job con delay) — este comando no debe tocarla. */
    public function test_it_ignores_pool_based_requests(): void
    {
        Notification::fake();

        $rideRequest = $this->directedRequest(['dispatch_pool' => 'fleet']);

        $this->artisan('rides:expire-overdue-directed-requests')->assertSuccessful();

        $this->assertSame('pending', $rideRequest->fresh()->status);
        Notification::assertNotSentTo($rideRequest->client, RideRequestExpiredPushNotification::class);
    }

    /** Una programada, aunque esté dirigida, tampoco pasa por acá (no tiene vencimiento). */
    public function test_it_ignores_scheduled_requests(): void
    {
        Notification::fake();

        $rideRequest = $this->directedRequest(['is_scheduled' => true, 'scheduled_at' => now()->addDay()]);

        $this->artisan('rides:expire-overdue-directed-requests')->assertSuccessful();

        $this->assertSame('pending', $rideRequest->fresh()->status);
        Notification::assertNotSentTo($rideRequest->client, RideRequestExpiredPushNotification::class);
    }

    public function test_it_ignores_requests_that_never_got_a_timer(): void
    {
        Notification::fake();

        $rideRequest = $this->directedRequest(['current_offer_expires_at' => null]);

        $this->artisan('rides:expire-overdue-directed-requests')->assertSuccessful();

        $this->assertSame('pending', $rideRequest->fresh()->status);
        Notification::assertNotSentTo($rideRequest->client, RideRequestExpiredPushNotification::class);
    }
}
