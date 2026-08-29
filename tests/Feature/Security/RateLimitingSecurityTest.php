<?php

namespace Tests\Feature\Security;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auditoría de seguridad. Los dos buscadores en vivo que devuelven datos de
 * otros usuarios (`fleet.search-drivers`, `driver.clients.search`) no tenían
 * límite del lado del servidor — un script podía barrer resultados probando
 * términos uno atrás de otro más rápido de lo que cualquier persona
 * escribiendo a mano podría. Se les agregó `throttle:30,1` (ver
 * routes/web.php) — de sobra para el buscador con debounce del frontend,
 * corta a un script. (Después, por pedido explícito del usuario, ambos
 * buscadores además pasaron a filtrar SOLO por código de socio/invitación,
 * ver FleetInvitationFlowTest y DriverInitiatedFleetInvitationTest — el
 * límite de acá sigue siendo necesario igual, un código de 3-4 dígitos
 * también se puede barrer por fuerza bruta sin este throttle.)
 *
 * (La auditoría original apuntaba a `/api/users/search` y
 * `/api/users/{user}/profile` — esas rutas no existen en esta app, que no
 * expone una API JSON pública de usuarios; toda la búsqueda de personas pasa
 * por las dos rutas de sesión de arriba, ya cubiertas acá.)
 */
class RateLimitingSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * El login usa el rate limiting propio de Laravel (LoginRequest::
     * ensureIsNotRateLimited(), 5 intentos por login+ip) — corta con un
     * ValidationException (422 con el mensaje bajo "login"), no con un 429:
     * ese es justamente el comportamiento esperado de Breeze, no hace falta
     * throttle de ruta aparte.
     */
    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        // Ruta POST sin nombre (ver routes/auth.php) — mismo path que usa
        // Auth/Login.vue.
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => 'nadie@example.com', 'password' => 'mal']);
        }

        $response = $this->post('/login', ['login' => 'nadie@example.com', 'password' => 'mal']);

        $response->assertSessionHasErrors('login');
        $this->assertStringContainsString('Demasiados intentos', session('errors')->first('login'));
    }

    public function test_searching_drivers_is_rate_limited(): void
    {
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($client)->getJson(route('fleet.search-drivers', $fleet).'?q=ana');
        }

        $response = $this->actingAs($client)->getJson(route('fleet.search-drivers', $fleet).'?q=ana');

        $response->assertStatus(429);
    }

    public function test_searching_clients_is_rate_limited(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($driver)->getJson(route('driver.clients.search').'?q=ana');
        }

        $response = $this->actingAs($driver)->getJson(route('driver.clients.search').'?q=ana');

        $response->assertStatus(429);
    }

    /**
     * Mismo criterio que arriba, aplicado a la pasada "full backend" de la
     * app móvil (roadmap Hito 2, "Limitar intentos de login, búsquedas y
     * acciones sensibles"): los endpoints de escritura nuevos de Expresos,
     * Soporte y perfil de conductor no tenían ningún throttle propio, solo
     * el límite general de 60/min de toda /api — de sobra para el uso
     * normal de una pantalla, pero no corta a un script que insista rápido.
     */
    public function test_mobile_express_route_creation_is_rate_limited(): void
    {
        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;

        for ($i = 0; $i < 10; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/express-routes', []);
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/express-routes', [])
            ->assertStatus(429);
    }

    public function test_mobile_express_discover_is_rate_limited(): void
    {
        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;

        for ($i = 0; $i < 30; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/express-routes/discover');
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/express-routes/discover')
            ->assertStatus(429);
    }

    public function test_mobile_express_application_creation_is_rate_limited(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $token = $driver->createToken('test')->plainTextToken;

        for ($i = 0; $i < 10; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/express-routes/1/applications', []);
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/express-routes/1/applications', [])
            ->assertStatus(429);
    }

    public function test_mobile_express_companion_request_is_rate_limited(): void
    {
        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;

        for ($i = 0; $i < 10; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/express-routes/1/companions', []);
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/express-routes/1/companions', [])
            ->assertStatus(429);
    }

    public function test_mobile_support_messages_are_rate_limited(): void
    {
        $client = User::factory()->create();
        $token = $client->createToken('test')->plainTextToken;

        for ($i = 0; $i < 20; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/support/messages', ['body' => 'x']);
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/support/messages', ['body' => 'x'])
            ->assertStatus(429);
    }

    public function test_mobile_driver_profile_update_is_rate_limited(): void
    {
        $driver = User::factory()->create();
        $token = $driver->createToken('test')->plainTextToken;

        for ($i = 0; $i < 10; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/driver/profile', []);
        }

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/driver/profile', [])
            ->assertStatus(429);
    }
}
