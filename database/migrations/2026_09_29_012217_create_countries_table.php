<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo de países (pedido explícito del usuario: "arka01 debe
     * funcionar en cualquier país"). Hasta acá todo el sistema asumía
     * Ecuador quemado en código (moneda dólar, prefijo +593, búsqueda de
     * direcciones restringida a Ecuador) — esta tabla es la única fuente de
     * verdad de qué país usa qué moneda/formato, administrable desde
     * /admin/paises sin volver a desplegar. El país de cada usuario NO se
     * guarda como columna aparte en ningún lado: se calcula en caliente
     * emparejando su prefijo telefónico contra `phone_prefix` (ver
     * App\Models\User::country() / App\Models\Country::forPhone()).
     */
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);

            // ISO 3166-1 alpha-2 (EC, CL...). Se usa tal cual como región de
            // Google Places (includedRegionCodes espera minúsculas, ver
            // Country::publicPayload()) y como sufijo del locale de Intl.
            $table->string('iso_code', 2)->unique();

            // Prefijo tal como lo elige el usuario en el selector de
            // registro (ej. '+593'). User::phone se guarda como
            // country_code.phone_local concatenado SIN separador, así que
            // Country::forPhone() hace coincidencia por el prefijo más largo
            // primero — evita ambigüedad si algún día dos prefijos se
            // solapan (ej. '+1' vs '+123').
            $table->string('phone_prefix', 6)->unique();

            // ISO 4217 (USD, CLP...) + símbolo mostrado al usuario. Van
            // separados porque el símbolo puede repetirse entre monedas
            // (USD y CLP comparten '$') pero el código no.
            $table->string('currency_code', 3);
            $table->string('currency_symbol', 5);

            // Cuántos decimales muestra esta moneda (USD=2, CLP=0 — en Chile
            // nadie factura fracciones de peso). Ver Utils/currency.js y
            // App\Support\Currency::format().
            $table->unsignedTinyInteger('decimal_digits')->default(2);

            // Formato del celular local, sin el prefijo. Nulo = cae al
            // genérico [0-9]{7,10} que ya usaba ValidPhoneNumberLocal antes
            // de este cambio — no todos los países necesitan una regla
            // propia.
            $table->string('phone_local_regex')->nullable();

            // Texto de ayuda para el mensaje de validación cuando el celular
            // no calza con phone_local_regex (ej. "9 dígitos, empieza en
            // 9") — sin esto el mensaje de error tendría que adivinar el
            // formato de un país que no conoce en código.
            $table->string('phone_format_hint')->nullable();

            // Antes ValidPhoneNumberLocal::normalize() pelaba el 0 inicial
            // SOLO si country_code === '+593', hardcodeado. Ahora es un dato
            // del país: no todos anteponen 0 al número local.
            $table->boolean('strips_leading_zero')->default(false);

            // Región para Google Places Autocomplete (includedRegionCodes),
            // en minúsculas tal como la pide esa API (ej. 'ec', 'cl').
            $table->string('geocoding_region_code', 2);

            // Exactamente un país debe tener esto en true: es el que se usa
            // como fallback en cualquier lugar que reciba un país nulo (ej.
            // visitante sin sesión) — hoy es Ecuador, por compatibilidad con
            // todo lo que ya existía antes de este cambio.
            $table->boolean('is_default')->default(false);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();

        // Ecuador: mismo comportamiento exacto que tenía todo el sistema
        // antes de este cambio (nada debe romperse para los usuarios
        // actuales). Chile: primer país nuevo, caso real reportado por el
        // usuario (conductores registrándose con tarifas en pesos).
        DB::table('countries')->insert([
            [
                'name' => 'Ecuador',
                'iso_code' => 'EC',
                'phone_prefix' => '+593',
                'currency_code' => 'USD',
                'currency_symbol' => '$',
                'decimal_digits' => 2,
                'phone_local_regex' => '^9\d{8}$',
                'phone_format_hint' => '9 dígitos, empieza en 9',
                'strips_leading_zero' => true,
                'geocoding_region_code' => 'ec',
                'is_default' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Chile',
                'iso_code' => 'CL',
                'phone_prefix' => '+56',
                'currency_code' => 'CLP',
                'currency_symbol' => '$',
                'decimal_digits' => 0,
                'phone_local_regex' => '^9\d{8}$',
                'phone_format_hint' => '9 dígitos, empieza en 9',
                'strips_leading_zero' => false,
                'geocoding_region_code' => 'cl',
                'is_default' => false,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
