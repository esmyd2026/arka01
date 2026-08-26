<?php

namespace Tests\Feature\Cooperative;

use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\RideRequest;
use App\Models\User;
use App\Services\RideDispatchAdvancer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pedido explícito del usuario, con una captura real del panel de despacho:
 * "revisa que pasa luego que la asigna y no la acepta el conductor el motor
 * sigue intentando y le dice a la de la cooperativa a quien se la asginaron
 * y la cancelo?" — el motor sí reintentaba con el siguiente conductor
 * (RideDispatchAdvancer::advanceOrExpire()), pero el panel no dejaba ningún
 * rastro de a quién se le había ofrecido antes ni por qué se reintentó.
 * Ver RideRequest::cooperative_dispatch_log.
 */
class CooperativeDispatchCascadeTest extends TestCase
{
    use RefreshDatabase;

    private function driverAt(Cooperative $cooperative, User $cooperativeUser, float $lat, float $lng): User
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'driver_type' => 'public_transport',
            'is_available' => true,
            'current_lat' => $lat,
            'current_lng' => $lng,
        ]);
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeUser->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        return $driver;
    }

    /**
     * @return array{0: User, 1: Cooperative, 2: RideRequest, 3: User, 4: User}
     */
    private function assignedRequestSetup(): array
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Central']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $first = $this->driverAt($cooperative, $cooperativeUser, -0.1810, -78.4680);
        $second = $this->driverAt($cooperative, $cooperativeUser, -0.2200, -78.5100);

        $client = User::factory()->create();
        $rideRequest = RideRequest::factory()->create([
            'client_user_id' => $client->id,
            'cooperative_id' => $cooperative->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'status' => 'pending',
        ]);

        Queue::fake();
        $this->actingAs($cooperativeUser)
            ->post(route('cooperative.rides.assign', $rideRequest), ['driver_user_id' => $first->id])
            ->assertRedirect();

        return [$cooperativeUser, $cooperative, $rideRequest->fresh(), $first, $second];
    }

    public function test_the_cascade_keeps_trying_the_next_driver_when_the_first_one_does_not_respond_in_time(): void
    {
        [, , $rideRequest, $first, $second] = $this->assignedRequestSetup();
        $this->assertSame($first->id, $rideRequest->driver_user_id);

        RideDispatchAdvancer::advanceOrExpire($rideRequest->id, $first->id);

        $this->assertSame($second->id, $rideRequest->fresh()->driver_user_id);
    }

    public function test_the_operator_panel_records_who_it_was_offered_to_before_and_why_it_moved_on(): void
    {
        [, , $rideRequest, $first] = $this->assignedRequestSetup();

        RideDispatchAdvancer::advanceOrExpire($rideRequest->id, $first->id);

        $log = $rideRequest->fresh()->cooperative_dispatch_log;
        $this->assertCount(1, $log);
        $this->assertSame($first->id, $log[0]['driver_user_id']);
        $this->assertSame($first->name, $log[0]['driver_name']);
        $this->assertSame('timeout', $log[0]['outcome']);
    }

    public function test_an_explicit_rejection_is_logged_differently_from_a_timeout(): void
    {
        [, , $rideRequest, $first] = $this->assignedRequestSetup();

        $this->actingAs($first)->post(route('ride-requests.reject', $rideRequest))->assertRedirect();

        $log = $rideRequest->fresh()->cooperative_dispatch_log;
        $this->assertSame('rejected', $log[0]['outcome']);
    }

    public function test_when_nobody_is_left_it_expires_and_still_logs_the_last_attempt(): void
    {
        [, , $rideRequest, $first, $second] = $this->assignedRequestSetup();

        RideDispatchAdvancer::advanceOrExpire($rideRequest->id, $first->id);
        RideDispatchAdvancer::advanceOrExpire($rideRequest->fresh()->id, $second->id);

        $fresh = $rideRequest->fresh();
        $this->assertSame('expired', $fresh->status);
        $this->assertCount(2, $fresh->cooperative_dispatch_log);
        $this->assertSame($second->id, $fresh->cooperative_dispatch_log[1]['driver_user_id']);
    }

    /**
     * Alcance acotado a propósito: el despacho de flota/público no tiene un
     * panel operativo que lo lea — no vale la pena llenar la columna JSON en
     * cada carrera común, solo cuando es de una cooperativa.
     */
    public function test_a_non_cooperative_cascade_does_not_write_to_the_dispatch_log(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $first = User::factory()->create();
        DriverProfile::factory()->for($first)->create(['is_available' => true, 'current_lat' => -0.1810, 'current_lng' => -78.4680]);
        FleetMember::factory()->for($fleet)->for($first, 'driver')->create(['added_by' => $client->id]);

        Queue::fake();
        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'dispatch_pool' => 'fleet',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertRedirect();

        $rideRequest = RideRequest::query()->where('client_user_id', $client->id)->firstOrFail();
        RideDispatchAdvancer::advanceOrExpire($rideRequest->id, $first->id);

        $this->assertNull($rideRequest->fresh()->cooperative_dispatch_log);
    }
}
