<?php

namespace Tests\Feature\Review;

use App\Models\RatingReason;
use App\Models\Review;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre la Fase 3 del roadmap: calificar y comentar al finalizar una carrera
 * (sección 3.6), la pieza que arma el historial de confianza de la plataforma.
 */
class ReviewFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_review_a_completed_ride(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
        ]);

        $this->actingAs($client)
            ->post(route('reviews.store', $ride), ['rating' => 5, 'comment' => 'Excelente viaje'])
            ->assertRedirect();

        $this->assertDatabaseHas('reviews', [
            'ride_id' => $ride->id,
            'reviewer_user_id' => $client->id,
            'reviewee_user_id' => $driver->id,
            'rating' => 5,
            'comment' => 'Excelente viaje',
        ]);
    }

    /**
     * Pedido explícito del usuario: cliente y conductor califican de forma
     * INDEPENDIENTE — el conductor no espera a que el cliente lo haga primero
     * (se probó con un orden fijo y se revirtió a pedido del usuario).
     */
    public function test_driver_can_review_a_completed_ride_before_the_client_does(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
        ]);

        $reason = RatingReason::query()->where('direction', 'driver_to_client')->firstOrFail();

        $this->actingAs($driver)
            ->post(route('reviews.store', $ride), ['rating' => 4, 'rating_reason_id' => $reason->id])
            ->assertRedirect();

        $this->assertDatabaseHas('reviews', [
            'ride_id' => $ride->id,
            'reviewer_user_id' => $driver->id,
            'reviewee_user_id' => $client->id,
            'rating' => 4,
            'rating_reason_id' => $reason->id,
        ]);
    }

    /**
     * Pedido explícito del usuario: si se baja de las 5 estrellas por
     * defecto, hay que elegir un motivo del catálogo — sin esto, se rechaza.
     */
    public function test_lowering_the_rating_below_5_requires_a_reason(): void
    {
        $client = User::factory()->create();
        $ride = Ride::factory()->create(['client_user_id' => $client->id, 'status' => 'completed']);

        $this->actingAs($client)
            ->post(route('reviews.store', $ride), ['rating' => 3])
            ->assertSessionHasErrors('rating_reason_id');

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_the_reason_must_match_the_reviewers_direction(): void
    {
        $client = User::factory()->create();
        $ride = Ride::factory()->create(['client_user_id' => $client->id, 'status' => 'completed']);

        // El cliente califica al conductor — un motivo "conductor → cliente"
        // no le corresponde, aunque exista y esté activo.
        $wrongDirectionReason = RatingReason::query()->where('direction', 'driver_to_client')->firstOrFail();

        $this->actingAs($client)
            ->post(route('reviews.store', $ride), ['rating' => 2, 'rating_reason_id' => $wrongDirectionReason->id])
            ->assertSessionHasErrors('rating_reason_id');

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_cannot_review_a_ride_that_is_not_completed(): void
    {
        $client = User::factory()->create();
        $ride = Ride::factory()->create(['client_user_id' => $client->id, 'status' => 'in_progress']);

        $this->actingAs($client)
            ->post(route('reviews.store', $ride), ['rating' => 5])
            ->assertSessionHasErrors('ride');

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_cannot_review_the_same_ride_twice(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
        ]);

        Review::factory()->create([
            'ride_id' => $ride->id,
            'reviewer_user_id' => $client->id,
            'reviewee_user_id' => $driver->id,
        ]);

        $this->actingAs($client)
            ->post(route('reviews.store', $ride), ['rating' => 3])
            ->assertSessionHasErrors('ride');

        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_a_stranger_cannot_review_a_ride_they_are_not_part_of(): void
    {
        $ride = Ride::factory()->create(['status' => 'completed']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post(route('reviews.store', $ride), ['rating' => 5])
            ->assertForbidden();
    }

    public function test_rating_must_be_between_1_and_5(): void
    {
        $client = User::factory()->create();
        $ride = Ride::factory()->create(['client_user_id' => $client->id, 'status' => 'completed']);

        $this->actingAs($client)
            ->post(route('reviews.store', $ride), ['rating' => 7])
            ->assertSessionHasErrors('rating');
    }

    /**
     * Pedido explícito del usuario ("si no han calificado que le arroje una
     * alarma"): /carreras marca cada carrera completada que todavía me falta
     * calificar — independiente de si la otra parte ya calificó o no.
     */
    public function test_rides_index_flags_a_completed_ride_i_have_not_reviewed_yet(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($client)->get(route('rides.index'));

        $response->assertInertia(fn ($page) => $page->where(
            'rideHistory',
            fn ($history) => collect($history)->firstWhere('id', $ride->id)['needs_my_review'] === true
        ));
    }

    public function test_rides_index_does_not_flag_a_ride_i_already_reviewed(): void
    {
        $client = User::factory()->create();
        $driver = User::factory()->create();
        $ride = Ride::factory()->create([
            'client_user_id' => $client->id,
            'driver_user_id' => $driver->id,
            'status' => 'completed',
        ]);

        Review::factory()->create([
            'ride_id' => $ride->id,
            'reviewer_user_id' => $client->id,
            'reviewee_user_id' => $driver->id,
        ]);

        $response = $this->actingAs($client)->get(route('rides.index'));

        $response->assertInertia(fn ($page) => $page->where(
            'rideHistory',
            fn ($history) => collect($history)->firstWhere('id', $ride->id)['needs_my_review'] === false
        ));
    }

    public function test_public_profile_shows_average_rating_and_comments(): void
    {
        $driver = User::factory()->create();
        Review::factory()->create(['reviewee_user_id' => $driver->id, 'rating' => 5, 'comment' => 'Muy puntual']);
        Review::factory()->create(['reviewee_user_id' => $driver->id, 'rating' => 3]);

        $viewer = User::factory()->create();

        $response = $this->actingAs($viewer)->get(route('profiles.show', $driver));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Profile/Show')
            ->where('averageRating', 4)
            ->where('reviewCount', 2)
        );
    }
}
