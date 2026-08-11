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

    // Compartir mi perfil (pedido explícito del usuario): QR/enlace absoluto
    // + vista previa profesional al compartirlo por WhatsApp.

    public function test_the_response_includes_an_absolute_profile_url(): void
    {
        $viewer = User::factory()->create();
        $target = User::factory()->create();

        $response = $this->actingAs($viewer)->get(route('profiles.show', $target));

        $response->assertInertia(fn ($page) => $page
            ->where('profileUrl', route('profiles.show', $target->id))
        );
    }

    /**
     * WhatsApp (y Facebook/Twitter/etc.) arman la tarjeta de vista previa
     * leyendo <meta og:*> de la respuesta cruda, sin ejecutar JavaScript —
     * como esta app es una SPA de Inertia sin SSR, esas etiquetas nunca les
     * llegarían por la vía normal. Ver PublicProfileController::show().
     */
    public function test_a_known_link_preview_bot_gets_a_static_page_with_og_tags(): void
    {
        $target = User::factory()->create(['name' => 'Juan Pérez']);

        $response = $this->withHeaders(['User-Agent' => 'WhatsApp/2.23.20 A'])
            ->get(route('profiles.show', $target));

        $response->assertOk();
        $response->assertViewIs('profile-preview');
        $response->assertSee('og:title', false);
        $response->assertSee('Juan Pérez — Arka01', false);
        $response->assertSee(route('profiles.show', $target->id), false);
    }

    /**
     * Pedido explícito del usuario: quien escanea el QR o abre el enlace
     * compartido puede no tener cuenta todavía en Arka01 — antes esta
     * pantalla vivía atrás del login y lo hubiera mandado a /login en vez
     * de mostrarle el perfil.
     */
    public function test_a_guest_without_an_account_can_view_the_profile(): void
    {
        $target = User::factory()->create();

        $response = $this->get(route('profiles.show', $target));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Profile/Show')
            ->where('profileUser.id', $target->id)
        );
    }

    /**
     * Confidencialidad (pedido explícito del usuario): en el perfil público
     * la foto del vehículo ya no viaja (solo el propio conductor y un admin
     * la ven) y la placa va tapada, no completa — ver
     * App\Models\DriverProfile::maskedPlate().
     */
    public function test_the_response_masks_the_plate_and_hides_the_vehicle_photo(): void
    {
        $viewer = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create([
            'vehicle_plate' => 'ABC-1234',
            'vehicle_type' => 'suv',
            'vehicle_photo_path' => 'driver-documents/vehiculo.jpg',
        ]);

        $response = $this->actingAs($viewer)->get(route('profiles.show', $driver));

        $response->assertInertia(fn ($page) => $page
            ->where('profileUser.driver_profile.vehicle_plate', 'Axxx34')
            ->where('profileUser.driver_profile.vehicle_type', 'SUV')
            ->missing('profileUser.driver_profile.vehicle_photo_url')
        );
    }

    public function test_a_regular_browser_still_gets_the_real_inertia_page(): void
    {
        $viewer = User::factory()->create();
        $target = User::factory()->create();

        $response = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
            ->actingAs($viewer)->get(route('profiles.show', $target));

        $response->assertInertia(fn ($page) => $page->component('Profile/Show'));
    }
}
