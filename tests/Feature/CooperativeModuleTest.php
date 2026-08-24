<?php

namespace Tests\Feature;

use App\Models\ClientCooperative;
use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\DriverProfile;
use App\Models\RideRequest;
use App\Models\User;
use App\Models\WhatsAppSession;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
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

    public function test_public_cooperative_profile_aggregates_its_drivers_rides_and_reviews(): void
    {
        $this->seed(DemoDataSeeder::class);
        $cooperative = Cooperative::query()->where('name', 'Cooperativa Amazonas')->firstOrFail();

        $this->get(route('cooperatives.show', $cooperative))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Cooperative/Show')
                ->where('reputation.client_count', 2)
                ->where('reputation.completed_rides', 57)
                ->where('reputation.review_count', 57)
                ->has('drivers', 3)
                ->has('reviews', 20));
    }
}
