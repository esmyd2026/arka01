<?php

namespace Tests\Feature\Admin;

use App\Models\Country;
use App\Models\PricingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pantalla de mantenimiento del catálogo de países (pedido explícito del
 * usuario: "arka01 debe funcionar en cualquier país") — de acá sale la
 * moneda, el prefijo telefónico y la región de geocodificación que usa el
 * resto del sistema, nada quemado en código. Mismo criterio que
 * LocationMaintenanceTest para las "zonas del Ecuador".
 */
class CountryMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_user_cannot_access_the_countries_maintenance_screen(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('admin.countries.index'))->assertForbidden();
    }

    public function test_an_admin_can_create_a_country(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.countries.store'), [
            'name' => 'México',
            'iso_code' => 'MX',
            'phone_prefix' => '+52',
            'currency_code' => 'MXN',
            'currency_symbol' => '$',
            'decimal_digits' => 2,
            'geocoding_region_code' => 'mx',
            'strips_leading_zero' => false,
        ])->assertRedirect();

        $this->assertDatabaseHas('countries', ['iso_code' => 'MX', 'phone_prefix' => '+52']);
    }

    /**
     * Bug reportado por el usuario (el motivo de todo este cambio): un país
     * nuevo nunca debe quedar sin fila de tarifas — si no, el primer
     * conductor que se registre ahí rompe con un error al buscarla.
     */
    public function test_creating_a_country_also_creates_its_pricing_row(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.countries.store'), [
            'name' => 'México',
            'iso_code' => 'MX',
            'phone_prefix' => '+52',
            'currency_code' => 'MXN',
            'currency_symbol' => '$',
            'decimal_digits' => 2,
            'geocoding_region_code' => 'mx',
            'strips_leading_zero' => false,
        ]);

        $mexico = Country::where('iso_code', 'MX')->firstOrFail();
        $this->assertNotNull(PricingSetting::forCountry($mexico));
    }

    public function test_an_admin_can_update_a_country(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $chile = Country::where('iso_code', 'CL')->firstOrFail();

        $this->actingAs($admin)->patch(route('admin.countries.update', $chile), [
            'name' => $chile->name,
            'iso_code' => $chile->iso_code,
            'phone_prefix' => $chile->phone_prefix,
            'currency_code' => $chile->currency_code,
            'currency_symbol' => $chile->currency_symbol,
            'decimal_digits' => $chile->decimal_digits,
            'geocoding_region_code' => $chile->geocoding_region_code,
            'strips_leading_zero' => $chile->strips_leading_zero,
            'is_active' => false,
        ])->assertRedirect();

        $this->assertFalse($chile->fresh()->is_active);
    }

    public function test_the_default_country_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $ecuador = Country::where('iso_code', 'EC')->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('admin.countries.destroy', $ecuador))
            ->assertSessionHasErrors('country');

        $this->assertDatabaseHas('countries', ['id' => $ecuador->id]);
    }

    public function test_a_country_with_pricing_configured_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $chile = Country::where('iso_code', 'CL')->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('admin.countries.destroy', $chile))
            ->assertSessionHasErrors('country');

        $this->assertDatabaseHas('countries', ['id' => $chile->id]);
    }

    public function test_country_active_cache_is_invalidated_after_a_change(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->assertNull(Country::active()->firstWhere('iso_code', 'MX'));

        $this->actingAs($admin)->post(route('admin.countries.store'), [
            'name' => 'México',
            'iso_code' => 'MX',
            'phone_prefix' => '+52',
            'currency_code' => 'MXN',
            'currency_symbol' => '$',
            'decimal_digits' => 2,
            'geocoding_region_code' => 'mx',
            'strips_leading_zero' => false,
        ]);

        $this->assertNotNull(Country::active()->firstWhere('iso_code', 'MX'));
    }
}
