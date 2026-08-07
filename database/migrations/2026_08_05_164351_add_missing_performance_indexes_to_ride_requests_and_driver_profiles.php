<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rendimiento en producción (pedido explícito del usuario, evaluación previa
 * a un despliegue con miles de usuarios): estas dos columnas se filtran en
 * cada pedido de carrera y no tenían índice, forzando un escaneo completo de
 * tabla a medida que crecen `ride_requests` y `driver_profiles`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            // App\Jobs\ExpireRideOffer / el scheduler de cascada filtran por
            // esta columna para encontrar ofertas vencidas.
            $table->index('current_offer_expires_at');
        });

        Schema::table('driver_profiles', function (Blueprint $table) {
            // App\Services\RideDispatchCandidates y la búsqueda de
            // "conductores cerca" filtran primero por disponibilidad antes
            // de calcular distancia — sin índice, escanean todo el catálogo
            // de conductores en cada solicitud de carrera.
            $table->index('is_available');
        });
    }

    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropIndex(['current_offer_expires_at']);
        });

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropIndex(['is_available']);
        });
    }
};
