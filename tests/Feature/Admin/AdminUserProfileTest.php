<?php

namespace Tests\Feature\Admin;

use App\Models\ChatbotMessage;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\Review;
use App\Models\Ride;
use App\Models\User;
use App\Models\WhatsAppSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: "en el Administrador debe existir una opción
 * para consultar el perfil completo tanto del conductor como del cliente,
 * mostrando toda la información relevante sin necesidad de navegar por
 * diferentes pantallas".
 */
class AdminUserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_view_a_full_profile(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $other = User::factory()->create();

        $this->actingAs($user)->get(route('admin.users.show', $other))->assertForbidden();
    }

    public function test_an_admin_can_view_a_drivers_full_profile(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['verification_status' => 'approved']);

        $response = $this->actingAs($admin)->get(route('admin.users.show', $driver));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/UserProfile')
            ->where('profileUser.id', $driver->id)
            ->where('driverPlan.plan_code', 'gratis')
            ->where('clientPlan', null)
        );
    }

    public function test_an_admin_can_view_a_clients_full_profile(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();
        Fleet::factory()->for($client, 'owner')->create();

        $response = $this->actingAs($admin)->get(route('admin.users.show', $client));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/UserProfile')
            ->has('fleetsOwned', 1)
            ->where('driverPlan', null)
        );
    }

    /**
     * Pedido explícito del usuario ("ayudame a ver la trazabilidad de los
     * whatsapp en el perfil de cada usuario") — la transcripción completa,
     * en la misma ficha que ya reúne todo lo demás del usuario, sea cliente,
     * conductor o admin.
     */
    public function test_the_profile_includes_the_whatsapp_transcript(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create(['phone' => '+593991234567']);
        ChatbotMessage::query()->create(['phone' => '+593991234567', 'user_id' => $client->id, 'direction' => 'in', 'body' => 'Hola']);
        ChatbotMessage::query()->create(['phone' => '+593991234567', 'user_id' => $client->id, 'direction' => 'out', 'body' => '¡Hola! ¿Qué necesita?']);

        $response = $this->actingAs($admin)->get(route('admin.users.show', $client));

        $response->assertInertia(fn ($page) => $page->has('whatsappMessages', 2));
    }

    /**
     * Pedido explícito del usuario ("permiteme actualizar el correo y el
     * telefono") — mismo criterio de unicidad y re-verificación que ya usa
     * el propio conductor al corregir su número, disparado acá por un admin.
     */
    public function test_an_admin_can_update_a_users_email_and_phone(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create(['email' => 'vieja@example.com', 'phone' => '+593991234567', 'phone_verified_at' => now()]);
        WhatsAppSession::query()->create(['user_id' => $client->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);

        $response = $this->actingAs($admin)->patch(route('admin.users.update-contact', $client), [
            'email' => 'nueva@example.com',
            'country_code' => '+593',
            'phone_local' => '981234567',
        ]);

        $response->assertRedirect();
        $client->refresh();
        $this->assertSame('nueva@example.com', $client->email);
        $this->assertSame('+593981234567', $client->phone);
        $this->assertNull($client->phone_verified_at);
        $this->assertFalse($client->hasActiveWhatsAppSession());
    }

    public function test_updating_contact_rejects_a_phone_already_used_by_another_account(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        User::factory()->create(['phone' => '+593981234567']);
        $client = User::factory()->create(['phone' => '+593991234567']);

        $response = $this->actingAs($admin)->patch(route('admin.users.update-contact', $client), [
            'email' => $client->email,
            'country_code' => '+593',
            'phone_local' => '981234567',
        ]);

        $response->assertSessionHasErrors('phone_local');
        $this->assertSame('+593991234567', $client->fresh()->phone);
    }

    /**
     * Pedido explícito del usuario ("ayudame a poder dar de baja a los
     * numeros") — libera el número por completo, para que otra cuenta
     * pueda registrarlo.
     */
    public function test_an_admin_can_release_a_users_phone_number(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create(['phone' => '+593991234567', 'phone_verified_at' => now()]);
        WhatsAppSession::query()->create(['user_id' => $client->id, 'opened_at' => now(), 'expires_at' => now()->addHours(20)]);

        $response = $this->actingAs($admin)->delete(route('admin.users.release-phone', $client));

        $response->assertRedirect();
        $client->refresh();
        $this->assertNull($client->phone);
        $this->assertNull($client->phone_verified_at);
        $this->assertFalse($client->hasActiveWhatsAppSession());
    }

    // Eliminar cuenta (pedido explícito del usuario): borra archivos y, por
    // el cascade que ya tienen las FKs a users.id, todo lo demás — historial
    // de carreras, flotas/membresías, reseñas, suscripciones, etc.

    public function test_a_regular_user_cannot_delete_an_account(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $client = User::factory()->create();

        $this->actingAs($user)->delete(route('admin.users.destroy', $client), [
            'confirm_email' => $client->email,
        ])->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $client->id]);
    }

    public function test_an_admin_cannot_delete_another_admin_account(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $otherAdmin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->delete(route('admin.users.destroy', $otherAdmin), [
            'confirm_email' => $otherAdmin->email,
        ])->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $otherAdmin->id]);
    }

    public function test_deleting_without_the_matching_confirmation_email_fails(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();

        $this->actingAs($admin)->delete(route('admin.users.destroy', $client), [
            'confirm_email' => 'correo-que-no-coincide@example.com',
        ])->assertSessionHasErrors('confirm_email');

        $this->assertDatabaseHas('users', ['id' => $client->id]);
    }

    public function test_an_admin_can_delete_a_client_account_and_it_cascades(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $ride = Ride::factory()->create(['fleet_id' => $fleet->id, 'client_user_id' => $client->id, 'driver_user_id' => $driver->id]);
        Review::factory()->create(['ride_id' => $ride->id, 'reviewer_user_id' => $driver->id, 'reviewee_user_id' => $client->id]);

        $response = $this->actingAs($admin)->delete(route('admin.users.destroy', $client), [
            'confirm_email' => $client->email,
        ]);

        $response->assertRedirect(route('admin.clients.index'));
        $this->assertDatabaseMissing('users', ['id' => $client->id]);
        $this->assertDatabaseMissing('fleets', ['id' => $fleet->id]);
        $this->assertDatabaseMissing('rides', ['id' => $ride->id]);
        $this->assertDatabaseMissing('reviews', ['reviewee_user_id' => $client->id]);
        // El conductor y su reseña hecha (del otro lado) no tenían nada que
        // ver con la cuenta borrada — sobreviven.
        $this->assertDatabaseHas('users', ['id' => $driver->id]);

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_user_id' => $admin->id,
            'action' => 'user.delete',
            'module' => 'usuarios',
        ]);
    }

    public function test_an_admin_can_delete_a_driver_account_and_purges_their_files(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();

        $licensePath = UploadedFile::fake()->image('licencia.jpg')->store('driver-documents', 'local');
        $vehiclePath = UploadedFile::fake()->image('vehiculo.jpg')->store('driver-documents', 'public');
        DriverProfile::factory()->for($driver)->create([
            'license_photo_path' => $licensePath,
            'vehicle_photo_path' => $vehiclePath,
        ]);

        $response = $this->actingAs($admin)->delete(route('admin.users.destroy', $driver), [
            'confirm_email' => $driver->email,
        ]);

        $response->assertRedirect(route('admin.drivers.index'));
        $this->assertDatabaseMissing('users', ['id' => $driver->id]);
        $this->assertDatabaseMissing('driver_profiles', ['user_id' => $driver->id]);
        Storage::disk('local')->assertMissing($licensePath);
        Storage::disk('public')->assertMissing($vehiclePath);
    }

    /**
     * Ajuste manual de puntos (pedido explícito del usuario): hoy los puntos
     * solo suben solos, uno por carrera completada — acá se corrige a mano.
     */
    public function test_an_admin_can_manually_adjust_a_drivers_points(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['total_points' => 10]);

        $response = $this->actingAs($admin)
            ->patch(route('admin.users.update-points', $driver), ['total_points' => 600]);

        $response->assertRedirect();
        $this->assertDatabaseHas('driver_profiles', ['user_id' => $driver->id, 'total_points' => 600]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'driver.points.update']);
    }

    public function test_a_regular_user_cannot_adjust_a_drivers_points(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($user)
            ->patch(route('admin.users.update-points', $driver), ['total_points' => 600])
            ->assertForbidden();
    }

    public function test_points_cannot_be_adjusted_for_a_user_without_a_driver_profile(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $client = User::factory()->create();

        $this->actingAs($admin)
            ->patch(route('admin.users.update-points', $client), ['total_points' => 600])
            ->assertNotFound();
    }

    /**
     * Activación manual (pedido explícito del usuario: "permiteme colocar
     * a un conductor activo asi no mande toda la informacion... para que
     * pueda operar y se pueda poner disponible") — un conductor SIN
     * documentos ni seguro declarado queda igual habilitado para
     * conectarse, una vez que un admin lo activa a mano con un motivo.
     */
    public function test_an_admin_can_force_activate_a_driver_missing_required_information(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create([
            'identity_document_path' => null,
            'license_photo_path' => null,
            'police_record_path' => null,
            'has_insurance' => false,
            'verification_status' => null,
        ]);

        $this->assertFalse($profile->hasCompleteRegistrationInformation());

        $response = $this->actingAs($admin)->post(route('admin.users.force-activate-driver', $driver), [
            'note' => 'Ya vetado por su cooperativa, se activa mientras completa la documentación.',
        ]);

        $response->assertRedirect();
        $profile->refresh();
        $this->assertNotNull($profile->admin_activated_at);
        $this->assertSame($admin->id, $profile->admin_activated_by);
        $this->assertTrue($profile->hasCompleteRegistrationInformation());
        $this->assertTrue($profile->canBecomeAvailable());
        $this->assertSame('approved', $profile->verification_status);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'driver.force_activate']);
    }

    public function test_force_activating_a_driver_requires_a_note(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($admin)
            ->post(route('admin.users.force-activate-driver', $driver), ['note' => ''])
            ->assertSessionHasErrors('note');
    }

    public function test_a_regular_user_cannot_force_activate_a_driver(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($user)
            ->post(route('admin.users.force-activate-driver', $driver), ['note' => 'motivo'])
            ->assertForbidden();
    }

    public function test_revoking_the_manual_activation_restores_the_normal_requirement(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        $profile = DriverProfile::factory()->for($driver)->create([
            'identity_document_path' => null,
            'has_insurance' => false,
        ]);
        $this->actingAs($admin)->post(route('admin.users.force-activate-driver', $driver), ['note' => 'motivo']);

        $this->actingAs($admin)->delete(route('admin.users.revoke-force-activate-driver', $driver))->assertRedirect();

        $profile->refresh();
        $this->assertNull($profile->admin_activated_at);
        $this->assertFalse($profile->hasCompleteRegistrationInformation());
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'driver.force_activate.revoke']);
    }

    /**
     * Pedido explícito del usuario: "ver el detalle de los clientes que
     * tiene cada conductor" desde el admin.
     */
    public function test_a_drivers_profile_lists_their_active_clients(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create(['name' => 'Mi flota']);
        $member = FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $response = $this->actingAs($admin)->get(route('admin.users.show', $driver));

        $response->assertInertia(fn ($page) => $page
            ->has('driverClients', 1)
            ->where('driverClients.0.member_id', $member->id)
            ->where('driverClients.0.client_id', $client->id)
            ->where('driverClients.0.fleet_name', 'Mi flota')
        );
    }

    /**
     * Pedido explícito del usuario: "que pueda eliminarle" — mismo
     * mecanismo que ya usa el propio cliente (left_at, sin borrar la fila).
     */
    public function test_an_admin_can_remove_a_client_from_a_drivers_fleet(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $member = FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $response = $this->actingAs($admin)->delete(route('admin.users.remove-client', [$driver, $member]));

        $response->assertRedirect();
        $member->refresh();
        $this->assertNotNull($member->left_at);
        $this->assertSame('admin_removed', $member->left_reason);
        $this->assertSame($admin->id, $member->removed_by);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'driver.client.remove']);
    }

    public function test_a_regular_user_cannot_remove_a_client_from_a_drivers_fleet(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $member = FleetMember::factory()->for($fleet)->for($driver, 'driver')->create(['added_by' => $client->id]);

        $this->actingAs($user)
            ->delete(route('admin.users.remove-client', [$driver, $member]))
            ->assertForbidden();

        $this->assertNull($member->fresh()->left_at);
    }

    /**
     * El `{member}` de la URL tiene que ser de verdad una flota de ESE
     * conductor — sin este chequeo, se podría sacar por error a un cliente
     * de otro conductor mandando el id equivocado.
     */
    public function test_cannot_remove_a_client_that_does_not_belong_to_the_given_driver(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();
        $otherDriver = User::factory()->create();
        DriverProfile::factory()->for($otherDriver)->create();
        $client = User::factory()->create();
        $fleet = Fleet::factory()->for($client, 'owner')->create();
        $member = FleetMember::factory()->for($fleet)->for($otherDriver, 'driver')->create(['added_by' => $client->id]);

        $this->actingAs($admin)
            ->delete(route('admin.users.remove-client', [$driver, $member]))
            ->assertNotFound();
    }
}
