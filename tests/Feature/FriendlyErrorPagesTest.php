<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Pedido explícito del usuario (con captura del "503 | SERVICE UNAVAILABLE"
 * en blanco y negro que muestra Laravel por defecto): pantallas de error con
 * el estilo oscuro de Arka01 en vez de la página cruda del framework — ver
 * resources/views/errors/*.blade.php.
 */
class FriendlyErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_route_shows_the_branded_404_page(): void
    {
        $response = $this->get('/esta-ruta-no-existe-nunca');

        $response->assertNotFound();
        $response->assertSee('No encontramos esta página');
    }

    public function test_maintenance_mode_shows_the_branded_503_page(): void
    {
        // try/finally: si alguna aserción de acá abajo falla, "up" tiene que
        // correr igual — si no, el modo mantenimiento se queda pegado (el
        // archivo storage/framework/maintenance.php) para el resto de la
        // suite y hasta para quien esté corriendo la app en local.
        try {
            Artisan::call('down', ['--render' => 'errors::503']);

            $response = $this->get('/');

            $response->assertStatus(503);
            $response->assertSee('Estamos actualizando la plataforma');
        } finally {
            Artisan::call('up');
        }
    }

    /**
     * Bug real reportado por el usuario ("el botón no dirige" en el 419):
     * Inertia muestra las respuestas que no son suyas (como esta página de
     * error) dentro de un <iframe> flotando en un modal — sin target="_top"
     * el enlace navegaba ese iframe nomás, no la ventana real.
     */
    public function test_the_home_link_targets_the_top_window_so_it_escapes_inertias_error_iframe(): void
    {
        $response = $this->get('/esta-ruta-no-existe-nunca');

        $response->assertSee('target="_top"', false);
    }
}
