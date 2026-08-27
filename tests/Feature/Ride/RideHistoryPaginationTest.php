<?php

namespace Tests\Feature\Ride;

use App\Models\Review;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Rediseño del historial de /carreras (pedido explícito del usuario: "este
 * diseño puede cambiar que sea mas profesional... asegura que tenga
 * paginado y colocale al menos la fecha y hora de la carrera") — ver
 * RideController::index().
 */
class RideHistoryPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_history_is_paginated_at_ten_per_page(): void
    {
        $client = User::factory()->create();
        Ride::factory()->count(12)->create(['client_user_id' => $client->id, 'status' => 'completed']);

        $response = $this->actingAs($client)->get(route('rides.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('rideHistory.data', 10)
            ->where('rideHistory.total', 12)
            ->where('rideHistory.next_page_url', fn ($url) => str_contains($url, 'historial=2'))
        );

        $secondPage = $this->actingAs($client)->get(route('rides.index', ['historial' => 2]));

        $secondPage->assertInertia(fn ($page) => $page->has('rideHistory.data', 2));
    }

    public function test_the_history_shows_when_the_ride_actually_happened(): void
    {
        $client = User::factory()->create();
        $completedAt = Carbon::parse('2026-05-10 14:30:00');
        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'status' => 'completed',
            'completed_at' => $completedAt,
        ]);

        $response = $this->actingAs($client)->get(route('rides.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('rideHistory.data.0.occurred_at', $completedAt->toIso8601String())
        );
    }

    public function test_a_cancelled_ride_shows_its_cancellation_time_not_creation_time(): void
    {
        $client = User::factory()->create();
        $cancelledAt = Carbon::parse('2026-05-11 09:00:00');
        Ride::factory()->create([
            'client_user_id' => $client->id,
            'status' => 'cancelled',
            'cancelled_at' => $cancelledAt,
        ]);

        $response = $this->actingAs($client)->get(route('rides.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('rideHistory.data.0.occurred_at', $cancelledAt->toIso8601String())
        );
    }

    /**
     * La alarma de "sin calificar" tiene que seguir siendo correcta aunque
     * esa carrera puntual haya quedado en la segunda página del historial —
     * por eso se calcula aparte de $rideHistory (ver RideController::index()).
     */
    public function test_unrated_ride_alert_is_accurate_even_when_the_ride_is_on_another_page(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();

        // 10 carreras recientes YA calificadas: llenan toda la primera página.
        Ride::factory()->count(10)->create(['client_user_id' => $client->id, 'driver_user_id' => $driver->id, 'status' => 'completed'])
            ->each(fn (Ride $ride) => Review::factory()->create([
                'ride_id' => $ride->id,
                'reviewer_user_id' => $client->id,
                'reviewee_user_id' => $driver->id,
            ]));

        // Una carrera más vieja, sin calificar — queda en la página 2.
        $oldUnrated = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
            'created_at' => now()->subMonth(),
        ]);

        $response = $this->actingAs($client)->get(route('rides.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('unratedRideIds', [$oldUnrated->id])
        );
    }
}
