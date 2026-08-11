<?php

namespace Tests\Feature\Ride;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\RideRequest;
use App\Models\User;
use App\Notifications\RideRequestedPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Zona de cobertura del conductor (pedido explícito del usuario): configura
 * hasta qué distancia de su ubicación actual quiere recibir solicitudes, para
 * no recibir carreras que no le convienen por los km — aunque el cliente sea
 * de su propia flota ("si está fuera de rango, así sea de mi flota, tiene que
 * estar fuera de rango").
 */
class DriverCoverageRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_can_set_a_maximum_request_distance(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['rate_per_km' => 0.5]);

        $this->actingAs($driver)->post(route('driver.profile.update'), [
            'license_number' => 'ABC123',
            'vehicle_make' => 'Chevrolet',
            'vehicle_model' => 'Spark',
            'vehicle_color' => 'Blanco',
            'vehicle_type' => 'sedan',
            'vehicle_plate' => 'ABC-1234',
            'vehicle_year' => 2020,
            'passenger_capacity' => 4,
            'has_trunk' => true,
            'rate_per_km' => 0.5,
            'max_request_distance_km' => 10,
        ])->assertSessionHasNoErrors();

        $this->assertSame(10, $driver->driverProfile->fresh()->max_request_distance_km);
    }

    /**
     * Guayaquil (Guasmo) ~ -2.20, -79.90 vs Samborondón ~ -1.99, -79.90 — unos
     * 23 km en línea recta, bastante más que un límite de 10 km configurado.
     */
    public function test_requesting_a_directed_ride_outside_the_drivers_range_is_rejected(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'rate_per_km' => 0.5,
            'current_lat' => -1.99,
            'current_lng' => -79.90,
            'max_request_distance_km' => 10,
        ]);

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -2.20,
            'origin_lng' => -79.90,
            'destination_lat' => -2.15,
            'destination_lng' => -79.88,
        ])->assertSessionHasErrors('driver_user_id');

        $this->assertDatabaseMissing('ride_requests', ['client_user_id' => $client->id]);
    }

    public function test_requesting_a_directed_ride_inside_the_drivers_range_still_works(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'rate_per_km' => 0.5,
            'current_lat' => -2.20,
            'current_lng' => -79.90,
            'max_request_distance_km' => 10,
        ]);

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -2.201,
            'origin_lng' => -79.901,
            'destination_lat' => -2.15,
            'destination_lng' => -79.88,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ride_requests', ['client_user_id' => $client->id, 'driver_user_id' => $driver->id]);
    }

    /**
     * El punto central del pedido: en "toda la flota", el conductor lejano
     * queda afuera de la notificación aunque sea de la misma flota — el
     * cercano sí se entera.
     */
    public function test_a_whole_fleet_request_only_notifies_drivers_within_their_own_range(): void
    {
        Notification::fake();
        Queue::fake();

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $farDriver = User::factory()->create();
        DriverProfile::factory()->for($farDriver)->create([
            'rate_per_km' => 0.5,
            'current_lat' => -1.99,
            'current_lng' => -79.90,
            'max_request_distance_km' => 10,
        ]);
        FleetMember::factory()->for($fleet)->for($farDriver, 'driver')->create(['added_by' => $client->id]);

        $nearDriver = User::factory()->create();
        DriverProfile::factory()->for($nearDriver)->create([
            'rate_per_km' => 0.5,
            'current_lat' => -2.201,
            'current_lng' => -79.901,
            'max_request_distance_km' => 10,
        ]);
        FleetMember::factory()->for($fleet)->for($nearDriver, 'driver')->create(['added_by' => $client->id]);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'origin_lat' => -2.20,
            'origin_lng' => -79.90,
            'destination_lat' => -2.15,
            'destination_lng' => -79.88,
        ])->assertSessionHasNoErrors();

        Notification::assertNotSentTo($farDriver, RideRequestedPushNotification::class);
        Notification::assertSentTo($nearDriver, RideRequestedPushNotification::class);
    }

    /**
     * Mismo criterio para la carga inicial de /carreras (no solo el aviso en
     * vivo): el conductor lejano ni siquiera debería listarla al entrar.
     */
    public function test_a_far_driver_does_not_see_a_whole_fleet_request_in_their_list(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $farDriver = User::factory()->create();
        DriverProfile::factory()->for($farDriver)->create([
            'current_lat' => -1.99,
            'current_lng' => -79.90,
            'max_request_distance_km' => 10,
        ]);
        FleetMember::factory()->for($fleet)->for($farDriver, 'driver')->create(['added_by' => $client->id]);

        RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => null,
            'status' => 'pending',
            'origin_lat' => -2.20,
            'origin_lng' => -79.90,
        ]);

        $response = $this->actingAs($farDriver)->get(route('rides.index'));

        $response->assertInertia(fn ($page) => $page->where('incomingRequestsAsDriver', []));
    }

    /**
     * Bug reportado por el usuario: pidió una carrera con un conductor que
     * se veía "disponible" en pantalla, y salió el mensaje genérico "no hay
     * conductores... (pasajeros/cajuela)" — el motivo real era la zona de
     * cobertura, nada que ver con pasajeros ni cajuela. El mensaje ahora
     * tiene que decir la razón real.
     */
    public function test_the_error_mentions_coverage_zone_when_thats_the_real_reason(): void
    {
        [$client, $driver] = [User::factory()->create(), User::factory()->create()];
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        DriverProfile::factory()->for($driver)->create([
            'rate_per_km' => 0.5,
            'current_lat' => -1.99,
            'current_lng' => -79.90,
            'max_request_distance_km' => 2,
        ]);
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $response = $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'origin_lat' => -2.20,
            'origin_lng' => -79.90,
            'destination_lat' => -2.15,
            'destination_lng' => -79.88,
        ]);

        $response->assertSessionHasErrors('driver_user_id');
        $this->assertStringContainsString('zona', session('errors')->first('driver_user_id'));
    }
}
