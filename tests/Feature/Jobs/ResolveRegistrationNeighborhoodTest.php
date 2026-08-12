<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ResolveRegistrationNeighborhood;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Resuelve un barrio/zona aproximado vía OpenStreetMap Nominatim a partir de
 * la ubicación real dada al registrarse (pedido explícito del usuario) — ver
 * App\Http\Controllers\Auth\RegisteredUserController::store().
 */
class ResolveRegistrationNeighborhoodTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_saves_the_neighborhood_resolved_by_nominatim(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'address' => ['suburb' => 'La Mariscal', 'city' => 'Quito'],
            ], 200),
        ]);

        $user = User::factory()->create();

        (new ResolveRegistrationNeighborhood($user->id, -0.19, -78.47))->handle();

        $this->assertSame('La Mariscal', $user->fresh()->registration_neighborhood);
    }

    public function test_it_logs_a_system_event_and_does_not_throw_when_nominatim_fails(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 503),
        ]);

        $user = User::factory()->create();

        (new ResolveRegistrationNeighborhood($user->id, -0.19, -78.47))->handle();

        $this->assertNull($user->fresh()->registration_neighborhood);
        $this->assertDatabaseHas('system_events', [
            'event_type' => 'reverse_geocode_failed',
            'module' => 'registration',
            'user_id' => $user->id,
        ]);
    }
}
