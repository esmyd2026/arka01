<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: cargo aparte por el trayecto que el
     * conductor recorre para ir a buscar al cliente. Acá se guarda el monto
     * PROPUESTO al candidato actual de la solicitud (recalculado en cada
     * salto del despacho secuencial, ver App\Services\RideDispatchAdvancer)
     * — todavía no es una decisión tomada, el conductor recién elige cobrarlo
     * o no al aceptar (ver `rides.pickup_fare_charged`,
     * App\Services\Ride\RideRequestResponder::accept()).
     */
    public function up(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->decimal('pickup_distance_km', 6, 2)->nullable()->after('distance_km');
            $table->decimal('pickup_fare', 8, 2)->nullable()->after('pickup_distance_km');
        });
    }

    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropColumn(['pickup_distance_km', 'pickup_fare']);
        });
    }
};
