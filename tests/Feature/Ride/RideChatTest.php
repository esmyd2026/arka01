<?php

namespace Tests\Feature\Ride;

use App\Events\RideMessageSent;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Chat temporal cliente↔conductor (sección 10 del roadmap de mejoras): solo
 * existe mientras hay una relación de viaje vigente entre esas dos personas
 * puntuales — nunca antes de aceptar, ni después de completar/cancelar.
 */
class RideChatTest extends TestCase
{
    use RefreshDatabase;

    private function clientAndDriverWithRide(string $status = 'in_progress'): array
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $ride = Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => $status,
        ]);

        return [$client, $driver, $ride];
    }

    public function test_the_client_can_send_a_message_while_the_ride_is_in_progress(): void
    {
        Event::fake([RideMessageSent::class]);

        [$client, , $ride] = $this->clientAndDriverWithRide();

        $response = $this->actingAs($client)->postJson(route('ride-messages.store', $ride), [
            'body' => '¿Vienes en camino?',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('ride_messages', [
            'ride_id' => $ride->id,
            'sender_user_id' => $client->id,
            'body' => '¿Vienes en camino?',
        ]);
        Event::assertDispatched(RideMessageSent::class);
    }

    public function test_a_stranger_cannot_send_a_message(): void
    {
        [, , $ride] = $this->clientAndDriverWithRide();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->postJson(route('ride-messages.store', $ride), [
            'body' => 'Hola',
        ])->assertForbidden();
    }

    /**
     * Pedido explícito del usuario: el chat se cierra cuando el viaje
     * finaliza o se cancela — no se puede seguir escribiendo.
     */
    public function test_no_new_messages_once_the_ride_is_completed(): void
    {
        [$client, , $ride] = $this->clientAndDriverWithRide('completed');

        $this->actingAs($client)->postJson(route('ride-messages.store', $ride), [
            'body' => 'Gracias',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('ride_messages', ['ride_id' => $ride->id]);
    }

    public function test_no_new_messages_once_the_ride_is_cancelled(): void
    {
        [$client, , $ride] = $this->clientAndDriverWithRide('cancelled');

        $this->actingAs($client)->postJson(route('ride-messages.store', $ride), [
            'body' => '¿Qué pasó?',
        ])->assertUnprocessable();
    }

    /**
     * Un viaje PROGRAMADO ya aceptado, pero todavía sin arrancar, también
     * cuenta como relación de viaje vigente (pedido explícito del usuario:
     * "puede habilitarse desde que el conductor acepta la carrera").
     */
    public function test_messages_are_allowed_while_the_ride_is_scheduled_but_not_started(): void
    {
        Event::fake([RideMessageSent::class]);

        [$client, , $ride] = $this->clientAndDriverWithRide('scheduled');

        $this->actingAs($client)->postJson(route('ride-messages.store', $ride), [
            'body' => 'Nos vemos a las 8.',
        ])->assertOk();
    }

    public function test_the_ride_screen_exposes_the_message_history(): void
    {
        [$client, $driver, $ride] = $this->clientAndDriverWithRide();

        $this->actingAs($client)->postJson(route('ride-messages.store', $ride), ['body' => 'Hola']);
        $this->actingAs($driver)->postJson(route('ride-messages.store', $ride), ['body' => 'Hola, ya voy']);

        $response = $this->actingAs($client)->get(route('rides.show', $ride));

        $response->assertInertia(fn ($page) => $page
            ->has('messages', 2)
            ->where('messages.0.body', 'Hola')
            ->where('messages.1.body', 'Hola, ya voy')
        );
    }

    public function test_the_message_body_is_required_and_capped_at_500_characters(): void
    {
        [$client, , $ride] = $this->clientAndDriverWithRide();

        $this->actingAs($client)->postJson(route('ride-messages.store', $ride), [
            'body' => '',
        ])->assertJsonValidationErrors('body');

        $this->actingAs($client)->postJson(route('ride-messages.store', $ride), [
            'body' => str_repeat('a', 501),
        ])->assertJsonValidationErrors('body');
    }
}
