<?php

namespace Tests\Feature\Ride;

use App\Events\DriverLocationUpdated;
use App\Events\RidePickedUp;
use App\Events\RideCancelled;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
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
    }

    public function test_picked_up_state_is_broadcast_to_the_ride_channel(): void
    {
        $ride = Ride::factory()->create(['status' => 'in_progress']);

        $channelNames = collect((new RidePickedUp($ride))->broadcastOn())
            ->pluck('name');

        $this->assertTrue($channelNames->contains("private-ride.{$ride->id}"));
    }

    public function test_cancellation_is_broadcast_immediately_to_the_ride_channel(): void
    {
        $ride = Ride::factory()->create(['status' => 'cancelled']);
        $event = new RideCancelled($ride);

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
        $this->assertTrue(collect($event->broadcastOn())->pluck('name')->contains("private-ride.{$ride->id}"));
    }
}
