<?php

namespace Tests\Feature\Ride;

use App\Events\RideRequestCancelled;
use App\Events\RideRequested;
use App\Events\RideRequestExpired;
use App\Jobs\ExpireRideOffer;
use App\Jobs\ExpireWaitingRideRequest;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use App\Notifications\RideRequestedPushNotification;
use App\Notifications\RideRequestExpiredPushNotification;
use App\Services\Ride\RideOfferComparison;
use App\Services\RideDispatchAdvancer;
use App\Services\RideDispatchCandidates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Despacho secuencial estilo Uber (pedido explícito del usuario): en vez de
 * avisarle a toda la flota a la vez, se le ofrece la carrera a un candidato
 * por vez —empezando por el más cercano—, con 30 segundos para responder
 * antes de pasar al siguiente (App\Services\RideDispatchCandidates,
 * App\Services\RideDispatchAdvancer, App\Jobs\ExpireRideOffer).
 */
class SequentialDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function driverAt(Fleet $fleet, User $client, float $lat, float $lng, array $overrides = []): User
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(array_merge([
            'rate_per_km' => 0.5,
            'is_available' => true,
            'current_lat' => $lat,
            'current_lng' => $lng,
        ], $overrides));
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        return $driver;
    }

    public function test_forpool_orders_by_distance_and_excludes_unavailable_busy_disabled_and_out_of_range_drivers(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        // Origen: -0.1807, -78.4678 (Quito).
        $near = $this->driverAt($fleet, $client, -0.1810, -78.4680);
        $far = $this->driverAt($fleet, $client, -0.2200, -78.5100);
        $this->driverAt($fleet, $client, -0.1812, -78.4682, ['is_available' => false]);

        $busy = $this->driverAt($fleet, $client, -0.1809, -78.4679);
        Ride::factory()->create(['driver_user_id' => $busy->id, 'status' => 'in_progress']);

        $this->driverAt($fleet, $client, -1.5, -79.5, ['max_request_distance_km' => 1]);

        $disabled = $this->driverAt($fleet, $client, -0.1808, -78.4679);
        FleetMember::query()->where('fleet_id', $fleet->id)->where('driver_user_id', $disabled->id)
            ->update(['requests_disabled' => true]);

        $noLocation = $this->driverAt($fleet, $client, 0, 0);
        DriverProfile::where('user_id', $noLocation->id)->update(['current_lat' => null, 'current_lng' => null]);

        $ids = RideDispatchCandidates::forPool($fleet, $client, 'fleet', -0.1807, -78.4678);

        $this->assertSame([$near->id, $far->id, $noLocation->id], $ids);
    }

    /**
     * Panel admin (pedido explícito del usuario: "bloquear o deshabilitar o
     * desconectar") — un conductor suspendido no puede ser candidato de
     * ninguna solicitud, ni de su propia flota, hasta que un admin lo reactive.
     */
    public function test_forpool_excludes_a_suspended_driver(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $suspended = $this->driverAt($fleet, $client, -0.1810, -78.4680);
        DriverProfile::where('user_id', $suspended->id)->update(['suspended_at' => now()]);

        $ids = RideDispatchCandidates::forPool($fleet, $client, 'fleet', -0.1807, -78.4678);

        $this->assertSame([], $ids);
    }

    public function test_forpool_public_pool_only_returns_public_directory_drivers(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $publicDriver = User::factory()->create();
        DriverProfile::factory()->for($publicDriver)->create([
            'is_public' => true,
            'current_lat' => -0.181,
            'current_lng' => -78.468,
        ]);

        $privateDriver = User::factory()->create();
        DriverProfile::factory()->for($privateDriver)->create(['is_public' => false]);

        $ids = RideDispatchCandidates::forPool($fleet, $client, 'public', -0.1807, -78.4678);

        $this->assertSame([$publicDriver->id], $ids);
    }

    public function test_store_assigns_the_nearest_candidate_and_keeps_the_rest_for_the_cascade(): void
    {
        Queue::fake();

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $near = $this->driverAt($fleet, $client, -0.1810, -78.4680);
        $far = $this->driverAt($fleet, $client, -0.2200, -78.5100);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'dispatch_pool' => 'fleet',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertRedirect();

        $rideRequest = RideRequest::query()->where('client_user_id', $client->id)->firstOrFail();

        $this->assertSame($near->id, $rideRequest->driver_user_id);
        $this->assertSame([$far->id], $rideRequest->offer_candidate_ids);
        $this->assertNotNull($rideRequest->current_offer_expires_at);
        $this->assertSame('v1', $rideRequest->smart_dispatch_version);
        $this->assertSame([$near->id, $far->id], collect($rideRequest->smart_dispatch_snapshot)->pluck('driver_user_id')->all());

        Queue::assertPushed(ExpireRideOffer::class, fn (ExpireRideOffer $job) => $job->rideRequestId === $rideRequest->id
            && $job->expectedCurrentDriverId === $near->id);
    }

    public function test_a_chosen_driver_falls_back_without_changing_the_clients_total(): void
    {
        Queue::fake();
        Notification::fake();

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $chosen = $this->driverAt($fleet, $client, -0.1810, -78.4680, ['rate_per_km' => 0.55]);
        $fallback = $this->driverAt($fleet, $client, -0.1820, -78.4690, ['rate_per_km' => 0.35]);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => $chosen->id,
            'dispatch_pool' => 'fleet',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'route_distance_km' => 5,
            'stops' => [[
                'lat' => -0.1900,
                'lng' => -78.4800,
                'address' => 'Parada 1',
                'route_distance_km' => 3,
            ]],
        ])->assertRedirect();

        $rideRequest = RideRequest::query()->where('client_user_id', $client->id)->firstOrFail();
        $initialFinalPrice = (float) $rideRequest->current_offered_price;
        $initialStopsPrice = (float) $rideRequest->stops_price;

        $this->assertSame($chosen->id, $rideRequest->price_reference_driver_user_id);
        $this->assertContains($fallback->id, $rideRequest->offer_candidate_ids);

        RideDispatchAdvancer::advanceOrExpire($rideRequest->id, $chosen->id);

        $advanced = $rideRequest->fresh('stops');
        $this->assertSame($fallback->id, $advanced->driver_user_id);
        $this->assertSame($initialFinalPrice, (float) $advanced->current_offered_price);
        $this->assertSame($initialStopsPrice, (float) $advanced->stops_price);

        $comparison = app(RideOfferComparison::class)->forDriver($advanced, $fallback);
        $this->assertTrue($comparison['uses_another_driver_price']);
        $this->assertSame(
            round($initialFinalPrice + $initialStopsPrice, 2),
            $comparison['locked_total'],
        );
    }

    public function test_smart_dispatch_only_reorders_eligible_drivers_inside_the_selected_pool(): void
    {
        config()->set('smart_dispatch.enabled', true);
        config()->set('smart_dispatch.minimum_history_samples', 3);
        config()->set('smart_dispatch.weights', [
            'proximity' => 0,
            'acceptance' => 100,
            'rating' => 0,
            'reliability' => 0,
            'idle_time' => 0,
        ]);

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $nearWithRejections = $this->driverAt($fleet, $client, -0.1810, -78.4680, ['rides_rejected_count' => 5]);
        $farWithAcceptances = $this->driverAt($fleet, $client, -0.2200, -78.5100);

        RideRequest::factory()->count(5)->create([
            'status' => 'accepted',
            'accepted_by' => $farWithAcceptances->id,
        ]);

        $ids = RideDispatchCandidates::forPool($fleet, $client, 'fleet', -0.1807, -78.4678);

        $this->assertSame([$farWithAcceptances->id, $nearWithRejections->id], $ids);
    }

    public function test_disabling_smart_dispatch_restores_distance_order(): void
    {
        config()->set('smart_dispatch.enabled', false);

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $near = $this->driverAt($fleet, $client, -0.1810, -78.4680, ['rides_rejected_count' => 100]);
        $far = $this->driverAt($fleet, $client, -0.2200, -78.5100);

        $ids = RideDispatchCandidates::forPool($fleet, $client, 'fleet', -0.1807, -78.4678);

        $this->assertSame([$near->id, $far->id], $ids);
    }

    public function test_store_fails_when_no_candidate_is_available(): void
    {
        $client = User::factory()->create();
        Fleet::factory()->for($client, 'owner')->create();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'dispatch_pool' => 'fleet',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertSessionHasErrors('driver_user_id');

        $this->assertDatabaseMissing('ride_requests', ['client_user_id' => $client->id]);
    }

    /**
     * Alcance acotado a propósito (consideración agregada): una carrera
     * PROGRAMADA no entra en la cascada de 30 seg. — no tiene sentido meterle
     * presión de tiempo a algo que es para más tarde. Se mantiene el aviso a
     * toda la flota de siempre, sin candidato puntual ni Job.
     */
    public function test_a_scheduled_whole_fleet_request_skips_the_cascade_entirely(): void
    {
        Queue::fake();

        $client = User::factory()->create();
        Fleet::factory()->for($client, 'owner')->create();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'dispatch_pool' => 'fleet',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'is_scheduled' => true,
            'scheduled_date' => now()->addDay()->format('Y-m-d'),
            'scheduled_time' => '08:00',
        ])->assertSessionHasNoErrors();

        $rideRequest = RideRequest::query()->where('client_user_id', $client->id)->firstOrFail();

        $this->assertNull($rideRequest->driver_user_id);
        $this->assertNull($rideRequest->dispatch_pool);
        $this->assertNull($rideRequest->current_offer_expires_at);

        Queue::assertNotPushed(ExpireRideOffer::class);
    }

    public function test_rejecting_a_sequential_offer_advances_immediately_to_the_next_candidate(): void
    {
        Event::fake([RideRequestCancelled::class, RideRequested::class]);
        Notification::fake();
        Queue::fake();

        [$client, $fleet, $first, $second] = $this->sequentialRequestSetup();

        $rideRequest = RideRequest::query()->where('client_user_id', $client->id)->firstOrFail();
        $this->assertSame($first->id, $rideRequest->driver_user_id);

        $this->actingAs($first)->post(route('ride-requests.reject', $rideRequest))->assertRedirect();

        $rideRequest->refresh();
        $this->assertSame('pending', $rideRequest->status);
        $this->assertSame($second->id, $rideRequest->driver_user_id);
        $this->assertSame([], $rideRequest->offer_candidate_ids);

        Event::assertDispatched(RideRequestCancelled::class, fn ($event) => $event->rideRequest->driver_user_id === $first->id);
        Event::assertDispatched(RideRequested::class, fn ($event) => $event->rideRequest->driver_user_id === $second->id);
        Notification::assertSentTo($second, RideRequestedPushNotification::class);
        Queue::assertPushed(ExpireRideOffer::class, fn (ExpireRideOffer $job) => $job->expectedCurrentDriverId === $second->id);
    }

    public function test_rejecting_the_last_sequential_candidate_expires_the_request(): void
    {
        Event::fake([RideRequestExpired::class]);
        Notification::fake();
        Queue::fake();

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $onlyDriver = $this->driverAt($fleet, $client, -0.1810, -78.4680);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'dispatch_pool' => 'fleet',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertRedirect();

        $rideRequest = RideRequest::query()->where('client_user_id', $client->id)->firstOrFail();

        $this->actingAs($onlyDriver)->post(route('ride-requests.reject', $rideRequest))->assertRedirect();

        $rideRequest->refresh();
        $this->assertSame('expired', $rideRequest->status);

        Event::assertDispatched(RideRequestExpired::class);
        Notification::assertSentTo($client, RideRequestExpiredPushNotification::class);
    }

    public function test_advancer_ignores_a_stale_call_after_the_request_already_moved_on(): void
    {
        // Solo los eventos del dominio: Event::fake() sin argumentos también
        // apaga el evento "creating" de DriverProfile (ahí se genera
        // invite_code, columna NOT NULL) y rompe driverAt() más abajo.
        Event::fake([RideRequestCancelled::class, RideRequested::class, RideRequestExpired::class]);
        // Sin esto, ExpireRideOffer corre de una bajo QUEUE_CONNECTION=sync
        // (forzado en phpunit.xml) y adelanta la cascada de verdad dentro de
        // sequentialRequestSetup(), antes de que este test llame a
        // advanceOrExpire() a mano.
        Queue::fake();

        [$client, $fleet, $first, $second] = $this->sequentialRequestSetup();
        $rideRequest = RideRequest::query()->where('client_user_id', $client->id)->firstOrFail();

        // Alguien más ya hizo avanzar la cascada (o el conductor ya aceptó)
        // antes de que este chequeo corriera — no debería pasar nada.
        RideDispatchAdvancer::advanceOrExpire($rideRequest->id, $second->id);

        $this->assertSame($first->id, $rideRequest->fresh()->driver_user_id);
        Event::assertNotDispatched(RideRequestCancelled::class);
    }

    public function test_advancer_does_nothing_once_the_request_is_no_longer_pending(): void
    {
        Event::fake([RideRequestCancelled::class, RideRequested::class, RideRequestExpired::class]);
        Queue::fake();

        [$client, $fleet, $first] = $this->sequentialRequestSetup();
        $rideRequest = RideRequest::query()->where('client_user_id', $client->id)->firstOrFail();
        $rideRequest->update(['status' => 'accepted']);

        RideDispatchAdvancer::advanceOrExpire($rideRequest->id, $first->id);

        $this->assertSame('accepted', $rideRequest->fresh()->status);
        $this->assertSame($first->id, $rideRequest->fresh()->driver_user_id);
        Event::assertNotDispatched(RideRequestCancelled::class);
    }

    /**
     * Bug reportado por el usuario: dos conductores activos, uno no
     * respondió y mientras tanto el OTRO (el siguiente candidato de la
     * bolsa) se desconectó — igual le llegaba la oferta cuando le tocaba su
     * turno, aunque ya no estuviera disponible de verdad.
     */
    public function test_advancer_skips_a_candidate_who_went_offline_since_the_bolsa_was_built(): void
    {
        Event::fake([RideRequestCancelled::class, RideRequested::class, RideRequestExpired::class]);
        Notification::fake();
        Queue::fake();

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $first = $this->driverAt($fleet, $client, -0.1810, -78.4680);
        $second = $this->driverAt($fleet, $client, -0.1815, -78.4685);
        $third = $this->driverAt($fleet, $client, -0.2200, -78.5100);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'dispatch_pool' => 'fleet',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertRedirect();

        $rideRequest = RideRequest::query()->where('client_user_id', $client->id)->firstOrFail();
        $this->assertSame($first->id, $rideRequest->driver_user_id);
        $this->assertSame([$second->id, $third->id], $rideRequest->offer_candidate_ids);

        // El segundo (el que le tocaría el turno) se desconecta mientras el
        // primero todavía lo tenía él.
        DriverProfile::where('user_id', $second->id)->update(['is_available' => false]);

        RideDispatchAdvancer::advanceOrExpire($rideRequest->id, $first->id);

        $rideRequest->refresh();
        $this->assertSame($third->id, $rideRequest->driver_user_id);
        $this->assertSame([], $rideRequest->offer_candidate_ids);
        Notification::assertSentTo($third, RideRequestedPushNotification::class);
        Notification::assertNotSentTo($second, RideRequestedPushNotification::class);
    }

    /**
     * Mismo bug, caso límite: si el único candidato que queda ya no es
     * elegible, tiene que expirar — no ofrecérsela igual.
     */
    public function test_advancer_expires_when_the_only_remaining_candidate_is_no_longer_eligible(): void
    {
        Event::fake([RideRequestCancelled::class, RideRequestExpired::class]);
        Notification::fake();
        Queue::fake();

        [$client, $fleet, $first, $second] = $this->sequentialRequestSetup();

        DriverProfile::where('user_id', $second->id)->update(['is_available' => false]);

        $rideRequest = RideRequest::query()->where('client_user_id', $client->id)->firstOrFail();
        RideDispatchAdvancer::advanceOrExpire($rideRequest->id, $first->id);

        $this->assertSame('expired', $rideRequest->fresh()->status);
        Event::assertDispatched(RideRequestExpired::class);
        Notification::assertNotSentTo($second, RideRequestedPushNotification::class);
    }

    /**
     * Lista de espera (pedido explícito del usuario: "puedo dejar la
     * carrera pendiente hasta que uno se desocupe y me atienda") — si el
     * único motivo por el que la bolsa está vacía es que todos los
     * candidatos elegibles están ocupados, la solicitud queda `waiting` en
     * vez de rechazarse.
     */
    public function test_store_creates_a_waiting_request_when_everyone_eligible_is_busy(): void
    {
        Queue::fake();

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $busyDriver = $this->driverAt($fleet, $client, -0.1810, -78.4680);
        Ride::factory()->create(['driver_user_id' => $busyDriver->id, 'status' => 'in_progress']);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'dispatch_pool' => 'fleet',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $rideRequest = RideRequest::query()->where('client_user_id', $client->id)->firstOrFail();

        $this->assertSame('waiting', $rideRequest->status);
        $this->assertNull($rideRequest->driver_user_id);
        $this->assertNull($rideRequest->current_offer_expires_at);

        Queue::assertPushed(ExpireWaitingRideRequest::class, fn (ExpireWaitingRideRequest $job) => $job->rideRequestId === $rideRequest->id);
        Queue::assertNotPushed(ExpireRideOffer::class);
    }

    /**
     * Sigue rechazándose igual que antes cuando el motivo NO es "todos
     * ocupados" — acá directamente no hay ningún conductor en la flota,
     * esperar no cambiaría nada (regresión sobre el comportamiento de
     * siempre, ver test_store_fails_when_no_candidate_is_available).
     */
    public function test_store_still_rejects_when_the_pool_is_empty_for_a_different_reason(): void
    {
        $client = User::factory()->create();
        Fleet::factory()->for($client, 'owner')->create();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'dispatch_pool' => 'fleet',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertSessionHasErrors('driver_user_id');

        $this->assertDatabaseMissing('ride_requests', ['client_user_id' => $client->id]);
    }

    /**
     * Al completar una carrera, el conductor recién liberado activa la
     * solicitud en espera más antigua (FIFO, confirmado con el usuario) —
     * no la más nueva.
     */
    public function test_completing_a_ride_activates_the_oldest_waiting_request(): void
    {
        Event::fake([RideRequested::class]);
        Notification::fake();
        Queue::fake();

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = $this->driverAt($fleet, $client, -0.1810, -78.4680);
        $busyRide = Ride::factory()->create(['driver_user_id' => $driver->id, 'status' => 'in_progress']);

        $payload = [
            'driver_user_id' => null,
            'dispatch_pool' => 'fleet',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ];

        $this->actingAs($client)->post(route('ride-requests.store'), $payload)->assertRedirect();
        $older = RideRequest::query()->where('client_user_id', $client->id)->firstOrFail();
        $olderInitialPrice = (float) $older->current_offered_price;
        $older->update(['requested_at' => now()->subMinutes(5)]);

        // La plataforma prohíbe dos solicitudes inmediatas del mismo
        // cliente. La segunda posición de la cola pertenece a otro cliente
        // cuya flota también incluye al mismo conductor ocupado.
        $newerClient = User::factory()->create();
        $newerFleet = Fleet::factory()->for($newerClient, 'owner')->create();
        FleetMember::factory()->for($newerFleet)->for($driver, 'driver')->create(['added_by' => $newerClient->id]);
        $this->actingAs($newerClient)->post(route('ride-requests.store'), $payload)->assertRedirect();
        $newer = RideRequest::query()->where('client_user_id', $newerClient->id)->firstOrFail();

        $this->actingAs($driver)->post(route('rides.complete', $busyRide))->assertRedirect();

        $this->assertSame('pending', $older->fresh()->status);
        $this->assertSame($driver->id, $older->fresh()->driver_user_id);
        $this->assertSame($olderInitialPrice, (float) $older->fresh()->current_offered_price);
        $this->assertNotNull($older->fresh()->current_offer_expires_at);
        $this->assertSame('waiting', $newer->fresh()->status);

        Event::assertDispatched(RideRequested::class, fn ($event) => $event->rideRequest->id === $older->id);
    }

    /**
     * Si la más antigua pide algo que este conductor recién liberado no
     * puede cumplir (ej. cajuela), se salta y se activa la siguiente en la
     * fila que sí puede — un conductor liberado alcanza para una sola. Hacen
     * falta DOS conductores ocupados: uno sin cajuela (el que se libera) y
     * otro con cajuela que se queda ocupado — si solo hubiera uno sin
     * cajuela, la solicitud que pide cajuela ni siquiera llegaría a
     * `waiting` (el motivo real sería "sin capacidad", no "ocupado").
     */
    public function test_completing_a_ride_skips_a_waiting_request_it_cannot_serve(): void
    {
        Event::fake([RideRequested::class]);
        Notification::fake();
        Queue::fake();

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driverNoTrunk = $this->driverAt($fleet, $client, -0.1810, -78.4680, ['has_trunk' => false]);
        $driverWithTrunk = $this->driverAt($fleet, $client, -0.1815, -78.4685, ['has_trunk' => true]);
        $busyRide = Ride::factory()->create(['driver_user_id' => $driverNoTrunk->id, 'status' => 'in_progress']);
        Ride::factory()->create(['driver_user_id' => $driverWithTrunk->id, 'status' => 'in_progress']);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'dispatch_pool' => 'fleet',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
            'needs_trunk' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $needsTrunk = RideRequest::query()->where('client_user_id', $client->id)->firstOrFail();
        $this->assertSame('waiting', $needsTrunk->status);
        $needsTrunk->update(['requested_at' => now()->subMinutes(5)]);

        $noTrunkClient = User::factory()->create();
        $noTrunkFleet = Fleet::factory()->for($noTrunkClient, 'owner')->create();
        FleetMember::factory()->for($noTrunkFleet)->for($driverNoTrunk, 'driver')->create(['added_by' => $noTrunkClient->id]);
        FleetMember::factory()->for($noTrunkFleet)->for($driverWithTrunk, 'driver')->create(['added_by' => $noTrunkClient->id]);

        $this->actingAs($noTrunkClient)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'dispatch_pool' => 'fleet',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $noTrunk = RideRequest::query()->where('client_user_id', $noTrunkClient->id)->firstOrFail();
        $this->assertSame('waiting', $noTrunk->status);

        // Se libera el que NO tiene cajuela — driverWithTrunk sigue ocupado.
        $this->actingAs($driverNoTrunk)->post(route('rides.complete', $busyRide))->assertRedirect();

        $this->assertSame('waiting', $needsTrunk->fresh()->status);
        $this->assertSame('pending', $noTrunk->fresh()->status);
        $this->assertSame($driverNoTrunk->id, $noTrunk->fresh()->driver_user_id);
    }

    public function test_a_waiting_request_can_be_cancelled(): void
    {
        Queue::fake();

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = $this->driverAt($fleet, $client, -0.1810, -78.4680);
        Ride::factory()->create(['driver_user_id' => $driver->id, 'status' => 'in_progress']);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'dispatch_pool' => 'fleet',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertRedirect();

        $rideRequest = RideRequest::query()->where('client_user_id', $client->id)->firstOrFail();
        $this->assertSame('waiting', $rideRequest->status);

        $this->actingAs($client)->post(route('ride-requests.cancel', $rideRequest))->assertRedirect();

        $this->assertSame('cancelled', $rideRequest->fresh()->status);
    }

    /**
     * A los 15 minutos (ExpireWaitingRideRequest) si nadie se desocupó a
     * tiempo, la solicitud en espera expira — mismo aviso que ya existe
     * para "nadie respondió a tiempo" en la cascada normal.
     */
    public function test_expire_if_still_waiting_marks_it_expired(): void
    {
        Event::fake([RideRequestExpired::class]);
        Notification::fake();

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'status' => 'waiting',
            'driver_user_id' => null,
            'dispatch_pool' => 'fleet',
        ]);

        RideDispatchAdvancer::expireIfStillWaiting($rideRequest->id);

        $this->assertSame('expired', $rideRequest->fresh()->status);
        Event::assertDispatched(RideRequestExpired::class);
        Notification::assertSentTo($client, RideRequestExpiredPushNotification::class);
    }

    public function test_the_latest_expired_request_stays_visible_after_reload_so_the_client_can_retry(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'status' => 'expired',
            'driver_user_id' => null,
            'dispatch_pool' => 'fleet',
            'requested_at' => now(),
        ]);

        $this->actingAs($client)->get(route('rides.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingRequestsAsClient.0.id', $rideRequest->id)
                ->where('pendingRequestsAsClient.0.status', 'expired')
            );
    }

    /**
     * Si para cuando corre el Job de los 15 min la solicitud ya se activó
     * (o se canceló), no hace nada — mismo criterio de protección que
     * advanceOrExpire() contra una carrera con el estado real.
     */
    public function test_expire_if_still_waiting_does_nothing_once_already_activated(): void
    {
        Event::fake([RideRequestExpired::class]);

        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $driver = $this->driverAt($fleet, $client, -0.1810, -78.4680);

        $rideRequest = RideRequest::factory()->create([
            'fleet_id' => $fleet->id,
            'client_user_id' => $client->id,
            'status' => 'pending',
            'driver_user_id' => $driver->id,
            'dispatch_pool' => 'fleet',
            'current_offer_expires_at' => now()->addSeconds(30),
        ]);

        RideDispatchAdvancer::expireIfStillWaiting($rideRequest->id);

        $this->assertSame('pending', $rideRequest->fresh()->status);
        Event::assertNotDispatched(RideRequestExpired::class);
    }

    /**
     * Cliente con flota y dos conductores candidatos (más cerca y más
     * lejos), ya con la solicitud creada y ofrecida al primero — reutilizado
     * por los tests de cascada.
     *
     * @return array{0: User, 1: Fleet, 2: User, 3: User}
     */
    private function sequentialRequestSetup(): array
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $first = $this->driverAt($fleet, $client, -0.1810, -78.4680);
        $second = $this->driverAt($fleet, $client, -0.2200, -78.5100);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'driver_user_id' => null,
            'dispatch_pool' => 'fleet',
            'origin_lat' => -0.1807,
            'origin_lng' => -78.4678,
            'destination_lat' => -0.2000,
            'destination_lng' => -78.5000,
        ])->assertRedirect();

        return [$client, $fleet, $first, $second];
    }
}
