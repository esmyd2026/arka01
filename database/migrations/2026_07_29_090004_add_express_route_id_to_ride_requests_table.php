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
        // Traza qué solicitudes de carrera vinieron de la generación
        // automática diaria de un Expreso (sección 4.2), para poder mostrar
        // el historial del contrato y para que los reportes de incumplimiento
        // (express_incidents) sepan a qué Expreso pertenece la carrera.
        // nullOnDelete (no cascade): si se borra el Expreso, el historial de
        // carreras ya generadas se conserva, solo pierde la referencia.
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->foreignId('express_route_id')->nullable()->after('fleet_id')
                ->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('express_route_id');
        });
    }
};
