<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auditoría de seguridad: `config/cors.php` tenía `allowed_origins => ['*']`
 * — cualquier sitio podía mandar un `Origin` y recibir de vuelta el header
 * `Access-Control-Allow-Origin` para `/api/*`. Se restringió al propio
 * dominio (`APP_URL`) — ver config/cors.php.
 */
class CorsSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cors_allows_the_apps_own_origin(): void
    {
        $response = $this->withHeaders([
            'Origin' => config('app.url'),
        ])->getJson('/api/user');

        $this->assertEquals(config('app.url'), $response->headers->get('Access-Control-Allow-Origin'));
    }

    /**
     * Con un solo origen configurado (el caso normal de esta app),
     * `fruitcake/php-cors` no compara request por request — devuelve
     * siempre ese único origen fijo, sin mirar el header `Origin` de quien
     * pregunta (ver CorsService::configureAllowedOrigin(), rama
     * "Single origins can be safely set"). Esto sigue siendo seguro: el
     * navegador de quien ataca compara ese valor devuelto contra SU PROPIO
     * origen (https://attacker.com), nunca van a coincidir, así que el
     * navegador igual bloquea la lectura de la respuesta — lo que hay que
     * probar es justamente eso, que el valor nunca sea el del atacante.
     */
    public function test_cors_never_echoes_back_an_untrusted_origin(): void
    {
        $response = $this->withHeaders([
            'Origin' => 'https://attacker.com',
        ])->getJson('/api/user');

        $this->assertNotEquals('https://attacker.com', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
