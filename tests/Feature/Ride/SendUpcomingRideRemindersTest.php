<?php

namespace Tests\Feature\Ride;

use App\Events\RideReminderDue;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use App\Notifications\RideScheduledReminderPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: avisarle al conductor 15-20 min antes de
 * una carrera programada — ver App\Console\Commands\SendUpcomingRideReminders.
 */
class SendUpcomingRideRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));
    }

    private function scheduledRideAt(string $scheduledAt): Ride
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $rideRequest = RideRequest::factory()->create([
            'client_user_id' => $client->id,
            'fleet_id' => $fleet->id,
            'is_scheduled' => true,
            'scheduled_at' => $scheduledAt,
            'status' => 'accepted',
        ]);

        return Ride::factory()->create([
            'ride_request_id' => $rideRequest->id,
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'scheduled',
            'started_at' => null,
        ]);
    }

    public function test_it_sends_a_reminder_for_a_ride_scheduled_in_15_to_20_minutes(): void
    {
        Event::fake([RideReminderDue::class]);
        Notification::fake();

        $ride = $this->scheduledRideAt(now()->addMinutes(17)->format('Y-m-d H:i:s'));

        $this->artisan('rides:send-upcoming-reminders')->assertSuccessful();

        $this->assertNotNull($ride->fresh()->driver_reminder_sent_at);
        Event::assertDispatched(RideReminderDue::class, fn ($event) => $event->ride->id === $ride->id);
        Notification::assertSentTo($ride->driver, RideScheduledReminderPushNotification::class);
    }

    public function test_it_does_not_send_a_reminder_twice(): void
    {
        Notification::fake();

        $ride = $this->scheduledRideAt(now()->addMinutes(17)->format('Y-m-d H:i:s'));
        $ride->update(['driver_reminder_sent_at' => now()]);

        $this->artisan('rides:send-upcoming-reminders')->assertSuccessful();

        Notification::assertNotSentTo($ride->driver, RideScheduledReminderPushNotification::class);
    }

    public function test_it_ignores_rides_outside_the_reminder_window(): void
    {
        Notification::fake();

        $tooSoon = $this->scheduledRideAt(now()->addMinutes(5)->format('Y-m-d H:i:s'));
        $tooFar = $this->scheduledRideAt(now()->addHours(2)->format('Y-m-d H:i:s'));

        $this->artisan('rides:send-upcoming-reminders')->assertSuccessful();

        Notification::assertNotSentTo($tooSoon->driver, RideScheduledReminderPushNotification::class);
        Notification::assertNotSentTo($tooFar->driver, RideScheduledReminderPushNotification::class);
        $this->assertNull($tooSoon->fresh()->driver_reminder_sent_at);
        $this->assertNull($tooFar->fresh()->driver_reminder_sent_at);
    }

    public function test_it_ignores_rides_that_already_started(): void
    {
        Notification::fake();

        $ride = $this->scheduledRideAt(now()->addMinutes(17)->format('Y-m-d H:i:s'));
        $ride->update(['status' => 'in_progress', 'started_at' => now()]);

        $this->artisan('rides:send-upcoming-reminders')->assertSuccessful();

        Notification::assertNotSentTo($ride->driver, RideScheduledReminderPushNotification::class);
    }
}
