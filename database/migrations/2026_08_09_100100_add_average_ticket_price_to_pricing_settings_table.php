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
        // Ticket promedio por carrera (pedido explícito del usuario): único
        // valor global que, junto con `estimated_monthly_rides` de cada
        // plan, arma la proyección de ganancia mensual que ve el conductor
        // al elegir su plan. $3.00 es el valor que usó el usuario en su
        // propio ejemplo — editable desde /admin/tarifas.
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->decimal('average_ticket_price', 8, 2)->default(3.00)->after('minimum_fare');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->dropColumn('average_ticket_price');
        });
    }
};
