<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `stops_price`: copiado de `ride_requests.stops_price` al aceptar (ver
     * RideRequestController::accept()). `settled_price`: el monto REAL
     * cobrado al cerrar la carrera — `stops_price + price` si se completó
     * entera, o solo la suma de los tramos ya completados si el conductor
     * cerró antes (pedido explícito del usuario). `price` sigue significando
     * exactamente lo mismo que hoy: el precio del tramo final.
     */
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->decimal('stops_price', 8, 2)->nullable()->after('price');
            $table->decimal('settled_price', 8, 2)->nullable()->after('stops_price');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['stops_price', 'settled_price']);
        });
    }
};
