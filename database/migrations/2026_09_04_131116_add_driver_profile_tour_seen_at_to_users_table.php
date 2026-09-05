<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario: tutorial guiado con Driver.js para completar
 * el perfil de conductor (vehículo, tarifa, forma de pago, documentos) —
 * mismo criterio que ride_request_tour_seen_at/fleet_tour_seen_at, un tour
 * independiente en su propia columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('driver_profile_tour_seen_at')->nullable()->after('fleet_tour_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('driver_profile_tour_seen_at');
        });
    }
};
