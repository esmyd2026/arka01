<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\TrustCircleConnection;
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

        $response = $this->actingAs($viewer)->get(route('profiles.show', $driver->public_id));

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

        $response = $this->actingAs($viewer)->get(route('profiles.show', $client->public_id));

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

        $response = $this->actingAs($viewer)->get(route('profiles.show', $driver->public_id));

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

        $response = $this->actingAs($viewer)->get(route('profiles.show', $target->public_id));

        $response->assertInertia(fn ($page) => $page
            ->where('profileUser.public_id', $target->public_id)
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

        $response = $this->actingAs($viewer)->get(route('profiles.show', $target->public_id));

        $response->assertInertia(fn ($page) => $page
            ->where('profileUrl', route('profiles.show', $target->public_id))
        );
    }

    public function test_the_shared_profile_includes_a_stable_explainable_trust_index(): void
    {
        $target = User::factory()->create();
        $contact = User::factory()->create();

        TrustCircleConnection::query()->create([
            'requester_user_id' => $target->id,
            'addressee_user_id' => $contact->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $response = $this->get(route('profiles.show', $target->public_id));

        $response->assertInertia(fn ($page) => $page
            ->where('trustIndex.role', 'Cliente')
            ->where('trustIndex.network_connections', 1)
            ->where('trustIndex.mutual_people', 0)
            ->has('trustIndex.score')
            ->has('trustIndex.level')
            ->has('trustIndex.components', 4)
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
            ->get(route('profiles.show', $target->public_id));

        $response->assertOk();
        $response->assertViewIs('profile-preview');
        $response->assertSee('og:title', false);
        $response->assertSee('Juan Pérez — Arka01', false);
        $response->assertSee(route('profiles.show', $target->public_id), false);
        $response->assertSee('Índice de confianza', false);
    }

    /**
     * Pedido explícito del usuario ("un mensaje con llamada a la accion...
     * unete y haz que la movilidad sea ahora mas segura"): la tarjeta de
     * vista previa que arma WhatsApp para un rastreador debe llevar esa
     * invitación, no una descripción neutra sin gancho.
     */
    public function test_the_link_preview_bot_sees_the_call_to_action_copy(): void
    {
        $target = User::factory()->create(['name' => 'Juan Pérez']);

        $response = $this->withHeaders(['User-Agent' => 'WhatsApp/2.23.20 A'])
            ->get(route('profiles.show', $target->public_id));

        $response->assertOk();
        $response->assertSee('únase y hagamos que la movilidad sea más segura en Ecuador', false);
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

        $response = $this->get(route('profiles.show', $target->public_id));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Profile/Show')
            ->where('profileUser.public_id', $target->public_id)
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

        $response = $this->actingAs($viewer)->get(route('profiles.show', $driver->public_id));

        $response->assertInertia(fn ($page) => $page
            ->where('profileUser.driver_profile.vehicle_plate', 'Axxx34')
            ->where('profileUser.driver_profile.vehicle_type', 'SUV')
            ->missing('profileUser.driver_profile.vehicle_photo_url')
        );
    }

    /**
     * Pedido explícito del usuario ("mejoremos la privacidad de los
     * conductores... habilitar su perfil al publico"): con el toggle
     * apagado, un desconocido ya no ve vehículo, tarifa ni reseñas — solo
     * nombre y avatar, más el aviso de que el perfil es privado.
     */
    public function test_a_stranger_does_not_see_driver_details_when_the_profile_is_private(): void
    {
        $viewer = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['profile_public' => false, 'vehicle_make' => 'Chevrolet']);

        $response = $this->actingAs($viewer)->get(route('profiles.show', $driver->public_id));

        $response->assertInertia(fn ($page) => $page
            ->where('profilePrivate', true)
            ->where('profileUser.driver_profile', null)
            ->where('averageRating', 0)
            ->where('reviewCount', 0)
            ->where('trustIndex', null)
            ->where('isDriver', true)
        );
    }

    public function test_a_private_driver_link_preview_does_not_reveal_the_trust_index_or_rating(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['profile_public' => false]);

        $response = $this->withHeaders(['User-Agent' => 'WhatsApp/2.23.20 A'])
            ->get(route('profiles.show', $driver->public_id));

        $response->assertOk();
        $response->assertDontSee('Índice de confianza', false);
        $response->assertDontSee('★', false);
    }

    public function test_a_guest_without_an_account_also_gets_the_private_version(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['profile_public' => false]);

        $response = $this->get(route('profiles.show', $driver->public_id));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('profilePrivate', true));
    }

    public function test_the_driver_still_sees_their_own_full_private_profile(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['profile_public' => false, 'vehicle_make' => 'Chevrolet']);

        $response = $this->actingAs($driver)->get(route('profiles.show', $driver->public_id));

        $response->assertInertia(fn ($page) => $page
            ->where('profilePrivate', false)
            ->where('profileUser.driver_profile.vehicle_make', 'Chevrolet')
        );
    }

    public function test_an_admin_still_sees_the_full_private_profile(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['profile_public' => false, 'vehicle_make' => 'Chevrolet']);

        $response = $this->actingAs($admin)->get(route('profiles.show', $driver->public_id));

        $response->assertInertia(fn ($page) => $page
            ->where('profilePrivate', false)
            ->where('profileUser.driver_profile.vehicle_make', 'Chevrolet')
        );
    }

    /** Comportamiento por defecto (columna nueva, default true): nadie pierde visibilidad de golpe. */
    public function test_a_driver_profile_is_public_by_default(): void
    {
        $viewer = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['vehicle_make' => 'Chevrolet']);

        $response = $this->actingAs($viewer)->get(route('profiles.show', $driver->public_id));

        $response->assertInertia(fn ($page) => $page
            ->where('profilePrivate', false)
            ->where('profileUser.driver_profile.vehicle_make', 'Chevrolet')
        );
    }

    public function test_a_regular_browser_still_gets_the_real_inertia_page(): void
    {
        $viewer = User::factory()->create();
        $target = User::factory()->create();

        $response = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'])
            ->actingAs($viewer)->get(route('profiles.show', $target->public_id));

        $response->assertInertia(fn ($page) => $page->component('Profile/Show'));
    }

    public function test_a_numeric_user_id_cannot_open_a_public_profile(): void
    {
        $target = User::factory()->create();

        $this->get('/perfil/'.$target->id)->assertNotFound();
        $this->get(route('profiles.show', $target->public_id))->assertOk();
    }
}
