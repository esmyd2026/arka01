<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Perfil público (sección 3.6): la marca de qué rol(es) tiene la persona
 * (cliente y/o conductor) tiene que reflejar lo que realmente activó, no
 * mostrar "cliente" a cualquiera solo porque técnicamente podría serlo.
 */
class PublicProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pure_driver_is_marked_as_driver_but_not_client(): void
    {
        $viewer = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $response = $this->actingAs($viewer)->get(route('profiles.show', $driver));

        $response->assertInertia(fn ($page) => $page
            ->where('isDriver', true)
            ->where('isClient', false)
        );
    }

    public function test_a_pure_client_is_marked_as_client_but_not_driver(): void
    {
        $viewer = User::factory()->create();
        $client = User::factory()->create();
        Fleet::factory()->for($client, 'owner')->create();

        $response = $this->actingAs($viewer)->get(route('profiles.show', $client));

        $response->assertInertia(fn ($page) => $page
            ->where('isDriver', false)
            ->where('isClient', true)
        );
    }

    /**
     * Bug real reportado por el usuario (captura de pantalla: el perfil
     * público de un conductor mostraba las insignias "Cliente" Y "Conductor"
     * a la vez): un conductor con una flota fantasma vieja (creada por el bug
     * de DriverDirectoryController::index(), ya corregido) no debe volver a
     * marcarse como cliente — la fuente de verdad es User::isClient(), no
     * "¿tiene alguna fila en `fleets`?".
     */
    public function test_a_driver_with_a_leftover_fleet_row_is_not_marked_as_client(): void
    {
        $viewer = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        Fleet::factory()->for($driver, 'owner')->create();

        $response = $this->actingAs($viewer)->get(route('profiles.show', $driver));

        $response->assertInertia(fn ($page) => $page
            ->where('isDriver', true)
            ->where('isClient', false)
        );
    }

    /**
     * Auditoría de seguridad (pedido explícito del usuario): esta pantalla es
     * visible para cualquier usuario logueado con cualquier ID en la URL —
     * antes se mandaba el modelo User completo, permitiendo enumerar email,
     * teléfono, is_admin y hasta códigos de verificación hasheados de toda la
     * base. Ahora solo debe viajar lo que Profile/Show.vue de verdad muestra.
     */
    public function test_the_response_does_not_leak_fields_the_page_does_not_show(): void
    {
        $viewer = User::factory()->create();
        $target = User::factory()->create([
            'email' => 'objetivo@arka01.test',
            'phone' => '+593991234567',
            'is_admin' => true,
        ]);

        $response = $this->actingAs($viewer)->get(route('profiles.show', $target));

        $response->assertInertia(fn ($page) => $page
            ->where('profileUser.id', $target->id)
            ->where('profileUser.name', $target->name)
            ->missing('profileUser.email')
            ->missing('profileUser.phone')
            ->missing('profileUser.is_admin')
            ->missing('profileUser.google_id')
            ->missing('profileUser.locked_at')
            ->missing('profileUser.session_takeover_code')
            ->missing('profileUser.phone_verification_code')
        );
    }
}
