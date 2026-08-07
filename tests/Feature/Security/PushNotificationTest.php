<?php

namespace Tests\Feature\Security;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use App\Notifications\RideAcceptedPushNotification;
use App\Notifications\RideCompletedPushNotification;
use App\Notifications\RideRequestedPushNotification;
use App\Notifications\RideStartedPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Notificaciones push (sección 9.2 y 9.5): guardar la suscripción del
 * navegador, y que pedir una carrera efectivamente avise al conductor
 * aunque no tenga la pestaña abierta (el WebSocket no alcanza para eso).
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_store_a_push_subscription(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(route('push-subscriptions.store'), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/example',
            'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-key'],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('push_subscriptions', [
            'subscribable_id' => $user->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/example',
        ]);
    }

    public function test_requesting_a_ride_notifies_the_directed_driver(): void
    {
        Notification::fake();

        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['rate_per_km' => 0.5]);

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ]);

        Notification::assertSentTo($driver, RideRequestedPushNotification::class);
    }

    /**
     * Despacho secuencial estilo Uber (pedido explícito del usuario): "toda
     * la flota" ya no avisa a todos los conductores a la vez — se le ofrece
     * de a uno, empezando por el candidato elegido primero. Al segundo solo
     * le toca si el primero no responde en 30 seg. (cascada cubierta aparte
     * en tests/Feature/Ride/SequentialDispatchTest.php).
     */
    public function test_requesting_a_ride_to_the_whole_fleet_only_notifies_the_first_candidate(): void
    {
        Notification::fake();
        Queue::fake();

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $driverA = User::factory()->create();
        DriverProfile::factory()->for($driverA)->create(['rate_per_km' => 0.5]);
        FleetMember::factory()->for($fleet)->for($driverA, 'driver')->create(['added_by' => $client->id]);

        $driverB = User::factory()->create();
        DriverProfile::factory()->for($driverB)->create(['rate_per_km' => 0.6]);
        FleetMember::factory()->for($fleet)->for($driverB, 'driver')->create(['added_by' => $client->id]);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ]);

        $rideRequest = RideRequest::query()->where('client_user_id', $client->id)->firstOrFail();
        $firstCandidate = $rideRequest->driver_user_id === $driverA->id ? $driverA : $driverB;
        $secondCandidate = $firstCandidate->id === $driverA->id ? $driverB : $driverA;

        Notification::assertSentTo($firstCandidate, RideRequestedPushNotification::class);
        Notification::assertNotSentTo($secondCandidate, RideRequestedPushNotification::class);
    }

    /**
     * Pedido explícito del usuario: cuando el conductor sale a buscar al
     * cliente, avisarle — cubre el caso de que tenga la app cerrada, a
     * diferencia del WebSocket (Reverb), que solo avisa con la pestaña abierta.
     */
    public function test_accepting_a_ride_request_notifies_the_client(): void
    {
        Notification::fake();

        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['rate_per_km' => 0.5]);

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
        ]);

        $this->actingAs($driver)->post(route('ride-requests.accept', $rideRequest));

        Notification::assertSentTo($client, RideAcceptedPushNotification::class);
    }

    /**
     * Pedido explícito del usuario: notificaciones habilitadas en cada acción
     * — a quien no completó la carrera (para que sepa que tiene que cerrarla).
     */
    public function test_completing_a_ride_notifies_the_other_party(): void
    {
        Notification::fake();

        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $ride = Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($driver)->post(route('rides.complete', $ride));

        Notification::assertSentTo($client, RideCompletedPushNotification::class);
        Notification::assertNotSentTo($driver, RideCompletedPushNotification::class);
    }

    /**
     * Pedido explícito del usuario: cuando el conductor sale a buscar al
     * cliente de una carrera que venía PROGRAMADA, avisarle — distinto del
     * aviso de "aceptada", que pasa mucho antes (al momento de programarla).
     */
    public function test_starting_a_scheduled_ride_notifies_the_client(): void
    {
        Notification::fake();

        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $ride = Ride::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'scheduled',
            'started_at' => null,
        ]);

        $this->actingAs($driver)->post(route('rides.start', $ride));

        Notification::assertSentTo($client, RideStartedPushNotification::class);
    }
}
