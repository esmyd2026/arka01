<?php

namespace Tests\Feature;

use App\Models\Cooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Models\FleetMember;
use App\Models\RideRequest;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Props compartidos globalmente vía HandleInertiaRequests::share() — tienen
 * que verse en CUALQUIER pantalla, no solo en Inicio, porque alimentan la
 * nav (AuthenticatedLayout.vue), visible en toda la app.
 */
class SharedInertiaPropsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pedido explícito del usuario: "coloca solicitudes en el navbar cuando
     * sea movil alli donde esta el boton de home" — el badge de la pestaña
     * "Carreras" necesita este número en cualquier pantalla.
     */
    public function test_pending_ride_requests_count_is_shared_for_a_driver_on_any_page(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $fleet = Fleet::factory()->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $fleet->owner_user_id]);
        RideRequest::factory()->for($fleet)->create(['driver_user_id' => null, 'status' => 'pending']);
        RideRequest::factory()->for($fleet)->create(['driver_user_id' => null, 'status' => 'pending']);

        $response = $this->actingAs($driver)->get(route('driver.profile.edit'));

        $response->assertInertia(fn ($page) => $page->where('auth.pendingRideRequestsCount', 2));
    }

    /**
     * Pedido explícito del usuario: "carreras y solicitudes es la misma
     * pagina... quitemos carreras y coloquemos clientes" — el tab
     * "Clientes" de la nav inferior (antes "Carreras") necesita este
     * número en cualquier pantalla.
     */
    public function test_pending_fleet_invitations_count_is_shared_for_a_driver_on_any_page(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $fleet = Fleet::factory()->create();
        FleetInvitation::query()->create([
            'fleet_id' => $fleet->id,
            'driver_user_id' => $driver->id,
            'invited_by' => $fleet->owner_user_id,
            'initiated_by' => 'client',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($driver)->get(route('driver.profile.edit'));

        $response->assertInertia(fn ($page) => $page->where('auth.pendingFleetInvitationsCount', 1));
    }

    public function test_pending_fleet_invitations_count_is_zero_for_a_client(): void
    {
        $client = User::factory()->create();

        $response = $this->actingAs($client)->get(route('profile.edit'));

        $response->assertInertia(fn ($page) => $page->where('auth.pendingFleetInvitationsCount', 0));
    }

    public function test_pending_ride_requests_count_is_zero_for_a_client(): void
    {
        $client = User::factory()->create();

        $response = $this->actingAs($client)->get(route('profile.edit'));

        $response->assertInertia(fn ($page) => $page->where('auth.pendingRideRequestsCount', 0));
    }

    /**
     * Pedido explícito del usuario: "eso es para que el sepa que pertenece
     * a una cooperativa, colocalo alli [menú de cuenta] como una etiqueta
     * mas con su enlace... debajo de la que dice conductor" — antes solo
     * vivía en Inicio (Dashboard.vue).
     */
    public function test_the_drivers_active_cooperative_is_shared_on_any_page(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $cooperativeUser = User::factory()->create();
        $cooperative = Cooperative::query()->create(['user_id' => $cooperativeUser->id, 'name' => 'Coop Norte']);
        CooperativeDriverMembership::query()->create([
            'cooperative_id' => $cooperative->id, 'driver_user_id' => $driver->id,
            'invited_by_user_id' => $cooperativeUser->id, 'status' => 'accepted', 'responded_at' => now(),
        ]);

        $response = $this->actingAs($driver)->get(route('driver.profile.edit'));

        $response->assertInertia(fn ($page) => $page
            ->where('auth.cooperative.id', $cooperative->id)
            ->where('auth.cooperative.name', 'Coop Norte')
        );
    }

    public function test_an_independent_driver_has_no_cooperative_shared(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->actingAs($driver)->get(route('driver.profile.edit'));

        $response->assertInertia(fn ($page) => $page->where('auth.cooperative', null));
    }

    /**
     * Pedido explícito del usuario: "permiteme en el modulo de sistema de
     * habilitar o no estas opciones del menu" — la lista de rutas apagadas
     * se comparte para que AuthenticatedLayout.vue filtre `quickLinks` en
     * cualquier pantalla.
     */
    public function test_disabled_quick_links_are_shared_when_set(): void
    {
        SiteSetting::current()->update(['disabled_quick_links' => ['driver.plan.edit']]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response->assertInertia(fn ($page) => $page->where('disabledQuickLinks', ['driver.plan.edit']));
    }

    public function test_disabled_quick_links_default_to_an_empty_array(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('profile.edit'));

        $response->assertInertia(fn ($page) => $page->where('disabledQuickLinks', []));
    }
}
