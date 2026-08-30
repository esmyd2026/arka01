<?php

namespace Tests\Feature\Fleet;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\TrustCircleConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: "Flotas a las que pertenecés" (lado
 * conductor) se veía muy larga — contadores (total/nuevos/con carreras/sin
 * carreras), filtro por cada uno, orden descendente y paginado.
 */
class DriverClientListTest extends TestCase
{
    use RefreshDatabase;

    private function membershipFor(User $driver, ?\DateTimeInterface $joinedAt = null): FleetMember
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        return FleetMember::factory()->for($fleet)->for($driver, 'driver')->create([
            'added_by' => $client->id,
            'joined_at' => $joinedAt ?? now()->subDays(60),
        ]);
    }

    public function test_the_stats_count_new_with_rides_and_without_rides(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        // Nuevo (se unió hace 5 días), sin carreras.
        $this->membershipFor($driver, now()->subDays(5));

        // Viejo, sin carreras.
        $this->membershipFor($driver, now()->subDays(90));

        // Viejo, con una carrera completada.
        $withRide = $this->membershipFor($driver, now()->subDays(90));
        Ride::factory()->create([
            'client_user_id' => $withRide->fleet->owner_user_id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
            'completed_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($driver)->get(route('driver.invitations.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('activeMembershipStats.total', 3)
            ->where('activeMembershipStats.nuevos', 1)
            ->where('activeMembershipStats.con_carreras', 1)
            ->where('activeMembershipStats.sin_carreras', 2)
        );
    }

    public function test_the_list_can_be_filtered_by_clients_without_rides(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $withoutRides = $this->membershipFor($driver);
        $withRides = $this->membershipFor($driver);
        Ride::factory()->create([
            'client_user_id' => $withRides->fleet->owner_user_id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
            'completed_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($driver)->get(route('driver.invitations.index', ['filter' => 'sin_carreras']));

        $response->assertInertia(fn ($page) => $page
            ->has('activeMemberships.data', 1)
            ->where('activeMemberships.data.0.id', $withoutRides->id)
        );
    }

    public function test_the_list_can_be_sorted_by_most_rides(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $fewRides = $this->membershipFor($driver);
        Ride::factory()->create([
            'client_user_id' => $fewRides->fleet->owner_user_id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
            'completed_at' => now()->subDay(),
        ]);

        $manyRides = $this->membershipFor($driver);
        Ride::factory()->count(3)->create([
            'client_user_id' => $manyRides->fleet->owner_user_id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
            'completed_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($driver)->get(route('driver.invitations.index', ['sort' => 'carreras']));

        $response->assertInertia(fn ($page) => $page
            ->where('activeMemberships.data.0.id', $manyRides->id)
            ->where('activeMemberships.data.1.id', $fewRides->id)
        );
    }

    public function test_the_list_is_paginated_at_ten_per_page(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        for ($i = 0; $i < 15; $i++) {
            $this->membershipFor($driver);
        }

        $response = $this->actingAs($driver)->get(route('driver.invitations.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('activeMemberships.data', 10)
            ->where('activeMemberships.total', 15)
            ->where('activeMembershipStats.total', 15)
        );
    }

    public function test_a_pending_invitation_shows_accepted_circle_people_who_are_already_driver_clients(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $invitingClient = User::factory()->create();
        $invitingFleet = Fleet::factory()->for($invitingClient, 'owner')->create();
        FleetInvitation::query()->create([
            'fleet_id' => $invitingFleet->id,
            'driver_user_id' => $driver->id,
            'invited_by' => $invitingClient->id,
            'initiated_by' => 'client',
            'status' => 'pending',
        ]);

        $knownClient = User::factory()->create();
        $knownFleet = Fleet::factory()->for($knownClient, 'owner')->create();
        FleetMember::factory()->for($knownFleet)->for($driver, 'driver')->create([
            'added_by' => $knownClient->id,
            'left_at' => null,
        ]);

        TrustCircleConnection::query()->create([
            'requester_user_id' => $invitingClient->id,
            'addressee_user_id' => $knownClient->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $unrelatedPerson = User::factory()->create();
        TrustCircleConnection::query()->create([
            'requester_user_id' => $invitingClient->id,
            'addressee_user_id' => $unrelatedPerson->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $this->actingAs($driver)
            ->get(route('driver.invitations.index'))
            ->assertInertia(fn ($page) => $page
                ->where('pendingInvitations.0.mutual_clients_count', 1)
                ->where('pendingInvitations.0.mutual_clients.0.public_id', $knownClient->public_id)
                ->where('pendingInvitations.0.mutual_clients.0.name', $knownClient->full_name)
                ->missing('pendingInvitations.0.mutual_clients.0.email')
                ->missing('pendingInvitations.0.mutual_clients.0.phone')
            );
    }

    public function test_an_active_client_shows_their_mutual_clients_without_private_data(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $activeClientMembership = $this->membershipFor($driver, now());
        $activeClient = $activeClientMembership->fleet->owner;
        $mutualClientMembership = $this->membershipFor($driver, now()->subDays(60));
        $mutualClient = $mutualClientMembership->fleet->owner;

        TrustCircleConnection::query()->create([
            'requester_user_id' => $activeClient->id,
            'addressee_user_id' => $mutualClient->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $this->actingAs($driver)
            ->get(route('driver.invitations.index'))
            ->assertInertia(fn ($page) => $page
                ->where('activeMemberships.data.0.mutual_clients_count', 1)
                ->where('activeMemberships.data.0.mutual_clients.0.public_id', $mutualClient->public_id)
                ->where('activeMemberships.data.0.mutual_clients.0.name', $mutualClient->full_name)
                ->missing('activeMemberships.data.0.mutual_clients.0.email')
                ->missing('activeMemberships.data.0.mutual_clients.0.phone')
            );
    }
}
