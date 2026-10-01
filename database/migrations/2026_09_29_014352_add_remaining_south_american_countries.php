<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * create_countries_table solo sembró Ecuador y Chile — pero el sistema
     * ya aceptaba registros de Perú, Colombia, Venezuela y Argentina desde
     * antes (la vieja lista fija RegisteredUserController::COUNTRY_CODES:
     * '+51', '+57', '+58', '+54'). Sin esta migración, un usuario de esos
     * países quedaba rechazado por el validador dinámico nuevo — regresión
     * real detectada corriendo el test suite. Mismo criterio que Ecuador:
     * sin phone_local_regex propio (cae al genérico de 7-10 dígitos, IGUAL
     * que se comportaban estos 4 países antes de este cambio completo).
     */
    public function up(): void
    {
        $now = now();

        DB::table('countries')->insert([
            [
                'name' => 'Perú',
                'iso_code' => 'PE',
                'phone_prefix' => '+51',
                'currency_code' => 'PEN',
                'currency_symbol' => 'S/',
                'decimal_digits' => 2,
                'phone_local_regex' => null,
                'phone_format_hint' => null,
                'strips_leading_zero' => false,
                'geocoding_region_code' => 'pe',
                'is_default' => false,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Colombia',
                'iso_code' => 'CO',
                'phone_prefix' => '+57',
                'currency_code' => 'COP',
                'currency_symbol' => '$',
                'decimal_digits' => 0,
                'phone_local_regex' => null,
                'phone_format_hint' => null,
                'strips_leading_zero' => false,
                'geocoding_region_code' => 'co',
                'is_default' => false,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Venezuela',
                'iso_code' => 'VE',
                'phone_prefix' => '+58',
                'currency_code' => 'VES',
                'currency_symbol' => 'Bs.',
                'decimal_digits' => 2,
                'phone_local_regex' => null,
                'phone_format_hint' => null,
                'strips_leading_zero' => false,
                'geocoding_region_code' => 've',
                'is_default' => false,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Argentina',
                'iso_code' => 'AR',
                'phone_prefix' => '+54',
                'currency_code' => 'ARS',
                'currency_symbol' => '$',
                'decimal_digits' => 2,
                'phone_local_regex' => null,
                'phone_format_hint' => null,
                'strips_leading_zero' => false,
                'geocoding_region_code' => 'ar',
                'is_default' => false,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Mismos valores de fábrica que create_pricing_settings_table —
        // sin esto, PricingSetting::forCountry() explota con firstOrFail()
        // apenas alguien de estos países pide una carrera o se registra
        // como conductor (ver seed_missing_pricing_settings_for_countries,
        // mismo criterio, para Ecuador/Chile).
        $countryIds = DB::table('countries')->whereIn('iso_code', ['PE', 'CO', 'VE', 'AR'])->pluck('id');
        foreach ($countryIds as $countryId) {
            DB::table('pricing_settings')->insert([
                'country_id' => $countryId,
                'night_surcharge_percent' => 20,
                'night_starts_at' => 20,
                'night_ends_at' => 6,
                'peak_surcharge_percent' => 0,
                'peak_morning_starts_at' => 7,
                'peak_morning_ends_at' => 9,
                'peak_evening_starts_at' => 17,
                'peak_evening_ends_at' => 19,
                'pickup_surcharge_threshold_km' => 2,
                'pickup_surcharge_percent' => 0,
                'minimum_fare' => 0,
                'average_ticket_price' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('countries')->whereIn('iso_code', ['PE', 'CO', 'VE', 'AR'])->delete();
    }
};
