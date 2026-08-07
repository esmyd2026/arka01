<?php

namespace Tests\Feature\Admin;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\DemandAlertPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Centro de operaciones (pedido explícito del usuario): concentración de
 * solicitudes activas, conectados, demanda por horario/zona, y avisar a
 * conductores cercanos indicando si son de su flota.
 */
class AdminOperationsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_operations(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.operations.index'))->assertForbidden();
    }

    public function test_the_index_reports_active_demand_wait_time_and_cancellations(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();
        $driver = User::factory()->create();
        $sauces = Sector::query()->where('name', 'Sauces 1')->firstOrFail();

        RideRequest::factory()->create([
            'client_user_id' => $client->id,
            'status' => 'pending',
            'origin_sector_id' => $sauces->id,
            'origin_lat' => -0.18,
            'origin_lng' => -78.46,
        ]);

        RideRequest::factory()->create([
            'client_user_id' => $client->id,
            'status' => 'accepted',
            'requested_at' => now()->subMinutes(5),
            'responded_at' => now(),
        ]);

        RideRequest::factory()->create(['client_user_id' => $client->id, 'status' => 'expired']);

        // Ride::factory() crea su propia RideRequest anidada si no se le pasa
        // una — sin fijarle un status terminal, quedaría "pending" por
        // defecto y ensuciaría el conteo de demanda activa de arriba.
        Ride::factory()->create([
            'ride_request_id' => RideRequest::factory()->create(['client_user_id' => $client->id, 'status' => 'accepted']),
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'cancelled',
            'started_at' => now(),
        ]);
        Ride::factory()->create([
            'ride_request_id' => RideRequest::factory()->create(['client_user_id' => $client->id, 'status' => 'accepted']),
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'cancelled',
            'started_at' => null,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.operations.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('activeDemand', 1)
            ->where('activeDemand.0.sector', 'Sauces 1')
            ->where('waitTime.avg_wait_minutes', fn ($value) => (float) $value === 5.0)
            ->where('waitTime.expired_count', 1)
            ->where('waitTime.cancelled_while_en_route_count', 1)
            ->where('waitTime.cancelled_before_pickup_count', 1)
        );
    }

    public function test_notify_nearby_alerts_available_drivers_within_range_and_flags_their_own_fleet(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $sauces = Sector::query()->where('name', 'Sauces 1')->firstOrFail();

        RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'status' => 'pending',
            'origin_sector_id' => $sauces->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
        ]);

        $ownFleetDriver = User::factory()->create();
        DriverProfile::factory()->for($ownFleetDriver)->create(['is_available' => true, 'current_lat' => -0.181, 'current_lng' => -78.468]);
        FleetMember::factory()->for($fleet)->for($ownFleetDriver, 'driver')->create(['added_by' => $client->id]);

        $outsideDriver = User::factory()->create();
        DriverProfile::factory()->for($outsideDriver)->create(['is_available' => true, 'current_lat' => -0.182, 'current_lng' => -78.469]);

        $farDriver = User::factory()->create();
        DriverProfile::factory()->for($farDriver)->create(['is_available' => true, 'current_lat' => -1.5, 'current_lng' => -79.5]);

        $this->actingAs($admin)
            ->post(route('admin.operations.notify-demand'), ['sector_id' => $sauces->id])
            ->assertRedirect();

        Notification::assertSentTo($ownFleetDriver, DemandAlertPushNotification::class, fn ($n) => $n->fromOwnFleet === true);
        Notification::assertSentTo($outsideDriver, DemandAlertPushNotification::class, fn ($n) => $n->fromOwnFleet === false);
        Notification::assertNotSentTo($farDriver, DemandAlertPushNotification::class);
    }

    public function test_notify_nearby_fails_without_active_demand_in_the_sector(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $sauces = Sector::query()->where('name', 'Sauces 1')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.operations.notify-demand'), ['sector_id' => $sauces->id])
            ->assertSessionHasErrors('sector_id');
    }
}
