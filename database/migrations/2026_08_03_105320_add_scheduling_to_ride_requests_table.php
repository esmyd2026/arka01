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
        // Pedir una carrera "para ahora mismo" (default, comportamiento de
        // siempre) o "programada" para una fecha/hora futura, con la opción
        // de marcarla como ida y vuelta (consideración agregada al alcance,
        // pedido explícito del usuario). `requested_at` sigue significando
        // "cuándo se hizo el pedido" (para el "esperando hace X minutos" de
        // Ride/Index.vue); `scheduled_at` es un dato aparte: "para cuándo es".
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->boolean('is_scheduled')->default(false)->after('requested_at');
            $table->timestamp('scheduled_at')->nullable()->after('is_scheduled');
            $table->boolean('round_trip')->default(false)->after('scheduled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropColumn(['is_scheduled', 'scheduled_at', 'round_trip']);
        });
    }
};
