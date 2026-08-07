<?php

namespace Tests\Feature\Fleet;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Review;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: "Conductores en tu flota" tenía mucho menos
 * detalle que el buscador de arriba (solo teléfono y tarifa) — ahora suma
 * foto, calificación, carreras completadas y categoría, mismo criterio que
 * FleetController::searchDrivers().
 */
class FleetShowMemberStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_fleet_screen_includes_rating_and_ride_count_for_each_member(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        Review::factory()->create(['reviewee_user_id' => $driver->id, 'rating' => 5]);
        Review::factory()->create(['reviewee_user_id' => $driver->id, 'rating' => 4]);
        Ride::factory()->create(['driver_user_id' => $driver->id, 'status' => 'completed']);

        $response = $this->actingAs($client)->get(route('fleet.show', $fleet));

        $response->assertInertia(fn ($page) => $page
            ->where("memberStats.{$driver->id}.average_rating", 4.5)
            ->where("memberStats.{$driver->id}.review_count", 2)
            ->where("memberStats.{$driver->id}.rides_count", 1)
            ->where("memberStats.{$driver->id}.active_clients_count", 1)
        );
    }

    public function test_a_member_without_any_reviews_shows_zero_stats(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $response = $this->actingAs($client)->get(route('fleet.show', $fleet));

        $response->assertInertia(fn ($page) => $page
            ->where("memberStats.{$driver->id}.average_rating", null)
            ->where("memberStats.{$driver->id}.review_count", 0)
            ->where("memberStats.{$driver->id}.rides_count", 0)
        );
    }
}
