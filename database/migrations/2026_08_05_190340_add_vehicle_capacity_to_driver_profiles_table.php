<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario: al pedir una carrera, el cliente indica
 * cuántos pasajeros van y si necesita cajuela (maletas) — para eso hace
 * falta saber la capacidad real del vehículo de cada conductor, no solo la
 * marca/modelo. Estos tres campos, junto con los que ya existían
 * (marca/modelo/placa/año), pasan a ser obligatorios en el perfil del
 * conductor (App\Http\Controllers\DriverProfileController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('vehicle_color')->nullable()->after('vehicle_model');
            $table->unsignedTinyInteger('passenger_capacity')->nullable()->after('vehicle_year');
            $table->boolean('has_trunk')->default(false)->after('passenger_capacity');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn(['vehicle_color', 'passenger_capacity', 'has_trunk']);
        });
    }
};
