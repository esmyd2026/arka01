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
        // express_conditions: condiciones pactadas por ruta (sección 4.1 y
        // 4.3), ej. "aire acondicionado", "puntualidad". Si no se cumple una,
        // el cliente la reporta desde la carrera puntual del día (ver
        // express_incidents), y el incumplimiento queda en el historial del
        // conductor y del Expreso.
        Schema::create('express_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('express_route_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('express_conditions');
    }
};
