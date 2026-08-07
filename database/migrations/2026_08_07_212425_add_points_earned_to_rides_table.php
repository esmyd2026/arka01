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
        // Cuántos puntos dio ESTA carrera puntual al completarse (pedido
        // explícito del usuario: puntos por carrera completada, según la
        // distancia) — nullable porque las carreras ya completadas antes de
        // este cambio, y cualquiera que no llegue a completarse, no tienen
        // puntos. Sirve de trazabilidad de driver_profiles.total_points sin
        // necesitar una tabla de movimientos aparte.
        Schema::table('rides', function (Blueprint $table) {
            $table->unsignedInteger('points_earned')->nullable()->after('price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('points_earned');
        });
    }
};
