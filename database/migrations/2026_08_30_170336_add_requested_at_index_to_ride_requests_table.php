<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rendimiento en producción (evaluación previa a un despliegue con miles de
 * usuarios, pedido explícito del usuario): Admin\OperationsController ahora
 * filtra `demandByHour()`/`waitTimeStats()` por una ventana reciente de
 * `requested_at` — sin índice, ese filtro igual escanea toda la tabla a
 * medida que crece.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->index('requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropIndex(['requested_at']);
        });
    }
};
