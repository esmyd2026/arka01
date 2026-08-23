<?php

namespace Tests\Feature;

use App\Models\Cooperative;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestRideTest extends TestCase
{
    use RefreshDatabase;

    private function approvedCooperative(): Cooperative
    {
        $owner = User::factory()->create();
        $cooperative = Cooperative::query()->create([
            'user_id' => $owner->id,
            'name' => 'Cooperativa Segura',
            'stand_lat' => -2.1709,
            'stand_lng' => -79.9224,
        ]);
        $cooperative->forceFill(['status' => 'approved'])->save();

        return $cooperative;
    }

    private function validPayload(Cooperative $cooperative): array
    {
        return [
            'name' => 'Invitada Prueba',
            'country_code' => '+593',
            'phone_local' => '991234567',
            'cooperative_id' => $cooperative->id,
            'origin_address' => 'Alborada, Guayaquil',
            'origin_lat' => -2.145,
            'origin_lng' => -79.89,
            'destination_address' => 'Centro, Guayaquil',
            'destination_lat' => -2.19,
            'destination_lng' => -79.88,
            'website' => '',
        ];
    }

    public function test_guest_can_continue_without_email_when_whatsapp_is_disabled_in_tests(): void
    {
        $cooperative = $this->approvedCooperative();

        $response = $this->post(route('guest-rides.store'), $this->validPayload($cooperative));

        $guest = User::query()->where('phone', '+593991234567')->firstOrFail();
        $this->assertAuthenticatedAs($guest);
        $this->assertNotNull($guest->phone_verified_at);
        $this->assertStringEndsWith('@guest.arka01.local', $guest->email);
        $this->assertDatabaseHas('client_cooperatives', [
            'client_user_id' => $guest->id,
            'cooperative_id' => $cooperative->id,
        ]);
        $response->assertRedirectContains('/flota/solicitar');
    }

    public function test_honeypot_blocks_automated_guest_registration(): void
    {
        $cooperative = $this->approvedCooperative();
        $payload = $this->validPayload($cooperative);
        $payload['website'] = 'https://spam.example';

        $this->post(route('guest-rides.store'), $payload)
            ->assertSessionHasErrors('website');

        $this->assertDatabaseMissing('users', ['phone' => '+593991234567']);
    }

    public function test_an_unapproved_cooperative_cannot_receive_a_guest_request(): void
    {
        $cooperative = $this->approvedCooperative();
        $cooperative->forceFill(['status' => 'pending'])->save();

        $this->post(route('guest-rides.store'), $this->validPayload($cooperative))
            ->assertSessionHasErrors('cooperative_id');

        $this->assertDatabaseMissing('users', ['phone' => '+593991234567']);
    }

    // Pedido explícito del usuario: "si pone el 09 quitemos el 0 delante" —
    // en vez de rechazarlo, se normaliza antes de validar (ver
    // ValidPhoneNumberLocal::normalize()).
    public function test_a_leading_zero_is_stripped_instead_of_rejected(): void
    {
        $cooperative = $this->approvedCooperative();
        $payload = $this->validPayload($cooperative);
        $payload['phone_local'] = '0991234567';

        $this->post(route('guest-rides.store'), $payload)->assertSessionDoesntHaveErrors('phone_local');

        $this->assertDatabaseHas('users', ['phone' => '+593991234567']);
    }

    // Pedido explícito del usuario: "si el numero esta registrado entonces
    // que lo lleve a iniciar sesion" — Welcome.vue detecta este mensaje
    // exacto para ofrecer el atajo (ver showsAccountExistsError).
    public function test_an_already_registered_phone_is_rejected_with_a_login_hint(): void
    {
        $cooperative = $this->approvedCooperative();
        User::factory()->create(['phone' => '+593991234567']);

        $this->post(route('guest-rides.store'), $this->validPayload($cooperative))
            ->assertSessionHasErrors(['phone_local' => 'Este número ya tiene una cuenta. Inicie sesión para continuar de forma segura.']);
    }
}
