<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * add_country_id_to_pricing_settings_table solo migró la fila que YA
     * existía (la de Ecuador) — cualquier país agregado en el seed de
     * create_countries_table (hoy, Chile) se queda sin fila de tarifas
     * propia, y PricingSetting::forCountry() explota con firstOrFail().
     * Mismos valores de fábrica que la migración original de
     * pricing_settings, para que un país nuevo arranque en un estado
     * razonable y editable — igual que hace Admin\CountriesController::store()
     * para cualquier país que se dé de alta desde acá en adelante.
     */
    public function up(): void
    {
        $now = now();

        $countriesWithoutPricing = DB::table('countries')
            ->whereNotIn('id', DB::table('pricing_settings')->pluck('country_id'))
            ->pluck('id');

        foreach ($countriesWithoutPricing as $countryId) {
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
     *
     * No hace falta deshacer nada acá: son filas de datos, no de esquema, y
     * down() de add_country_id_to_pricing_settings_table ya se encarga de
     * la columna si algún día se revierte esa migración.
     */
    public function down(): void {}
};
