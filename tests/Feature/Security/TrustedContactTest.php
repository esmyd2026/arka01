<?php

namespace Tests\Feature\Security;

use App\Models\TrustedContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contactos de confianza (sección 8): a quién avisa el botón SOS y a quién
 * se le puede compartir el seguimiento en vivo.
 */
class TrustedContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_add_a_trusted_contact(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('trusted-contacts.store'), [
            'name' => 'Mamá',
            'phone' => '0991234567',
            'email' => 'mama@example.com',
            'relationship_label' => 'Familiar',
        ])->assertRedirect();

        $this->assertDatabaseHas('trusted_contacts', [
            'user_id' => $user->id,
            'name' => 'Mamá',
            'email' => 'mama@example.com',
        ]);
    }

    public function test_a_contact_needs_at_least_a_phone_or_an_email(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('trusted-contacts.store'), [
            'name' => 'Sin datos',
        ])->assertSessionHasErrors('phone');

        $this->assertDatabaseMissing('trusted_contacts', ['name' => 'Sin datos']);
    }

    public function test_a_user_can_delete_their_own_contact_but_not_someone_elses(): void
    {
        $user = User::factory()->create();
        $contact = TrustedContact::query()->create([
            'user_id' => $user->id,
            'name' => 'Pareja',
            'phone' => '0990000000',
        ]);

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->delete(route('trusted-contacts.destroy', $contact))->assertForbidden();
        $this->assertDatabaseHas('trusted_contacts', ['id' => $contact->id]);

        $this->actingAs($user)->delete(route('trusted-contacts.destroy', $contact))->assertRedirect();
        $this->assertDatabaseMissing('trusted_contacts', ['id' => $contact->id]);
    }
}
