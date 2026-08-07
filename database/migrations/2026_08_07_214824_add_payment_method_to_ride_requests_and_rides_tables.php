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
        // Forma de pago (pedido explícito del usuario): el cliente todavía no
        // la elegía en ningún lado — se agrega acá, con "efectivo" de
        // default, y se copia a la carrera confirmada al aceptar (mismo
        // patrón que rate_per_km_snapshot/price, ver RideRequestController::accept()).
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->enum('payment_method', ['efectivo', 'transferencia'])->default('efectivo')->after('distance_km');
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->enum('payment_method', ['efectivo', 'transferencia'])->default('efectivo')->after('distance_km');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });
    }
};
