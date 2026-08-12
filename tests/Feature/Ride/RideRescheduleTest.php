<?php

namespace Tests\Feature\Ride;

use App\Events\RideRescheduleProposed;
use App\Events\RideRescheduleResponded;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: poder editar una carrera programada si el
 * cliente se equivocó de fecha/hora, y que el conductor tenga que
 * confirmarla de nuevo — no queda aplicada sola.
 */
class RideRescheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));
    }

    /**
     * @return array{0: User, 1: User, 2: Fleet, 3: Ride}
     */
    private function scheduledRide(): array
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
            'scheduled_at' => '2026-01-16 08:00:00',
            'status' => 'accepted',
        ]);

        $ride = Ride::factory()->create([
            'ride_request_id' => $rideRequest->id,
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'scheduled',
            'started_at' => null,
        ]);

        return [$client, $driver, $fleet, $ride];
    }

    public function test_client_can_propose_a_new_schedule_and_it_does_not_apply_on_its_own(): void
    {
        Event::fake([RideRescheduleProposed::class]);

        [$client, , , $ride] = $this->scheduledRide();

        $this->actingAs($client)
            ->post(route('rides.reschedule.propose', $ride), [
                'scheduled_date' => '2026-01-17',
                'scheduled_time' => '09:30',
            ])
            ->assertRedirect();

        $ride->refresh();
        $this->assertSame('2026-01-17 09:30:00', $ride->pending_reschedule_at->format('Y-m-d H:i:s'));
        // La fecha real de la carrera NO cambia hasta que el conductor confirme.
        $this->assertSame('2026-01-16 08:00:00', $ride->rideRequest->scheduled_at->format('Y-m-d H:i:s'));

        Event::assertDispatched(RideRescheduleProposed::class, fn ($event) => $event->ride->id === $ride->id);
    }

    public function test_driver_can_confirm_the_proposed_schedule(): void
    {
        Event::fake([RideRescheduleResponded::class]);

        [$client, $driver, , $ride] = $this->scheduledRide();

        $this->actingAs($client)->post(route('rides.reschedule.propose', $ride), [
            'scheduled_date' => '2026-01-17',
            'scheduled_time' => '09:30',
        ]);

        $this->actingAs($driver)
            ->post(route('rides.reschedule.confirm', $ride))
            ->assertRedirect();

        $ride->refresh();
        $this->assertNull($ride->pending_reschedule_at);
        $this->assertSame('2026-01-17 09:30:00', $ride->rideRequest->scheduled_at->format('Y-m-d H:i:s'));

        Event::assertDispatched(RideRescheduleResponded::class, fn ($event) => $event->confirmed === true);
    }

    public function test_driver_can_reject_the_proposed_schedule_keeping_the_original(): void
    {
        [$client, $driver, , $ride] = $this->scheduledRide();

        $this->actingAs($client)->post(route('rides.reschedule.propose', $ride), [
            'scheduled_date' => '2026-01-17',
            'scheduled_time' => '09:30',
        ]);

        $this->actingAs($driver)
            ->post(route('rides.reschedule.reject', $ride))
            ->assertRedirect();

        $ride->refresh();
        $this->assertNull($ride->pending_reschedule_at);
        $this->assertSame('2026-01-16 08:00:00', $ride->rideRequest->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_the_driver_cannot_propose_a_reschedule(): void
    {
        [, $driver, , $ride] = $this->scheduledRide();

        $this->actingAs($driver)
            ->post(route('rides.reschedule.propose', $ride), [
                'scheduled_date' => '2026-01-17',
                'scheduled_time' => '09:30',
            ])
            ->assertForbidden();
    }

    public function test_the_client_cannot_confirm_or_reject_a_reschedule(): void
    {
        [$client, , , $ride] = $this->scheduledRide();

        $ride->update(['pending_reschedule_at' => '2026-01-17 09:30:00']);

        $this->actingAs($client)->post(route('rides.reschedule.confirm', $ride))->assertForbidden();
        $this->actingAs($client)->post(route('rides.reschedule.reject', $ride))->assertForbidden();
    }

    public function test_proposing_a_reschedule_in_the_past_is_rejected(): void
    {
        [$client, , , $ride] = $this->scheduledRide();

        $this->actingAs($client)
            ->post(route('rides.reschedule.propose', $ride), [
                'scheduled_date' => '2026-01-15',
                'scheduled_time' => '08:00',
            ])
            ->assertSessionHasErrors('scheduled_time');

        $this->assertNull($ride->fresh()->pending_reschedule_at);
    }

    /**
     * El conductor no puede arrancar con un horario en disputa (pedido
     * explícito del usuario) — primero tiene que confirmarlo o rechazarlo.
     */
    public function test_the_driver_cannot_start_a_ride_with_a_pending_reschedule(): void
    {
        [$client, $driver, , $ride] = $this->scheduledRide();

        $this->actingAs($client)->post(route('rides.reschedule.propose', $ride), [
            'scheduled_date' => '2026-01-17',
            'scheduled_time' => '09:30',
        ]);

        $this->actingAs($driver)
            ->post(route('rides.start', $ride))
            ->assertSessionHasErrors('ride');

        $this->assertSame('scheduled', $ride->fresh()->status);
    }
}
