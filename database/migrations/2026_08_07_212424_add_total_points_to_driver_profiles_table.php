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
        // Contador corriente de puntos ganados por carreras completadas
        // (pedido explícito del usuario) — no un ledger aparte, la
        // trazabilidad de cada carrera puntual queda en rides.points_earned.
        // Se actualiza con increment() (atómico) en RideController::complete().
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->unsignedInteger('total_points')->default(0)->after('invite_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn('total_points');
        });
    }
};
