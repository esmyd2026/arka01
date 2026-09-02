<?php

namespace Tests\Feature;

use App\Events\CooperativeRideUpdated;
use App\Jobs\FallbackCooperativeAssignment;
use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\DriverProfile;
use App\Models\RideRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WhatsAppSession;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CooperativeModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cooperative_account_is_created_from_registration(): void
    {
        $response = $this->post('/register', [
            'account_type' => 'cooperativa',
            'name' => 'Cooperativa Norte',
            'email' => 'cooperativa@example.com',
            'country_code' => '+593',
            'phone_local' => '997654321',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $user = User::query()->where('email', 'cooperativa@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->fresh()->isCooperative());
        $this->assertDatabaseHas('cooperatives', ['user_id' => $user->id, 'status' => 'pending']);
        $response->assertRedirect(route('cooperative.profile.edit'));
    }

    public function test_driver_must_accept_a_cooperative_invitation(): void
    {
        Notification::fake();

        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $cooperativeUser->id,
            'name' => 'Coop Uno',
        ]);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $driver = User::factory()->create();
        DriverProfile::factory()->create(['user_id' => $driver->id, 'driver_type' => 'independent']);

        $this->actingAs($cooperativeUser)
            ->post(route('cooperative.drivers.invite'), ['driver_user_id' => $driver->id])
            ->assertRedirect();

        $membership = CooperativeDriverMembership::query()->firstOrFail();
        $this->assertSame('pending', $membership->status);

        $this->actingAs($driver)
            ->post(route('cooperative-driver-invitations.respond', $membership), ['decision' => 'accept'])
            ->assertRedirect();

        $this->assertSame('accepted', $membership->fresh()->status);
        $this->assertSame('public_transport', $driver->driverProfile->fresh()->driver_type);
    }

    /**
     * Bug reportado por el usuario ("no le llega la solicitud que le manda
     * la cooperativa para que se una"): la invitación solo se mandaba por
     * Web Push, que falla en silencio sin permiso del navegador — ahora
     * también le llega por WhatsApp si tiene la ventana de 24h abierta,
     * mismo criterio que WhatsAppFreeformSender::sendNewRideAlert().
     */
    public function test_a_driver_with_an_active_whatsapp_session_also_gets_the_invitation_by_whatsapp(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);

        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Uno']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $driver = User::factory()->create(['phone' => '+593991234567']);
        DriverProfile::factory()->create(['user_id' => $driver->id, 'driver_type' => 'independent']);
        WhatsAppSession::query()->create(['user_id' => $driver->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);

        $this->actingAs($cooperativeUser)
            ->post(route('cooperative.drivers.invite'), ['driver_user_id' => $driver->id])
            ->assertRedirect();

        Http::assertSent(fn ($request) => str_contains($request['text']['body'] ?? '', 'Coop Uno')
            && str_contains($request['text']['body'] ?? '', 'vincularlo'));
    }

    /**
     * Pedido explícito del usuario: "le deberia llegar en la pantalla de
     * solicitudes al conductor tambien. como cuando un cliente le manda la
     * solicitud" — antes la invitación solo vivía en
     * /cooperativas/invitaciones, una pantalla aparte y fácil de no
     * encontrar. Ahora también aparece en /carreras (RideController::index()).
     */
    public function test_a_pending_cooperative_invitation_shows_up_on_the_driver_ride_requests_screen(): void
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Norte']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $driver = User::factory()->create();
        DriverProfile::factory()->create(['user_id' => $driver->id, 'driver_type' => 'independent']);
        $membership = CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeUser->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($driver)->get(route('rides.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->has('pendingCooperativeInvitations', 1)
            ->where('pendingCooperativeInvitations.0.id', $membership->id)
            ->where('pendingCooperativeInvitations.0.cooperative.name', 'Coop Norte')
        );
    }

    public function test_cooperative_profile_can_be_saved_as_an_incomplete_draft(): void
    {
        $user = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post(route('cooperative.profile.update'), [
            'name' => 'Cooperativa Amazonas',
            'main_address' => 'La Alborada, Guayaquil, Ecuador',
            'stand_lat' => -2.1450000,
            'stand_lng' => -79.8950000,
            'declared_driver_count' => 3,
            'declared_unit_count' => 3,
            'response_timeout_seconds' => 30,
            'automatic_assignment_enabled' => true,
            'manual_assignment_timeout_seconds' => 30,
            // Cobertura, horario y documentos todavía incompletos a propósito.
        ])->assertRedirect()->assertSessionHasNoErrors();

        $cooperative->refresh();
        $this->assertSame('Cooperativa Amazonas', $cooperative->name);
        $this->assertSame('La Alborada, Guayaquil, Ecuador', $cooperative->main_address);
        $this->assertSame('-2.1450000', $cooperative->stand_lat);
        $this->assertNull($cooperative->geographic_coverage);
        $this->assertNull($cooperative->submitted_at);
    }

    /**
     * Pedido explícito del usuario ("mejoremos la privacidad de las
     * cooperativas") — persistencia simple del toggle nuevo, mismo endpoint
     * que ya guarda el resto del perfil (borrador o completo).
     */
    public function test_a_cooperative_can_turn_off_showing_their_fleet_publicly(): void
    {
        $user = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $user->id, 'show_fleet_publicly' => true]);

        $this->actingAs($user)->post(route('cooperative.profile.update'), [
            'declared_driver_count' => 3,
            'declared_unit_count' => 3,
            'response_timeout_seconds' => 30,
            'automatic_assignment_enabled' => true,
            'manual_assignment_timeout_seconds' => 30,
            'show_fleet_publicly' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($cooperative->fresh()->show_fleet_publicly);
    }

    /**
     * Documentos legales de más los datos obligatorios que ya exige
     * submitForReview() — deja la cooperativa lista para enviar a
     * validación salvo por lo que el test decida omitir (ej. el seguro).
     */
    private function completeCooperativeDocuments(Cooperative $cooperative): void
    {
        foreach (['ruc' => 'RUC', 'legal_appointment' => 'Nombramiento', 'operating_authorization' => 'Habilitante', 'operating_permit' => 'Permiso'] as $type => $label) {
            $cooperative->documents()->create([
                'type' => $type, 'label' => $label, 'path' => "cooperative-documents/{$cooperative->id}/{$type}.pdf",
                'original_name' => "{$type}.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 1024, 'status' => 'pending',
            ]);
        }
    }

    /**
     * Pedido explícito del usuario: la cooperativa declara con un checkbox
     * si cuenta con un seguro que proteja al representante, a los
     * conductores y a los vehículos — se exige recién al enviar a
     * validación, no en cada guardado parcial.
     */
    public function test_submitting_for_review_requires_declaring_insurance(): void
    {
        $user = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $user->id, 'name' => 'Coop Sur', 'legal_name' => 'Coop Sur S.A.', 'ruc' => '1234567890001',
            'main_address' => 'Av. Principal', 'stand_lat' => -2.17, 'stand_lng' => -79.92, 'city_id' => null,
            'province' => 'Guayas', 'phone' => '0999999999', 'email' => 'coop@example.com', 'legal_representative' => 'Juan Pérez',
            'geographic_coverage' => 'Guayaquil', 'operating_hours' => '24 horas',
        ]);
        $this->completeCooperativeDocuments($cooperative);

        $this->actingAs($user)->post(route('cooperative.profile.submit-review'))
            ->assertSessionHasErrors('has_insurance');

        $this->assertNull($cooperative->fresh()->submitted_at);
    }

    public function test_an_admin_cannot_approve_a_cooperative_without_declared_insurance(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $user->id, 'name' => 'Coop Norte']);
        $this->completeCooperativeDocuments($cooperative);

        $this->actingAs($admin)->post(route('admin.cooperatives.approve', $cooperative))
            ->assertSessionHasErrors('cooperative');

        $this->assertNotSame('approved', $cooperative->fresh()->status);
    }

    public function test_an_admin_can_mark_a_cooperative_as_public(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $user->id, 'name' => 'Coop Norte']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $this->actingAs($admin)
            ->patch(route('admin.cooperatives.public-visibility', $cooperative), ['is_public' => true])
            ->assertRedirect();

        $this->assertTrue($cooperative->fresh()->is_public);
    }

    /**
     * Pedido explícito del usuario: la pantalla admin de cooperativas
     * ("Gestión de cooperativas") no mostraba si eran públicas, si su flota
     * era visible, ni cuántos clientes tenían — ver
     * Admin\CooperativeController::index() y Admin/Cooperatives/Index.vue.
     */
    public function test_the_admin_index_lists_visibility_client_count_and_plan(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $cooperativeOwner = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $cooperativeOwner->id,
            'name' => 'Coop Norte',
            'is_public' => true,
            'show_fleet_publicly' => true,
        ]);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $client = User::factory()->create();
        ClientCooperative::query()->create(['client_user_id' => $client->id, 'cooperative_id' => $cooperative->id]);

        $plan = SubscriptionPlan::query()->where('owner_type', 'cooperative')->orderBy('sort_order')->firstOrFail();
        Subscription::factory()->for($cooperativeOwner)->create(['subscription_plan_id' => $plan->id, 'status' => 'active']);

        $response = $this->actingAs($admin)->get(route('admin.cooperatives.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('cooperatives.data.0.is_public', true)
            ->where('cooperatives.data.0.show_fleet_publicly', true)
            ->where('cooperatives.data.0.client_links_count', 1)
            ->where('cooperatives.data.0.plan_name', $plan->name)
        );
    }

    public function test_a_regular_user_cannot_mark_a_cooperative_as_public(): void
    {
        $user = User::factory()->create();
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Norte']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $this->actingAs($user)
            ->patch(route('admin.cooperatives.public-visibility', $cooperative), ['is_public' => true])
            ->assertForbidden();

        $this->assertFalse($cooperative->fresh()->is_public);
    }

    public function test_a_client_can_request_a_ride_from_an_attached_cooperative(): void
    {
        Bus::fake();
        Notification::fake();

        $client = User::factory()->create();
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $cooperativeUser->id,
            'name' => 'Coop Centro',
            'response_timeout_seconds' => 45,
        ]);
        $cooperative->forceFill(['status' => 'approved'])->save();
        ClientCooperative::query()->create(['client_user_id' => $client->id, 'cooperative_id' => $cooperative->id]);

        $driver = User::factory()->create();
        DriverProfile::factory()->create([
            'user_id' => $driver->id,
            'driver_type' => 'public_transport',
            'current_lat' => -2.1700,
            'current_lng' => -79.9000,
            'passenger_capacity' => 4,
        ]);
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeUser->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
        // Pedido explícito del usuario: un conductor de cooperativa necesita
        // un plan pago vigente para que el despacho automático lo considere
        // (ver PlanLimits::hasActivePaidPlan()).
        $plan = SubscriptionPlan::query()->where('owner_type', 'driver')->where('code', 'plus')->firstOrFail();
        Subscription::factory()->for($driver)->create(['subscription_plan_id' => $plan->id, 'status' => 'active']);

        $response = $this->actingAs($client)->post(route('ride-requests.store'), [
            'cooperative_id' => $cooperative->id,
            'origin_lat' => -2.1701,
            'origin_lng' => -79.9001,
            'origin_address' => 'Origen de prueba',
            'destination_lat' => -2.1800,
            'destination_lng' => -79.9100,
            'destination_address' => 'Destino de prueba',
            'passenger_count' => 1,
            'needs_trunk' => false,
            'payment_method' => 'efectivo',
        ]);

        $response->assertRedirect(route('rides.index'));
        $rideRequest = RideRequest::query()->latest('id')->firstOrFail();
        $this->assertSame($cooperative->id, $rideRequest->cooperative_id);
        $this->assertSame($driver->id, $rideRequest->driver_user_id);
        $this->assertSame('cooperative', $rideRequest->dispatch_pool);
        $this->assertSame('awaiting_driver', $rideRequest->cooperative_assignment_status);
        $this->assertNotNull($rideRequest->cooperative_offer_expires_at);
    }

    public function test_cooperative_mode_never_falls_back_to_the_clients_fleet_without_a_cooperative(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'provider_type' => 'cooperative',
            'cooperative_id' => null,
            'origin_lat' => -2.17,
            'origin_lng' => -79.90,
            'destination_lat' => -2.18,
            'destination_lng' => -79.91,
        ])->assertSessionHasErrors('cooperative_id');

        $this->assertDatabaseCount('ride_requests', 0);
    }

    public function test_manual_cooperative_dispatch_waits_for_the_operator_and_never_notifies_a_driver_first(): void
    {
        Bus::fake();
        Notification::fake();
        Event::fake([CooperativeRideUpdated::class]);

        $client = User::factory()->create();
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $cooperativeUser->id,
            'name' => 'Coop Manual',
            'automatic_assignment_enabled' => false,
            'rate_per_km' => 0.50,
            'driver_pay_rate_per_km' => 0.30,
        ]);
        $cooperative->forceFill(['status' => 'approved'])->save();
        ClientCooperative::query()->create(['client_user_id' => $client->id, 'cooperative_id' => $cooperative->id]);

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'provider_type' => 'cooperative',
            'cooperative_id' => $cooperative->id,
            'origin_lat' => -2.1701,
            'origin_lng' => -79.9001,
            'destination_lat' => -2.1800,
            'destination_lng' => -79.9100,
        ])->assertSessionHasNoErrors()->assertRedirect(route('rides.index'));

        $rideRequest = RideRequest::query()->latest('id')->firstOrFail();
        $this->assertNull($rideRequest->driver_user_id);
        $this->assertNull($rideRequest->dispatch_pool);
        $this->assertSame('awaiting_operator', $rideRequest->cooperative_assignment_status);
        // Pedido explícito del usuario: en modo manual nadie recibe la
        // solicitud de entrada, pero si el operador no asigna a tiempo debe
        // haber un respaldo — acotado siempre a los conductores de ESTA
        // cooperativa (ver RideDispatchAdvancer::startCooperativeDispatch()).
        Bus::assertDispatched(
            FallbackCooperativeAssignment::class,
            fn (FallbackCooperativeAssignment $job) => $job->rideRequestId === $rideRequest->id,
        );

        // Reproduciendo el reporte del usuario ("no está llegando el sonido
        // al panel de la cooperativa cuando le llega una carrera"): si este
        // evento nunca se dispara, o su canal no resuelve al user_id de la
        // cooperativa, Cooperative/Dashboard.vue jamás recibe el WebSocket
        // que dispara playIncomingRideAlert().
        Event::assertDispatched(CooperativeRideUpdated::class, function (CooperativeRideUpdated $event) use ($rideRequest, $cooperativeUser) {
            $channels = $event->broadcastOn();
            return $event->rideRequest->id === $rideRequest->id
                && $event->action === 'created'
                && count($channels) === 1
                && $channels[0]->name === "private-App.Models.User.{$cooperativeUser->id}";
        });
    }

    /**
     * El respaldo que vence el modo manual sustituye al operador, por lo que
     * debe usar las mismas unidades que este podía seleccionar en el panel.
     * La regla de plan pago continúa aplicando al modo 100% automático, pero
     * no puede provocar que una unidad afiliada y libre termine cancelando
     * una carrera que el operador dejó vencer.
     */
    public function test_manual_timeout_assigns_an_available_affiliated_driver_instead_of_expiring_the_request(): void
    {
        Notification::fake();
        Bus::fake();

        $client = User::factory()->create();
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $cooperativeUser->id,
            'name' => 'Coop con respaldo',
            'automatic_assignment_enabled' => false,
            'response_timeout_seconds' => 30,
        ]);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'driver_type' => 'public_transport',
            'is_available' => true,
            'current_lat' => -2.1700,
            'current_lng' => -79.9000,
            'passenger_capacity' => 4,
        ]);
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeUser->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $rideRequest = RideRequest::factory()->create([
            'client_user_id' => $client->id,
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => null,
            'status' => 'pending',
            'cooperative_assignment_status' => 'awaiting_operator',
            'origin_lat' => -2.1701,
            'origin_lng' => -79.9001,
            'passenger_count' => 1,
            'needs_trunk' => false,
        ]);

        (new FallbackCooperativeAssignment($rideRequest->id))->handle();

        $rideRequest->refresh();
        $this->assertSame('pending', $rideRequest->status);
        $this->assertSame($driver->id, $rideRequest->driver_user_id);
        $this->assertSame('cooperative', $rideRequest->dispatch_pool);
        $this->assertSame('awaiting_driver', $rideRequest->cooperative_assignment_status);
        $this->assertNotNull($rideRequest->current_offer_expires_at);
    }

    public function test_a_client_cannot_request_from_an_unattached_cooperative(): void
    {
        $client = User::factory()->create();
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Ajena']);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'cooperative_id' => $cooperative->id,
            'origin_lat' => -2.17,
            'origin_lng' => -79.90,
            'destination_lat' => -2.18,
            'destination_lng' => -79.91,
        ])->assertSessionHasErrors('cooperative_id');

        $this->assertDatabaseCount('ride_requests', 0);
    }

    /**
     * Pedido explícito del usuario: un admin puede marcar una cooperativa
     * como pública (Cooperative.is_public) para que aparezca en "Elige tu
     * conductor" de CUALQUIER cliente, sin que la haya agregado a su red
     * (ClientCooperative) — ver RideRequestController::create().
     */
    public function test_a_public_cooperative_is_listed_to_a_client_who_never_added_it(): void
    {
        $client = User::factory()->create();
        $cooperativeUser = User::factory()->create();
        $publicCooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Pública', 'is_public' => true]);
        $publicCooperative->forceFill(['status' => 'approved'])->save();

        $otherUser = User::factory()->create();
        $privateCooperative = Cooperative::query()->create(['user_id' => $otherUser->id, 'name' => 'Coop Privada']);
        $privateCooperative->forceFill(['status' => 'approved'])->save();

        $response = $this->actingAs($client)->get(route('ride-requests.create'));

        $response->assertInertia(fn ($page) => $page
            ->where('cooperatives.0.id', $publicCooperative->id)
            ->where('cooperatives.0.is_public', true)
            ->has('cooperatives', 1)
        );
    }

    /**
     * Pedido explícito del usuario: "la que tiene que estar seleccionada por
     * defecto tiene que ser al de su flota" — el listado debe distinguir con
     * is_added la cooperativa que el cliente agregó a su propia red de la
     * que solo aparece por ser pública, para que el front la priorice al
     * recomendar aunque la pública gane en precio o cercanía.
     */
    public function test_a_client_added_cooperative_is_flagged_apart_from_a_public_one(): void
    {
        $client = User::factory()->create();

        $addedOwner = User::factory()->create();
        $addedCooperative = Cooperative::query()->create(['user_id' => $addedOwner->id, 'name' => 'Coop De Mi Red']);
        $addedCooperative->forceFill(['status' => 'approved'])->save();
        ClientCooperative::query()->create(['client_user_id' => $client->id, 'cooperative_id' => $addedCooperative->id]);

        $publicOwner = User::factory()->create();
        $publicCooperative = Cooperative::query()->create(['user_id' => $publicOwner->id, 'name' => 'Coop Pública', 'is_public' => true]);
        $publicCooperative->forceFill(['status' => 'approved'])->save();

        $response = $this->actingAs($client)->get(route('ride-requests.create'));

        $response->assertInertia(function ($page) use ($addedCooperative, $publicCooperative) {
            $cooperatives = collect($page->toArray()['props']['cooperatives'])->keyBy('id');
            $this->assertTrue($cooperatives[$addedCooperative->id]['is_added']);
            $this->assertFalse($cooperatives[$publicCooperative->id]['is_added']);
        });
    }

    /**
     * Misma corrección: la validación al ENVIAR la solicitud (no solo el
     * listado) también tiene que aceptar una cooperativa pública sin
     * vínculo explícito — si no, el cliente la vería en la lista pero el
     * envío fallaría igual.
     */
    public function test_a_client_can_request_a_ride_from_a_public_cooperative_without_adding_it(): void
    {
        $client = User::factory()->create();
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Pública', 'is_public' => true]);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $this->actingAs($client)->post(route('ride-requests.store'), [
            'cooperative_id' => $cooperative->id,
            'origin_lat' => -2.17,
            'origin_lng' => -79.90,
            'destination_lat' => -2.18,
            'destination_lng' => -79.91,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('ride_requests', ['cooperative_id' => $cooperative->id, 'client_user_id' => $client->id]);
        $this->assertDatabaseMissing('client_cooperatives', ['client_user_id' => $client->id, 'cooperative_id' => $cooperative->id]);
    }

    /**
     * Pedido explícito del usuario ("mejoremos la privacidad de las
     * cooperativas... si no, que salga solo las cantidades y los
     * conductores como bloqueados"): con el toggle apagado, un desconocido
     * ve la cantidad (ya la traía reputation.driver_count) pero no la lista
     * real de conductores.
     */
    public function test_a_stranger_does_not_see_the_driver_roster_when_the_fleet_is_private(): void
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $cooperativeUser->id, 'name' => 'Coop Privada', 'show_fleet_publicly' => false,
        ]);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $driver = User::factory()->create();
        DriverProfile::factory()->create(['user_id' => $driver->id, 'driver_type' => 'public_transport']);
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id, 'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeUser->id, 'status' => 'accepted', 'responded_at' => now(),
        ]);

        $viewer = User::factory()->create();
        $response = $this->actingAs($viewer)->get(route('cooperatives.show', $cooperative->public_id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('fleetVisible', false)
            ->has('drivers', 0)
            ->where('reputation.driver_count', 1)
        );
    }

    public function test_the_cooperative_owner_still_sees_their_own_private_roster(): void
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $cooperativeUser->id, 'name' => 'Coop Privada', 'show_fleet_publicly' => false,
        ]);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $driver = User::factory()->create();
        DriverProfile::factory()->create(['user_id' => $driver->id, 'driver_type' => 'public_transport']);
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id, 'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeUser->id, 'status' => 'accepted', 'responded_at' => now(),
        ]);

        $response = $this->actingAs($cooperativeUser)->get(route('cooperatives.show', $cooperative->public_id));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('fleetVisible', true)
            ->has('drivers', 1)
        );
    }

    public function test_public_cooperative_profile_aggregates_its_drivers_rides_and_reviews(): void
    {
        $this->seed(DemoDataSeeder::class);
        $cooperative = Cooperative::query()->where('name', 'Cooperativa Amazonas')->firstOrFail();

        $this->get(route('cooperatives.show', $cooperative->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Cooperative/Show')
                ->where('reputation.client_count', 2)
                ->where('reputation.completed_rides', 57)
                ->where('reputation.review_count', 57)
                ->has('drivers', 3)
                ->has('reviews', 20));
    }

    public function test_a_numeric_cooperative_id_cannot_open_its_public_profile(): void
    {
        $owner = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $owner->id,
            'name' => 'Cooperativa con enlace privado',
        ]);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $this->get('/cooperativas/'.$cooperative->id)->assertNotFound();
        $this->get(route('cooperatives.show', $cooperative->public_id))->assertOk();
    }

    /**
     * Bug reportado por el usuario: el menú de cuenta del conductor no se
     * desplegaba. Causa real: CooperativeDriverMembership::activeCooperativeFor()
     * cargaba la cooperativa con ->with('cooperative:id,name,logo_path') —
     * sin public_id, AuthenticatedLayout.vue armaba
     * route('cooperatives.show', null) y Ziggy tiraba una excepción en cada
     * render, dejando el dropdown roto.
     */
    public function test_the_shared_cooperative_prop_includes_a_public_id(): void
    {
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $cooperativeUser->id,
            'name' => 'Coop con conductor afiliado',
        ]);
        $cooperative->forceFill(['status' => 'approved'])->save();

        $driver = User::factory()->create();
        DriverProfile::factory()->create(['user_id' => $driver->id, 'driver_type' => 'independent']);
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id,
            'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeUser->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $this->actingAs($driver)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.cooperative.public_id', $cooperative->public_id));
    }
}
