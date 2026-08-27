<?php

namespace Tests\Feature\Ride;

use App\Events\RideRequestExpired;
use App\Models\Fleet;
use App\Models\RideRequest;
use App\Models\User;
use App\Notifications\ScheduledRideRequestExpiredPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Bug reportado por el usuario: una solicitud programada que ningún
 * conductor aceptó se quedaba en 'pending' para siempre aunque la hora
 * pedida ya hubiera pasado — ver App\Console\Commands\ExpireOverdueScheduledRideRequests.
 */
class ExpireOverdueScheduledRideRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));
    }

    private function pendingRequest(array $overrides = []): RideRequest
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        return RideRequest::factory()->create(array_merge([
            'client_user_id' => $client->id,
            'fleet_id' => $fleet->id,
            'status' => 'pending',
            'is_scheduled' => true,
            'scheduled_at' => now()->subMinutes(10)->format('Y-m-d H:i:s'),
        ], $overrides));
    }

    public function test_it_expires_a_scheduled_request_nobody_accepted(): void
    {
        Event::fake([RideRequestExpired::class]);
        Notification::fake();

        $rideRequest = $this->pendingRequest();

        $this->artisan('rides:expire-overdue-scheduled-requests')->assertSuccessful();

        $fresh = $rideRequest->fresh();
        $this->assertSame('expired', $fresh->status);
        $this->assertNotNull($fresh->responded_at);
        Event::assertDispatched(RideRequestExpired::class, fn ($event) => $event->rideRequest->id === $rideRequest->id);
        Notification::assertSentTo($rideRequest->client, ScheduledRideRequestExpiredPushNotification::class);
    }

    public function test_it_ignores_scheduled_requests_still_in_the_future(): void
    {
        Notification::fake();

        $rideRequest = $this->pendingRequest(['scheduled_at' => now()->addHours(2)->format('Y-m-d H:i:s')]);

        $this->artisan('rides:expire-overdue-scheduled-requests')->assertSuccessful();

        $this->assertSame('pending', $rideRequest->fresh()->status);
        Notification::assertNotSentTo($rideRequest->client, ScheduledRideRequestExpiredPushNotification::class);
    }

    public function test_it_ignores_requests_that_are_no_longer_pending(): void
    {
        Notification::fake();

        $rideRequest = $this->pendingRequest(['status' => 'accepted']);

        $this->artisan('rides:expire-overdue-scheduled-requests')->assertSuccessful();

        $this->assertSame('accepted', $rideRequest->fresh()->status);
        Notification::assertNotSentTo($rideRequest->client, ScheduledRideRequestExpiredPushNotification::class);
    }

    /**
     * Bug encontrado en una auditoría del flujo completo (pedido explícito
     * del usuario): un conductor puede contraofertar una solicitud
     * programada (RideRequestController::counter(), no distingue programada
     * de inmediata) — antes, si el cliente nunca respondía esa contraoferta
     * antes de la hora pedida, se quedaba en 'negotiating' para siempre,
     * invisible para este comando (solo miraba 'pending').
     */
    public function test_it_also_expires_a_negotiating_scheduled_request_nobody_resolved(): void
    {
        Event::fake([RideRequestExpired::class]);
        Notification::fake();

        $rideRequest = $this->pendingRequest(['status' => 'negotiating']);

        $this->artisan('rides:expire-overdue-scheduled-requests')->assertSuccessful();

        $fresh = $rideRequest->fresh();
        $this->assertSame('expired', $fresh->status);
        Event::assertDispatched(RideRequestExpired::class, fn ($event) => $event->rideRequest->id === $rideRequest->id);
        Notification::assertSentTo($rideRequest->client, ScheduledRideRequestExpiredPushNotification::class);
    }

    public function test_it_ignores_immediate_non_scheduled_requests(): void
    {
        Notification::fake();

        $rideRequest = $this->pendingRequest(['is_scheduled' => false, 'scheduled_at' => null]);

        $this->artisan('rides:expire-overdue-scheduled-requests')->assertSuccessful();

        $this->assertSame('pending', $rideRequest->fresh()->status);
        Notification::assertNotSentTo($rideRequest->client, ScheduledRideRequestExpiredPushNotification::class);
    }
}
