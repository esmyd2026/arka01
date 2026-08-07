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
        // express_incidents: reporte de incumplimiento de una condición
        // pactada (sección 4.3), ligado a la carrera puntual del día en que
        // pasó — visible para el administrador ante una disputa (sección 9.6).
        Schema::create('express_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('express_route_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ride_id')->constrained()->cascadeOnDelete();

            // Nulo = queja general, no atada a una condición puntual de la lista.
            $table->foreignId('express_condition_id')->nullable()->constrained('express_conditions')->nullOnDelete();

            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->string('description');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('express_incidents');
    }
};
