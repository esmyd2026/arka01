<?php

namespace Tests\Feature\Admin;

use App\Models\ChatbotMessage;
use App\Models\City;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Módulo de clientes registrados (pedido explícito del usuario): mismo
 * criterio que el panel de conductores, del otro lado — ver Admin\ClientController.
 */
class AdminClientControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_the_clients_panel(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.clients.index'))->assertForbidden();
    }

    public function test_the_list_only_includes_clients_not_drivers_or_admins(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->actingAs($admin)->get(route('admin.clients.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('clients.data', 1)
            ->where('clients.data.0.id', $client->id)
        );
    }

    public function test_the_list_can_be_filtered_by_city_and_search(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $quito = City::query()->where('name', 'Quito')->firstOrFail();
        $guayaquil = City::query()->where('name', 'Guayaquil')->firstOrFail();

        $match = User::factory()->create(['name' => 'Carlos Andrade', 'city_id' => $quito->id]);
        User::factory()->create(['name' => 'Pedro Salazar', 'city_id' => $guayaquil->id]);

        $response = $this->actingAs($admin)->get(route('admin.clients.index', ['q' => 'Andrade']));

        $response->assertInertia(fn ($page) => $page
            ->has('clients.data', 1)
            ->where('clients.data.0.id', $match->id)
        );

        $response = $this->actingAs($admin)->get(route('admin.clients.index', ['city_id' => $quito->id]));

        $response->assertInertia(fn ($page) => $page
            ->has('clients.data', 1)
            ->where('clients.data.0.id', $match->id)
        );
    }

    public function test_the_list_is_paginated_at_twenty_per_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        User::factory()->count(25)->create();

        $response = $this->actingAs($admin)->get(route('admin.clients.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('clients.data', 20)
            ->where('clients.total', 25)
        );
    }

    public function test_it_shows_how_many_drivers_are_in_the_clients_fleet(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        // "added_by" explícito en las tres (pedido implícito por la propia
        // factory: sin esto, FleetMemberFactory arma un User::factory() nuevo
        // para "added_by" en cada llamada — tres cuentas fantasma de sobra,
        // con role 'cliente' por defecto, que le ganaban a $client en el
        // orden alfabético de la lista).
        $driverOne = User::factory()->create();
        DriverProfile::factory()->for($driverOne)->create();
        FleetMember::factory()->for($fleet)->for($driverOne, 'driver')->create(['added_by' => $client->id]);

        $driverTwo = User::factory()->create();
        DriverProfile::factory()->for($driverTwo)->create();
        FleetMember::factory()->for($fleet)->for($driverTwo, 'driver')->create(['added_by' => $client->id]);

        // Un miembro que ya se fue no cuenta.
        $formerDriver = User::factory()->create();
        DriverProfile::factory()->for($formerDriver)->create();
        FleetMember::factory()->for($fleet)->for($formerDriver, 'driver')->create(['added_by' => $client->id, 'left_at' => now()]);

        $response = $this->actingAs($admin)->get(route('admin.clients.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('clients.data', 1)
            ->where('clients.data.0.id', $client->id)
            ->where('clients.data.0.drivers_count', 2)
        );
    }

    /**
     * Pedido explícito del usuario ("ayudame a ver la trazabilidad en el
     * panel administrativo... como tenemos en los bot que hemos
     * desarrollado mejor") — la transcripción completa de WhatsApp de un
     * cliente puntual, más el link directo a su ticket si tiene uno abierto.
     */
    public function test_the_client_detail_shows_the_whatsapp_transcript_and_the_open_ticket_link(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create(['phone' => '+593991234567']);
        ChatbotMessage::query()->create(['phone' => '+593991234567', 'user_id' => $client->id, 'direction' => 'in', 'body' => 'Hola']);
        ChatbotMessage::query()->create(['phone' => '+593991234567', 'user_id' => $client->id, 'direction' => 'out', 'body' => '¡Hola! ¿Qué necesita?']);
        $ticket = SupportTicket::factory()->for($client)->create(['status' => 'en_atencion']);

        $response = $this->actingAs($admin)->get(route('admin.clients.show', $client->id));

        $response->assertInertia(fn ($page) => $page
            ->component('Admin/ClientShow')
            ->has('messages', 2)
            ->where('open_ticket_id', $ticket->id)
        );
    }
}
