<?php

namespace Tests\Feature\Api\V1;

use App\Models\TrustedContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contactos de confianza desde la app móvil (roadmap Hito 5B, paridad con
 * la web) — mismas reglas que TrustedContactController (web).
 */
class MobileTrustedContactTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_a_user_can_list_their_trusted_contacts(): void
    {
        $user = User::factory()->create();
        TrustedContact::query()->create(['user_id' => $user->id, 'name' => 'Mamá', 'phone' => '0991234567']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/trusted-contacts')
            ->assertOk()
            ->assertJsonCount(1, 'contacts')
            ->assertJsonPath('contacts.0.name', 'Mamá');
    }

    public function test_a_user_can_add_a_trusted_contact(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/v1/trusted-contacts', [
                'name' => 'Mamá',
                'phone' => '0991234567',
                'email' => 'mama@example.com',
                'relationship_label' => 'Familiar',
            ]);

        $response->assertCreated()->assertJsonPath('contact.name', 'Mamá');

        $this->assertDatabaseHas('trusted_contacts', [
            'user_id' => $user->id,
            'name' => 'Mamá',
            'email' => 'mama@example.com',
        ]);
    }

    public function test_a_contact_needs_at_least_a_phone_or_an_email(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/v1/trusted-contacts', ['name' => 'Sin datos'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->assertDatabaseMissing('trusted_contacts', ['name' => 'Sin datos']);
    }

    public function test_a_user_can_delete_their_own_contact(): void
    {
        $user = User::factory()->create();
        $contact = TrustedContact::query()->create([
            'user_id' => $user->id,
            'name' => 'Pareja',
            'phone' => '0990000000',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->deleteJson("/api/v1/trusted-contacts/{$contact->id}")
            ->assertOk();

        $this->assertDatabaseMissing('trusted_contacts', ['id' => $contact->id]);
    }

    public function test_a_user_cannot_delete_someone_elses_contact(): void
    {
        $owner = User::factory()->create();
        $contact = TrustedContact::query()->create([
            'user_id' => $owner->id,
            'name' => 'Pareja',
            'phone' => '0990000000',
        ]);
        $stranger = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->deleteJson("/api/v1/trusted-contacts/{$contact->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('trusted_contacts', ['id' => $contact->id]);
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/trusted-contacts')->assertUnauthorized();
    }
}
