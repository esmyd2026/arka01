<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario: cuántos pasajeros van (por defecto 1) y si
 * hace falta cajuela para maletas (por defecto no) — se usan para filtrar
 * qué conductores pueden tomar la carrera (App\Services\RideDispatchCandidates,
 * RideRequestController::store()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->unsignedTinyInteger('passenger_count')->default(1)->after('round_trip');
            $table->boolean('needs_trunk')->default(false)->after('passenger_count');
        });
    }

    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropColumn(['passenger_count', 'needs_trunk']);
        });
    }
};
