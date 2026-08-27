<?php

namespace Tests\Feature\Ride;

use App\Events\RideOverdueAlert;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use App\Notifications\RideOverdueSchedulePushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Bug reportado por el usuario: una carrera programada cuya hora ya pasó se
 * quedaba mostrando "Iniciar viaje" para siempre, sin ningún aviso — ver
 * App\Console\Commands\SendOverdueScheduledRideAlerts.
 */
class SendOverdueScheduledRideAlertsTest extends TestCase
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

    public function test_it_alerts_the_driver_when_a_scheduled_ride_is_overdue(): void
    {
        Event::fake([RideOverdueAlert::class]);
        Notification::fake();

        $ride = $this->scheduledRideAt(now()->subMinutes(20)->format('Y-m-d H:i:s'));

        $this->artisan('rides:send-overdue-scheduled-alerts')->assertSuccessful();

        $this->assertNotNull($ride->fresh()->overdue_alert_sent_at);
        Event::assertDispatched(RideOverdueAlert::class, fn ($event) => $event->ride->id === $ride->id);
        Notification::assertSentTo($ride->driver, RideOverdueSchedulePushNotification::class);
    }

    public function test_it_does_not_alert_twice(): void
    {
        Notification::fake();

        $ride = $this->scheduledRideAt(now()->subMinutes(20)->format('Y-m-d H:i:s'));
        $ride->update(['overdue_alert_sent_at' => now()]);

        $this->artisan('rides:send-overdue-scheduled-alerts')->assertSuccessful();

        Notification::assertNotSentTo($ride->driver, RideOverdueSchedulePushNotification::class);
    }

    /**
     * Antes de los 15 minutos de margen, todavía no se considera vencida —
     * puede ser tráfico normal, no hace falta alarmar de una.
     */
    public function test_it_ignores_rides_still_within_the_grace_window(): void
    {
        Notification::fake();

        $ride = $this->scheduledRideAt(now()->subMinutes(5)->format('Y-m-d H:i:s'));

        $this->artisan('rides:send-overdue-scheduled-alerts')->assertSuccessful();

        Notification::assertNotSentTo($ride->driver, RideOverdueSchedulePushNotification::class);
        $this->assertNull($ride->fresh()->overdue_alert_sent_at);
    }

    public function test_it_ignores_rides_that_already_started(): void
    {
        Notification::fake();

        $ride = $this->scheduledRideAt(now()->subMinutes(20)->format('Y-m-d H:i:s'));
        $ride->update(['status' => 'in_progress', 'started_at' => now()]);

        $this->artisan('rides:send-overdue-scheduled-alerts')->assertSuccessful();

        Notification::assertNotSentTo($ride->driver, RideOverdueSchedulePushNotification::class);
    }

    public function test_it_ignores_rides_still_in_the_future(): void
    {
        Notification::fake();

        $ride = $this->scheduledRideAt(now()->addHours(2)->format('Y-m-d H:i:s'));

        $this->artisan('rides:send-overdue-scheduled-alerts')->assertSuccessful();

        Notification::assertNotSentTo($ride->driver, RideOverdueSchedulePushNotification::class);
    }
}
