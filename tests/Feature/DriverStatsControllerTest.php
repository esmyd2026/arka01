<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\Review;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Mis indicadores" (pedido explícito del usuario: "el reporte de conductor
 * se siente pobre" — trazabilidad, filtros, tarjetas, segmentación y
 * medallas) — ver App\Http\Controllers\DriverStatsController.
 */
class DriverStatsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function driver(): User
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        return $driver;
    }

    public function test_a_client_cannot_access_the_stats_screen(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->get(route('rides.stats'))->assertRedirect(route('dashboard'));
    }

    public function test_the_cards_total_only_this_drivers_rides(): void
    {
        $driver = $this->driver();
        $otherDriver = $this->driver();
        $client = User::factory()->create();

        Ride::factory()->for($client, 'client')->for($driver, 'driver')->create(['status' => 'completed', 'price' => 5, 'distance_km' => 2]);
        Ride::factory()->for($client, 'client')->for($driver, 'driver')->create(['status' => 'completed', 'price' => 7, 'distance_km' => 3]);
        Ride::factory()->for($client, 'client')->for($driver, 'driver')->create(['status' => 'cancelled', 'price' => 4, 'distance_km' => 1]);
        // De otro conductor: no debería sumar acá.
        Ride::factory()->for($client, 'client')->for($otherDriver, 'driver')->create(['status' => 'completed', 'price' => 100, 'distance_km' => 50]);

        $response = $this->actingAs($driver)->get(route('rides.stats'));

        $response->assertInertia(fn ($page) => $page
            ->where('totals.total', 3)
            ->where('totals.completed', 2)
            ->where('totals.cancelled', 1)
            ->where('totals.earnings', 12)
            ->where('totals.distance_km', 5)
        );
    }

    public function test_the_date_filter_excludes_rides_outside_the_range(): void
    {
        $driver = $this->driver();
        $client = User::factory()->create();

        Carbon::setTestNow('2026-08-01 12:00:00');
        Ride::factory()->for($client, 'client')->for($driver, 'driver')->create(['status' => 'completed', 'price' => 5]);

        Carbon::setTestNow('2026-08-10 12:00:00');
        Ride::factory()->for($client, 'client')->for($driver, 'driver')->create(['status' => 'completed', 'price' => 9]);
        Carbon::setTestNow();

        $response = $this->actingAs($driver)->get(route('rides.stats', ['from' => '2026-08-05', 'to' => '2026-08-15']));

        $response->assertInertia(fn ($page) => $page
            ->where('totals.total', 1)
            ->where('totals.earnings', 9)
        );
    }

    public function test_the_status_breakdown_ignores_the_status_filter(): void
    {
        $driver = $this->driver();
        $client = User::factory()->create();

        Ride::factory()->for($client, 'client')->for($driver, 'driver')->create(['status' => 'completed']);
        Ride::factory()->for($client, 'client')->for($driver, 'driver')->create(['status' => 'cancelled']);

        // Pedido explícito del diseño: filtrar "solo completadas" en la
        // tabla no debería dejar la torta con una sola porción.
        $response = $this->actingAs($driver)->get(route('rides.stats', ['status' => 'completed']));

        $response->assertInertia(fn ($page) => $page
            ->where('totals.total', 1)
            ->where('statusBreakdown.completed', 1)
            ->where('statusBreakdown.cancelled', 1)
        );
    }

    public function test_gamification_reports_the_current_and_next_tier(): void
    {
        $driver = $this->driver();
        $driver->driverProfile()->update(['total_points' => 150]);

        $response = $this->actingAs($driver)->get(route('rides.stats'));

        $response->assertInertia(fn ($page) => $page
            ->where('gamification.total_points', 150)
            ->where('gamification.tier.name', 'Plata')
            ->where('gamification.next_tier.name', 'Oro')
        );
    }

    public function test_gamification_has_no_next_tier_at_the_top(): void
    {
        $driver = $this->driver();
        $driver->driverProfile()->update(['total_points' => 1000]);

        $response = $this->actingAs($driver)->get(route('rides.stats'));

        $response->assertInertia(fn ($page) => $page
            ->where('gamification.tier.name', 'Diamante')
            ->where('gamification.next_tier', null)
        );
    }

    public function test_history_is_paginated_at_twenty_per_page(): void
    {
        $driver = $this->driver();
        $client = User::factory()->create();

        Ride::factory()->for($client, 'client')->for($driver, 'driver')->count(25)->create(['status' => 'completed']);

        $response = $this->actingAs($driver)->get(route('rides.stats'));

        $response->assertInertia(fn ($page) => $page
            ->has('history.data', 20)
            ->where('history.total', 25)
        );
    }

    public function test_average_rating_comes_from_reviews_received(): void
    {
        $driver = $this->driver();
        $client = User::factory()->create();
        $ride = Ride::factory()->for($client, 'client')->for($driver, 'driver')->create(['status' => 'completed']);

        Review::query()->create([
            'ride_id' => $ride->id,
            'reviewer_user_id' => $client->id,
            'reviewee_user_id' => $driver->id,
            'rating' => 4,
        ]);

        $response = $this->actingAs($driver)->get(route('rides.stats'));

        $response->assertInertia(fn ($page) => $page
            ->where('totals.average_rating', 4)
            ->where('totals.review_count', 1)
        );
    }
}
