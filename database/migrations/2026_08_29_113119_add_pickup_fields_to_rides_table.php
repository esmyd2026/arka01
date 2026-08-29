<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: cargo aparte por el trayecto que el
     * conductor recorre para ir a buscar al cliente — snapshot final de lo
     * que ya se calculó en `ride_requests` al momento de aceptar, más
     * `pickup_fare_charged`, la decisión real del conductor (el desglose se
     * le muestra antes de aceptar, pero es él quien decide si lo cobra o
     * no). Columnas propias, no mezcladas en `price`, para poder armar
     * indicadores después.
     */
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->decimal('pickup_distance_km', 6, 2)->nullable()->after('distance_km');
            $table->decimal('pickup_fare', 8, 2)->nullable()->after('pickup_distance_km');
            $table->boolean('pickup_fare_charged')->default(false)->after('pickup_fare');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['pickup_distance_km', 'pickup_fare', 'pickup_fare_charged']);
        });
    }
};
