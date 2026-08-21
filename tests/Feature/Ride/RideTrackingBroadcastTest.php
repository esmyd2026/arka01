<?php

namespace Tests\Feature\Ride;

use App\Events\DriverLocationUpdated;
use App\Events\RideArrived;
use App\Events\RideCancelled;
use App\Events\RideCompleted;
use App\Events\RidePickedUp;
use App\Events\RideStarted;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use App\Notifications\RideArrivedPushNotification;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RideTrackingBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_cooperative_driver_location_is_broadcast_to_the_active_ride_channel(): void
    {
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create([
            'current_lat' => -2.1376,
            'current_lng' => -79.8942,
        ]);
        $ride = Ride::factory()->create([
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $channelNames = collect((new DriverLocationUpdated($profile))->broadcastOn())
            ->pluck('name');

        $this->assertTrue($channelNames->contains("private-ride.{$ride->id}"));
        $this->assertInstanceOf(ShouldBroadcastNow::class, new DriverLocationUpdated($profile));
    }

    public function test_driver_can_update_location_during_a_ride_without_changing_availability(): void
    {
        Event::fake([DriverLocationUpdated::class]);

        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create(['is_available' => false]);
        $ride = Ride::factory()->create([
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($driver)
            ->postJson(route('rides.location.update', $ride), [
                'lat' => -2.145,
                'lng' => -79.895,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $profile->refresh();
        $this->assertEqualsWithDelta(-2.145, (float) $profile->current_lat, 0.000001);
        $this->assertEqualsWithDelta(-79.895, (float) $profile->current_lng, 0.000001);
        $this->assertFalse($profile->is_available);
        Event::assertDispatched(DriverLocationUpdated::class);
    }

    public function test_location_ping_marks_arrival_and_notifies_client_when_driver_reaches_pickup(): void
    {
        Event::fake([DriverLocationUpdated::class, RideArrived::class]);
        Notification::fake();

        $driver = User::factory()->create();
        $client = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['is_available' => false]);
        $ride = Ride::factory()->create([
            'driver_user_id' => $driver->id,
            'client_user_id' => $client->id,
            'status' => 'in_progress',
            'origin_lat' => -2.1376,
            'origin_lng' => -79.8942,
            'arrived_at' => null,
            'picked_up_at' => null,
        ]);

        $this->actingAs($driver)
            ->postJson(route('rides.location.update', $ride), [
                'lat' => -2.1377,
                'lng' => -79.8942,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJson(fn ($json) => $json->whereType('arrived_at', 'string')->etc());

        $this->assertNotNull($ride->fresh()->arrived_at);
        Event::assertDispatched(RideArrived::class, fn (RideArrived $event) => $event->ride->is($ride));
        Notification::assertSentTo($client, RideArrivedPushNotification::class);
    }

    public function test_location_ping_does_not_mark_arrival_while_driver_is_far_from_pickup(): void
    {
        Event::fake([DriverLocationUpdated::class, RideArrived::class]);

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $ride = Ride::factory()->create([
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
            'origin_lat' => -2.1376,
            'origin_lng' => -79.8942,
            'arrived_at' => null,
        ]);

        $this->actingAs($driver)
            ->postJson(route('rides.location.update', $ride), [
                'lat' => -2.1600,
                'lng' => -79.9100,
            ])
            ->assertOk()
            ->assertJsonPath('arrived_at', null);

        $this->assertNull($ride->fresh()->arrived_at);
        Event::assertNotDispatched(RideArrived::class);
    }

    public function test_location_updates_are_rejected_after_the_ride_finishes(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $ride = Ride::factory()->create([
            'driver_user_id' => $driver->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $this->actingAs($driver)
            ->postJson(route('rides.location.update', $ride), [
                'lat' => -2.145,
                'lng' => -79.895,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ride');
    }

    public function test_picked_up_state_is_broadcast_to_the_ride_channel(): void
    {
        $ride = Ride::factory()->create(['status' => 'in_progress']);

        $channelNames = collect((new RidePickedUp($ride))->broadcastOn())
            ->pluck('name');

        $this->assertTrue($channelNames->contains("private-ride.{$ride->id}"));
    }

    public function test_active_ride_lifecycle_events_are_broadcast_immediately(): void
    {
        $ride = Ride::factory()->create(['status' => 'in_progress']);

        foreach ([
            new RideStarted($ride),
            new RideArrived($ride),
            new RidePickedUp($ride),
            new RideCompleted($ride),
        ] as $event) {
            $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
            $this->assertTrue(
                collect($event->broadcastOn())->pluck('name')->contains("private-ride.{$ride->id}"),
            );
        }
    }

    public function test_cancellation_is_broadcast_immediately_to_the_ride_channel(): void
    {
        $ride = Ride::factory()->create(['status' => 'cancelled']);
        $event = new RideCancelled($ride);

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
        $this->assertTrue(collect($event->broadcastOn())->pluck('name')->contains("private-ride.{$ride->id}"));
    }
}
