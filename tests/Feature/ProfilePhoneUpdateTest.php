<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: "que tambien pueda actualizar su numero de
 * telefono... y que cuando ingrese el numero le invite a escribirle al
 * asistente de whatsapp para confirmar su numero" — mismo mecanismo que ya
 * prueba DriverProfilePhoneUpdateTest, mirroreado acá para el cliente.
 */
class ProfilePhoneUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function enableWhatsApp(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
    }

    public function test_a_client_can_update_their_phone_number(): void
    {
        $client = User::factory()->create(['phone' => '+593991111111']);

        $this->actingAs($client)->patch('/profile', [
            'name' => $client->name,
            'email' => $client->email,
            'country_code' => '+593',
            'phone_local' => '992222222',
        ])->assertSessionHasNoErrors();

        $this->assertSame('+593992222222', $client->fresh()->phone);
    }

    public function test_leaving_the_phone_fields_blank_does_not_touch_the_existing_phone(): void
    {
        $client = User::factory()->create(['phone' => '+593991111111']);

        $this->actingAs($client)->patch('/profile', [
            'name' => $client->name,
            'email' => $client->email,
        ])->assertSessionHasNoErrors();

        $this->assertSame('+593991111111', $client->fresh()->phone);
    }

    public function test_a_client_cannot_set_a_phone_already_used_by_another_account(): void
    {
        User::factory()->create(['phone' => '+593992222222']);
        $client = User::factory()->create(['phone' => '+593991111111']);

        $this->actingAs($client)->patch('/profile', [
            'name' => $client->name,
            'email' => $client->email,
            'country_code' => '+593',
            'phone_local' => '992222222',
        ])->assertSessionHasErrors('phone_local');

        $this->assertSame('+593991111111', $client->fresh()->phone);
    }

    public function test_changing_the_phone_resets_verification_and_requires_it_again(): void
    {
        $this->enableWhatsApp();
        $client = User::factory()->create(['phone' => '+593991111111', 'phone_verified_at' => now()]);

        $this->actingAs($client)->patch('/profile', [
            'name' => $client->name,
            'email' => $client->email,
            'country_code' => '+593',
            'phone_local' => '992222222',
        ])->assertSessionHasNoErrors();

        $this->assertNull($client->fresh()->phone_verified_at);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && $request['type'] === 'template');
    }

    public function test_changing_the_phone_without_whatsapp_verification_configured_stays_auto_verified(): void
    {
        $client = User::factory()->create(['phone' => '+593991111111', 'phone_verified_at' => now()]);

        $this->actingAs($client)->patch('/profile', [
            'name' => $client->name,
            'email' => $client->email,
            'country_code' => '+593',
            'phone_local' => '992222222',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($client->fresh()->phone_verified_at);
    }

    /**
     * Mismo criterio que el conductor: si el envío falla de verdad, no puede
     * quedar el número nuevo trabado sin verificar para siempre.
     */
    public function test_changing_the_phone_when_the_whatsapp_send_actually_fails_stays_verified(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 401)]);

        $client = User::factory()->create(['phone' => '+593991111111', 'phone_verified_at' => now()]);

        $this->actingAs($client)->patch('/profile', [
            'name' => $client->name,
            'email' => $client->email,
            'country_code' => '+593',
            'phone_local' => '992222222',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($client->fresh()->phone_verified_at);
        $this->assertNull($client->fresh()->phone_verification_code);
    }
}
