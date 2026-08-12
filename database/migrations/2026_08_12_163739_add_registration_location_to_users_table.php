<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Pedido explícito del usuario: "ver de dónde se registran las
            // personas, por su ubicación" — coordenadas reales del navegador
            // en el momento del registro (con su permiso, ver
            // Register.vue), no la ciudad que el usuario elige a mano en su
            // perfil (users.city_id, que ya existía). `registration_neighborhood`
            // es informativo, resuelto después en cola vía OpenStreetMap
            // Nominatim (ver App\Jobs\ResolveRegistrationNeighborhood) — no
            // se fuerza a calzar contra el catálogo propio de `sectors`
            // (ese no tiene coordenadas, ver LocationController).
            $table->decimal('registration_lat', 10, 7)->nullable()->after('city_id');
            $table->decimal('registration_lng', 10, 7)->nullable()->after('registration_lat');
            $table->string('registration_neighborhood')->nullable()->after('registration_lng');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['registration_lat', 'registration_lng', 'registration_neighborhood']);
        });
    }
};
