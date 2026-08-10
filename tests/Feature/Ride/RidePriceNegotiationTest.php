<?php

namespace Tests\Feature\Ride;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\PricingSetting;
use App\Models\RideRequest;
use App\Models\User;
use App\Services\PriceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Fase 4 del roadmap: negociación de precio (sección 5) — el cliente propone
 * un monto (el sugerido u otro), y el conductor puede aceptarlo, rechazarlo,
 * o contraofertar una única vez antes de que el cliente decida.
 */
class RidePriceNegotiationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mediodía fijo: sin recargo nocturno, para que el precio sugerido
        // sea siempre el mismo en las aserciones (sección 5).
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00'));
    }

    private function clientWithFleetDriver(float $ratePerKm = 0.5): array
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['rate_per_km' => $ratePerKm]);

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        return [$client, $driver, $fleet];
    }

    public function test_store_uses_the_suggested_price_by_default(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver(ratePerKm: 0.5);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.1807,
            'destination_lng' => -78.4778, // ~1.1km aprox, no importa el valor exacto
        ])->assertRedirect();

        $rideRequest = RideRequest::firstOrFail();
        $suggested = PriceCalculator::suggestedPrice((float) $rideRequest->distance_km, 0.5)['total'];

        $this->assertEquals(number_format($suggested, 2, '.', ''), (string) $rideRequest->current_offered_price);
        $this->assertSame(0, $rideRequest->negotiation_round);
        $this->assertSame('client', $rideRequest->last_offer_made_by);
        $this->assertDatabaseHas('ride_price_offers', [
            'ride_request_id' => $rideRequest->id,
            'offered_by_user_id' => $client->id,
        ]);
    }

    /**
     * Pedido explícito del usuario: la tarifa mínima que declara el
     * conductor en su perfil se respeta si es MENOR o igual a la de la
     * plataforma — antes se ignoraba por completo, siempre se usaba la de
     * /admin/tarifas sin importar lo que el conductor hubiera puesto.
     */
    public function test_a_drivers_own_lower_minimum_fare_is_used_over_the_platform_one(): void
    {
        PricingSetting::current()->update(['minimum_fare' => 3.00]);

        [$client, $driver] = $this->clientWithFleetDriver(ratePerKm: 0.1);
        $driver->driverProfile()->update(['minimum_fare' => 1.00]);

        // Distancia corta a propósito: distancia × tarifa da bien por debajo
        // de cualquiera de los dos mínimos, así el que gana se ve directo en
        // el precio final.
        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.1808,
            'destination_lng' => -78.4679,
        ])->assertRedirect();

        $rideRequest = RideRequest::firstOrFail();
        $this->assertSame('1.00', (string) $rideRequest->current_offered_price);
    }

    /**
     * Red de seguridad además de la validación al guardar el perfil
     * (DriverProfileController::update()): si el admin bajó su tarifa
     * DESPUÉS de que el conductor hubiera guardado una más alta cuando
     * todavía era válida, PriceCalculator igual la recorta a la de la
     * plataforma — nunca se cobra más que el tope vigente.
     */
    public function test_a_stale_driver_minimum_fare_above_the_platform_one_gets_capped(): void
    {
        PricingSetting::current()->update(['minimum_fare' => 2.00]);

        [$client, $driver] = $this->clientWithFleetDriver(ratePerKm: 0.1);
        // forceFill: se salta la validación de DriverProfileController a
        // propósito, para simular el escenario de "ya no es válida".
        $driver->driverProfile()->update(['minimum_fare' => 5.00]);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.1808,
            'destination_lng' => -78.4679,
        ])->assertRedirect();

        $rideRequest = RideRequest::firstOrFail();
        $this->assertSame('2.00', (string) $rideRequest->current_offered_price);
    }

    public function test_client_can_propose_a_custom_price(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.1900,
            'destination_lng' => -78.4700,
            'offered_price' => 7.5,
        ])->assertRedirect();

        $this->assertDatabaseHas('ride_requests', ['current_offered_price' => '7.50']);
    }

    /**
     * Pedido explícito del usuario (caso real: propuso $2 contra un
     * estimado de $3.85) — la contraoferta inicial no puede ser menor al
     * precio estimado, para que no se le proponga un monto simbólico al
     * conductor.
     */
    public function test_client_cannot_propose_a_price_below_the_suggested_price(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver(ratePerKm: 0.5);

        $response = $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $driver->id,
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2500,
            'destination_lng' => -78.5500, // ~10km, sugerido bastante por encima del mínimo
            'offered_price' => 2,
        ]);

        $response->assertSessionHasErrors('offered_price');
        $this->assertDatabaseMissing('ride_requests', ['client_user_id' => $client->id]);
    }

    public function test_driver_can_accept_the_offered_price_directly(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => Fleet::where('owner_user_id', $client->id)->first()->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
            'current_offered_price' => 6.4,
        ]);

        $this->actingAs($driver)->post(route('ride-requests.accept', $rideRequest))->assertRedirect();

        $this->assertDatabaseHas('rides', ['ride_request_id' => $rideRequest->id, 'price' => '6.40']);
    }

    public function test_driver_can_counter_offer_once(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => Fleet::where('owner_user_id', $client->id)->first()->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
            'current_offered_price' => 5,
        ]);

        $this->actingAs($driver)
            ->post(route('ride-requests.counter', $rideRequest), ['offered_amount' => 8])
            ->assertRedirect();

        $rideRequest->refresh();
        $this->assertSame('negotiating', $rideRequest->status);
        $this->assertSame(1, $rideRequest->negotiation_round);
        $this->assertSame('driver', $rideRequest->last_offer_made_by);
        $this->assertSame($driver->id, $rideRequest->negotiating_driver_user_id);
        $this->assertEquals('8.00', (string) $rideRequest->current_offered_price);

        $this->assertDatabaseHas('ride_price_offers', [
            'ride_request_id' => $rideRequest->id,
            'offered_by_user_id' => $driver->id,
            'offered_amount' => '8.00',
        ]);
    }

    public function test_driver_cannot_counter_offer_twice(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => Fleet::where('owner_user_id', $client->id)->first()->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'negotiating',
            'negotiation_round' => 1,
            'last_offer_made_by' => 'driver',
            'negotiating_driver_user_id' => $driver->id,
            'current_offered_price' => 8,
        ]);

        $this->actingAs($driver)
            ->post(route('ride-requests.counter', $rideRequest), ['offered_amount' => 9])
            ->assertSessionHasErrors('ride_request');
    }

    public function test_client_can_accept_the_drivers_counter_offer(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => Fleet::where('owner_user_id', $client->id)->first()->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'negotiating',
            'negotiation_round' => 1,
            'last_offer_made_by' => 'driver',
            'negotiating_driver_user_id' => $driver->id,
            'current_offered_price' => 8,
        ]);

        // El conductor no puede aceptar su propia contraoferta: le toca al cliente.
        $this->actingAs($driver)
            ->post(route('ride-requests.accept', $rideRequest))
            ->assertForbidden();

        $this->actingAs($client)
            ->post(route('ride-requests.accept', $rideRequest))
            ->assertRedirect();

        $this->assertDatabaseHas('rides', [
            'ride_request_id' => $rideRequest->id,
            'driver_user_id' => $driver->id,
            'price' => '8.00',
        ]);
    }

    public function test_client_can_reject_the_drivers_counter_offer(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => Fleet::where('owner_user_id', $client->id)->first()->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'negotiating',
            'negotiation_round' => 1,
            'last_offer_made_by' => 'driver',
            'negotiating_driver_user_id' => $driver->id,
            'current_offered_price' => 8,
        ]);

        $this->actingAs($client)
            ->post(route('ride-requests.cancel', $rideRequest))
            ->assertRedirect();

        $this->assertDatabaseHas('ride_requests', ['id' => $rideRequest->id, 'status' => 'cancelled']);
        $this->assertDatabaseMissing('rides', ['ride_request_id' => $rideRequest->id]);
    }

    public function test_only_the_first_driver_to_counter_a_whole_fleet_request_claims_it(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $driverA = User::factory()->create();
        DriverProfile::factory()->for($driverA)->create(['rate_per_km' => 0.5]);
        FleetMember::factory()->for($fleet)->for($driverA, 'driver')->create(['added_by' => $client->id]);

        $driverB = User::factory()->create();
        DriverProfile::factory()->for($driverB)->create(['rate_per_km' => 0.6]);
        FleetMember::factory()->for($fleet)->for($driverB, 'driver')->create(['added_by' => $client->id]);

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'driver_user_id' => null,
            'status' => 'pending',
            'current_offered_price' => 5,
        ]);

        $this->actingAs($driverA)
            ->post(route('ride-requests.counter', $rideRequest), ['offered_amount' => 6])
            ->assertRedirect();

        // driverB llega tarde: ya no está pendiente para contraofertar ni aceptar.
        $this->actingAs($driverB)
            ->post(route('ride-requests.counter', $rideRequest), ['offered_amount' => 7])
            ->assertSessionHasErrors('ride_request');

        $this->actingAs($driverB)
            ->post(route('ride-requests.accept', $rideRequest))
            ->assertForbidden();
    }

    public function test_a_stranger_cannot_counter_offer(): void
    {
        [$client, $driver] = $this->clientWithFleetDriver();

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => Fleet::where('owner_user_id', $client->id)->first()->id,
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'pending',
            'current_offered_price' => 5,
        ]);

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post(route('ride-requests.counter', $rideRequest), ['offered_amount' => 6])
            ->assertForbidden();
    }
}
