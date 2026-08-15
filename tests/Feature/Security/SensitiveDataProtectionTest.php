<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auditoría de seguridad. Decisión tomada sobre teléfono/email (que NO están
 * cifrados en la base): son datos que la app necesita poder buscar tal cual
 * — login por teléfono/correo/usuario (User::findByLoginIdentifier(), con
 * `LIKE` incluido), buscador de conductores/clientes (FleetController::
 * searchDrivers(), DriverInvitationController::searchClients()), validación
 * de único al registrarse. El cast `encrypted` de Eloquent cifra distinto
 * cada vez (IV al azar) — un simple `WHERE email = ?` o `LIKE` dejaría de
 * encontrar NADA apenas se aplicara, rompiendo login y todos los buscadores
 * de la app. Cifrado de verdad y buscable requeriría una columna aparte de
 * índice ciego (hash determinístico) — un cambio de fondo, no una línea de
 * `$casts`, y no se justifica todavía con el volumen real de esta app. La
 * protección que sí importa (y la que se prueba acá) es que ESE dato nunca
 * le llegue a un desconocido — solo al propio dueño, a un admin, o a la
 * otra parte de una relación real (flota, carrera en curso).
 */
class SensitiveDataProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_is_hashed(): void
    {
        $user = User::factory()->create();

        $this->assertNotEquals('password', $user->password);
        $this->assertTrue(password_verify('password', $user->password));
    }

    public function test_password_not_exposed_in_json(): void
    {
        $user = User::factory()->create();
        $json = $user->toJson();

        $this->assertStringNotContainsString($user->password, $json);
    }

    /**
     * Este es el límite real que protege a un desconocido de ver el
     * teléfono/correo de otro usuario — cubierto también, con más detalle,
     * en tests/Feature/PublicProfileTest.php (ese ya prueba is_admin,
     * google_id, locked_at y los códigos de verificación además de estos
     * dos). Se repite acá porque es justo el hallazgo que motivó esta
     * auditoría — que quede una prueba dedicada a "sensibles" también.
     */
    public function test_a_strangers_phone_and_email_are_not_exposed_through_the_public_profile(): void
    {
        $stranger = User::factory()->create([
            'phone' => '+593998765432',
            'email' => 'privado@example.com',
        ]);

        $response = $this->get(route('profiles.show', $stranger));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->missing('profileUser.phone')
            ->missing('profileUser.email')
        );
    }

    /**
     * Auditoría de seguridad: `registration_lat/lng` guardaban 7 decimales
     * (~1 metro — casi la puerta de calle exacta) para un dato que solo se
     * usa como respaldo de "conductores cerca" a nivel de ciudad/barrio (ver
     * DashboardController::nearbyDriversFor()). Bajado a 4 decimales
     * (~11 metros) — ver la migración
     * reduce_registration_location_precision_on_users_table y el cast en
     * App\Models\User.
     */
    public function test_registration_location_is_rounded_to_city_level_precision(): void
    {
        $user = User::factory()->create([
            'registration_lat' => -0.2202855,
            'registration_lng' => -78.4944565,
        ]);

        $this->assertEquals(-0.2203, (float) $user->registration_lat);
        $this->assertEquals(-78.4945, (float) $user->registration_lng);
    }
}
