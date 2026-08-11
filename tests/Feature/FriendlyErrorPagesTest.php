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
}
