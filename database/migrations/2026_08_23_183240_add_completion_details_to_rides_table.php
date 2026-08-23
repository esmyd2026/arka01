<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: el conductor puede completar la carrera
     * de cerca del destino (20 m) sin más trámite, pero si está más lejos
     * tiene que elegir un motivo de una lista antes de poder completarla
     * igual — esa info le llega al cliente. Mismo patrón que
     * cancellation_reason/cancellation_note (2026_08_12_180304).
     */
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->string('completion_reason')->nullable()->after('completed_at');
            $table->text('completion_note')->nullable()->after('completion_reason');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['completion_reason', 'completion_note']);
        });
    }
};
