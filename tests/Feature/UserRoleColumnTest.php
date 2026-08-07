<?php

namespace Tests\Feature;

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Columna `users.role` (consideración agregada al alcance): un string
 * consultable por SQL directo con el tipo de cuenta, sincronizado solo desde
 * `User::isDriver()`/`isClient()`/`is_admin` — nunca la fuente de verdad para
 * permisos, solo para consulta rápida (reportes, admin, etc.).
 */
class UserRoleColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_brand_new_user_defaults_to_cliente(): void
    {
        $user = User::factory()->create();

        $this->assertSame('cliente', $user->role);
        $this->assertSame('cliente', $user->fresh()->role);
    }

    public function test_activating_the_driver_profile_syncs_the_role_to_conductor(): void
    {
        $user = User::factory()->create();
        $this->assertSame('cliente', $user->fresh()->role);

        DriverProfile::factory()->for($user)->create();

        $this->assertSame('conductor', $user->fresh()->role);
    }

    public function test_an_admin_account_syncs_the_role_to_admin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->assertSame('admin', $admin->role);
        $this->assertSame('admin', $admin->fresh()->role);
    }

    /**
     * Si en algún momento se agrega una forma de sacarle `is_admin` a una
     * cuenta, el rol tiene que volver a reflejar la realidad (cliente o
     * conductor, según corresponda) en vez de quedarse pegado en "admin".
     * `is_admin` no es mass-assignable a propósito (no está en $fillable,
     * es una barrera de seguridad existente) — se cambia por asignación
     * directa, como haría un script de confianza, no `update()`.
     */
    public function test_removing_admin_status_falls_back_to_the_real_role(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->assertSame('admin', $admin->fresh()->role);

        $admin->is_admin = false;
        $admin->save();

        $this->assertSame('cliente', $admin->fresh()->role);
    }

    /**
     * Así quedó desactualizada la columna de la cuenta demo "Demo Cliente" al
     * limpiar un perfil de conductor accidental a mano — este hook evita que
     * vuelva a pasar.
     */
    public function test_deleting_the_driver_profile_falls_back_the_role_to_cliente(): void
    {
        $user = User::factory()->create();
        $profile = DriverProfile::factory()->for($user)->create();
        $this->assertSame('conductor', $user->fresh()->role);

        $profile->delete();

        $this->assertSame('cliente', $user->fresh()->role);
    }
}
