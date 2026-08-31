<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario: completar una parada lejos del punto exacto
 * debe permitir un motivo, igual que ya funciona al completar el destino
 * final de la carrera (ver 2026_08_23_183240_add_completion_details_to_rides_table.php,
 * mismo par de columnas, mismo criterio).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ride_stops', function (Blueprint $table) {
            $table->string('completion_reason')->nullable()->after('completed_at');
            $table->text('completion_note')->nullable()->after('completion_reason');
        });
    }

    public function down(): void
    {
        Schema::table('ride_stops', function (Blueprint $table) {
            $table->dropColumn(['completion_reason', 'completion_note']);
        });
    }
};
