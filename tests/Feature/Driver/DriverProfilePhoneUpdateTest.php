<?php

namespace Tests\Feature\Driver;

use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pedido explícito del usuario: el conductor tiene que poder corregir el
 * número de WhatsApp que declaró en su perfil — es el que se valida contra
 * el que usa para conectarse (WhatsAppWebhookController) y al que le llegan
 * los avisos de carrera nueva. Un número es de una sola cuenta a la vez.
 */
class DriverProfilePhoneUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function baseVehiclePayload(): array
    {
        return [
            'license_number' => 'ABC123',
            'vehicle_make' => 'Chevrolet',
            'vehicle_model' => 'Spark',
            'vehicle_color' => 'Blanco',
            'vehicle_type' => 'sedan',
            'vehicle_plate' => 'ABC-1234',
            'vehicle_year' => 2020,
            'passenger_capacity' => 4,
            'has_trunk' => true,
            'rate_per_km' => 0.5,
        ];
    }

    private function enableWhatsApp(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.fake']]], 200)]);
    }

    public function test_a_driver_can_update_their_declared_phone_number(): void
    {
        $driver = User::factory()->create(['phone' => '+593991111111']);
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($driver)->post(route('driver.profile.update'), $this->baseVehiclePayload() + [
            'country_code' => '+593',
            'phone_local' => '992222222',
        ])->assertSessionHasNoErrors();

        $this->assertSame('+593992222222', $driver->fresh()->phone);
    }

    public function test_leaving_the_phone_fields_blank_does_not_touch_the_existing_phone(): void
    {
        $driver = User::factory()->create(['phone' => '+593991111111']);
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($driver)->post(route('driver.profile.update'), $this->baseVehiclePayload())
            ->assertSessionHasNoErrors();

        $this->assertSame('+593991111111', $driver->fresh()->phone);
    }

    /**
     * Caso real reportado por el usuario (visto también en el webhook): un
     * número de WhatsApp no puede quedar vinculado a dos cuentas a la vez.
     */
    public function test_a_driver_cannot_set_a_phone_already_used_by_another_account(): void
    {
        User::factory()->create(['phone' => '+593992222222']);

        $driver = User::factory()->create(['phone' => '+593991111111']);
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($driver)->post(route('driver.profile.update'), $this->baseVehiclePayload() + [
            'country_code' => '+593',
            'phone_local' => '992222222',
        ])->assertSessionHasErrors('phone_local');

        $this->assertSame('+593991111111', $driver->fresh()->phone);
    }

    public function test_changing_the_phone_resets_verification_and_requires_it_again(): void
    {
        $this->enableWhatsApp();

        $driver = User::factory()->create(['phone' => '+593991111111', 'phone_verified_at' => now()]);
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($driver)->post(route('driver.profile.update'), $this->baseVehiclePayload() + [
            'country_code' => '+593',
            'phone_local' => '992222222',
        ])->assertSessionHasNoErrors();

        $this->assertNull($driver->fresh()->phone_verified_at);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && $request['type'] === 'template');
    }

    public function test_changing_the_phone_without_whatsapp_verification_configured_stays_auto_verified(): void
    {
        $driver = User::factory()->create(['phone' => '+593991111111', 'phone_verified_at' => now()]);
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($driver)->post(route('driver.profile.update'), $this->baseVehiclePayload() + [
            'country_code' => '+593',
            'phone_local' => '992222222',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($driver->fresh()->phone_verified_at);
    }

    /**
     * Bug crítico reportado por el usuario: si la integración está
     * configurada pero el envío falla de verdad, no puede quedar el número
     * nuevo trabado sin verificar para siempre — mismo criterio que "no
     * configurada".
     */
    public function test_changing_the_phone_when_the_whatsapp_send_actually_fails_stays_verified(): void
    {
        Config::set('services.whatsapp.token', 'fake-token');
        Config::set('services.whatsapp.phone_number_id', '123456');
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 401)]);

        $driver = User::factory()->create(['phone' => '+593991111111', 'phone_verified_at' => now()]);
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($driver)->post(route('driver.profile.update'), $this->baseVehiclePayload() + [
            'country_code' => '+593',
            'phone_local' => '992222222',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($driver->fresh()->phone_verified_at);
        $this->assertNull($driver->fresh()->phone_verification_code);
    }

    /**
     * Pedido explícito del usuario (con capturas): antes cualquier cadena de
     * 7 a 10 dígitos pasaba, incluidos números obviamente falsos como
     * 999999999 — ver App\Rules\ValidPhoneNumberLocal, mismo criterio que en
     * el registro (RegistrationTest).
     */
    /**
     * Pedido explícito del usuario ("mejoremos la privacidad de los
     * conductores... habilitar su perfil al publico") — distinto de
     * is_public (directorio): esto no está gateado por plan, cualquier
     * conductor lo puede apagar.
     */
    public function test_a_driver_can_turn_off_their_individual_public_profile(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create(['profile_public' => true]);

        $this->actingAs($driver)->post(route('driver.profile.update'), $this->baseVehiclePayload() + [
            'profile_public' => false,
        ])->assertSessionHasNoErrors();

        $this->assertFalse($driver->driverProfile->fresh()->profile_public);
    }

    /**
     * La columna en sí ya tiene default true (ver la migración) — acá se
     * confirma que dejar `profile_public` fuera del payload de un guardado
     * (mismo criterio que "sometimes" en el resto del formulario) no la
     * apaga por accidente.
     */
    public function test_a_driver_profile_stays_public_by_default_when_not_sent(): void
    {
        $driver = User::factory()->create();
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($driver)->post(route('driver.profile.update'), $this->baseVehiclePayload())
            ->assertSessionHasNoErrors();

        $this->assertTrue($driver->driverProfile->fresh()->profile_public);
    }

    public function test_an_obviously_fake_ecuadorian_number_is_rejected_on_the_profile_too(): void
    {
        $driver = User::factory()->create(['phone' => '+593991111111']);
        DriverProfile::factory()->for($driver)->create();

        $this->actingAs($driver)->post(route('driver.profile.update'), $this->baseVehiclePayload() + [
            'country_code' => '+593',
            'phone_local' => '999999999',
        ])->assertSessionHasErrors('phone_local');

        $this->assertSame('+593991111111', $driver->fresh()->phone);
    }
}
