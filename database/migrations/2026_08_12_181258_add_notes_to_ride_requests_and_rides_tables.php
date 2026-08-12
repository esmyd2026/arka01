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
        // Pedido explícito del usuario: un campo de observación para el
        // cliente al pedir la carrera (ej. "el portón es el azul", "llamar
        // al llegar") — libre, nunca obligatorio. Se copia tal cual a
        // `rides.notes` al aceptar (mismo criterio que `payment_method`/
        // `round_trip`, ver RideRequestController::accept()), así el
        // conductor la sigue teniendo a mano durante toda la carrera, no
        // solo al decidir si aceptar.
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('needs_trunk');
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('payment_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropColumn('notes');
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
