<?php

namespace Tests\Feature\Fleet;

use App\Events\FleetInvitationCreated;
use App\Models\City;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Models\FleetMember;
use App\Models\User;
use App\Notifications\FleetInvitationRespondedPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Segunda dirección de las invitaciones de flota, pedida explícitamente por
 * el usuario: además de que el cliente invite a un conductor
 * (FleetInvitationFlowTest), el conductor puede buscar un cliente existente y
 * mandarle una solicitud para unirse a su flota — la respuesta le toca al
 * cliente, no al conductor.
 */
class DriverInitiatedFleetInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_can_search_clients_by_member_code(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create(['name' => 'María Torres']);

        $response = $this->actingAs($driver)
            ->getJson(route('driver.clients.search', ['q' => (string) $client->member_code]));

        $response->assertOk();
        $response->assertJsonPath('clients.0.user_id', $client->id);
        $response->assertJsonPath('clients.0.status', 'not_invited');
    }

    /**
     * Corrección explícita del usuario sobre una restricción anterior
     * ("puedes habilitar para que busque por nombre y usuario tambien"):
     * vuelve a admitir nombre y usuario, con el mismo criterio que ya usaba
     * FleetDriverSearch del otro lado.
     */
    public function test_driver_can_search_clients_by_name_or_username(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create(['name' => 'María Torres']);

        $byName = $this->actingAs($driver)->getJson(route('driver.clients.search', ['q' => 'María']));
        $byName->assertJsonPath('clients.0.user_id', $client->id);

        $byUsername = $this->actingAs($driver)->getJson(route('driver.clients.search', ['q' => $client->username]));
        $byUsername->assertJsonPath('clients.0.user_id', $client->id);

        $byAtUsername = $this->actingAs($driver)->getJson(route('driver.clients.search', ['q' => '@'.$client->username]));
        $byAtUsername->assertJsonPath('clients.0.user_id', $client->id);
    }

    /**
     * Pedido explícito del usuario: "quitemos el número de teléfono y
     * agreguemos mejor la ciudad" en los resultados del buscador.
     */
    public function test_search_results_expose_the_clients_city_instead_of_their_phone(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $city = City::query()->create(['name' => 'Guayaquil', 'is_active' => true]);
        $client = User::factory()->create(['name' => 'María Torres', 'city_id' => $city->id]);

        $response = $this->actingAs($driver)
            ->getJson(route('driver.clients.search', ['q' => (string) $client->member_code]));

        $response->assertOk();
        $response->assertJsonPath('clients.0.city', 'Guayaquil');
        $response->assertJsonMissingPath('clients.0.phone');
    }

    public function test_search_only_returns_clients_not_drivers_or_the_searching_driver(): void
    {
        $driver = User::factory()->create(['name' => 'Pedro Conductor']);
        DriverProfile::factory()->for($driver)->create();

        $otherDriver = User::factory()->create(['name' => 'Pedro Otro']);
        DriverProfile::factory()->for($otherDriver)->create();

        $response = $this->actingAs($driver)
            ->getJson(route('driver.clients.search', ['q' => (string) $otherDriver->member_code]));

        $response->assertOk();
        $response->assertJsonCount(0, 'clients');
    }

    public function test_search_marks_a_client_already_in_the_fleet_as_member(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create(['name' => 'Ana Cliente']);
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $response = $this->actingAs($driver)
            ->getJson(route('driver.clients.search', ['q' => (string) $client->member_code]));

        $response->assertJsonPath('clients.0.status', 'member');
    }

    public function test_driver_can_send_a_join_request_creating_a_fleet_for_a_client_without_one(): void
    {
        Event::fake([FleetInvitationCreated::class]);

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create();

        $this->assertDatabaseMissing('fleets', ['owner_user_id' => $client->id]);

        $this->actingAs($driver)
            ->post(route('fleet-invitations.request'), ['client_user_id' => $client->id])
            ->assertRedirect();

        $fleet = Fleet::where('owner_user_id', $client->id)->firstOrFail();
        $this->assertDatabaseHas('fleet_invitations', [
            'fleet_id' => $fleet->id,
            'driver_user_id' => $driver->id,
            'invited_by' => $driver->id,
            'initiated_by' => 'driver',
            'status' => 'pending',
        ]);

        Event::assertDispatched(FleetInvitationCreated::class, fn ($event) => $event->invitation->respondingPartyId() === $client->id);
    }

    public function test_client_can_accept_a_driver_initiated_request(): void
    {
        Notification::fake();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create();

        $this->actingAs($driver)->post(route('fleet-invitations.request'), ['client_user_id' => $client->id]);
        $invitation = FleetInvitation::firstOrFail();

        $this->actingAs($client)
            ->post(route('driver.invitations.accept', $invitation))
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_invitations', ['id' => $invitation->id, 'status' => 'accepted']);
        $this->assertTrue(
            FleetMember::where('fleet_id', $invitation->fleet_id)
                ->where('driver_user_id', $driver->id)
                ->where('added_by', $client->id)
                ->whereNull('left_at')
                ->exists()
        );
        Notification::assertSentTo($driver, FleetInvitationRespondedPushNotification::class);
    }

    public function test_client_can_reject_a_driver_initiated_request(): void
    {
        Notification::fake();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create();

        $this->actingAs($driver)->post(route('fleet-invitations.request'), ['client_user_id' => $client->id]);
        $invitation = FleetInvitation::firstOrFail();

        $this->actingAs($client)
            ->post(route('driver.invitations.reject', $invitation))
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_invitations', ['id' => $invitation->id, 'status' => 'rejected']);
        $this->assertDatabaseMissing('fleet_members', ['driver_user_id' => $driver->id]);
        Notification::assertSentTo($driver, FleetInvitationRespondedPushNotification::class);
    }

    /**
     * Quien manda la solicitud no puede responderla él mismo — le toca a la
     * otra parte (ver FleetInvitationPolicy::respond()).
     */
    public function test_driver_cannot_accept_their_own_request(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create();

        $this->actingAs($driver)->post(route('fleet-invitations.request'), ['client_user_id' => $client->id]);
        $invitation = FleetInvitation::firstOrFail();

        $this->actingAs($driver)
            ->post(route('driver.invitations.accept', $invitation))
            ->assertForbidden();
    }

    /**
     * Bug real reportado por el usuario (403 en producción, con captura):
     * "Mis clientes de confianza" mostraba esta solicitud en "Invitaciones
     * recibidas" con los botones Aceptar/Rechazar activos — el conductor los
     * tocaba y el backend le respondía 403 (correcto, ver el test de
     * arriba), pero la pantalla nunca debió ofrecerle esa acción. Cubre
     * tanto la pantalla web (Driver/Invitations.vue) como los contadores que
     * avisan de la invitación en Inicio y en la barra de notificaciones.
     */
    public function test_a_driver_initiated_request_does_not_show_as_a_received_invitation(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create();

        $this->actingAs($driver)->post(route('fleet-invitations.request'), ['client_user_id' => $client->id]);

        $this->actingAs($driver)->get(route('driver.invitations.index'))->assertInertia(
            fn ($page) => $page->where('pendingInvitations', [])
        );

        $this->actingAs($driver)->get(route('dashboard'))->assertInertia(
            fn ($page) => $page->where('driverStats.pending_invitations', 0)
        );

        $this->actingAs($driver)->get(route('dashboard'))->assertInertia(
            fn ($page) => $page->where('auth.pendingFleetInvitationsCount', 0)
        );
    }

    public function test_driver_can_cancel_their_own_pending_request(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create();

        $this->actingAs($driver)->post(route('fleet-invitations.request'), ['client_user_id' => $client->id]);
        $invitation = FleetInvitation::firstOrFail();

        $this->actingAs($driver)
            ->delete(route('fleet.invitations.destroy', $invitation))
            ->assertRedirect();

        $this->assertDatabaseHas('fleet_invitations', ['id' => $invitation->id, 'status' => 'cancelled']);
    }
}
