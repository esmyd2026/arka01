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
        Schema::table('cooperatives', function (Blueprint $table) {
            // Pedido explícito del usuario: "si quieres pese a que tienen el
            // perfil publico mostrar su flota de conductores y que si no que
            // salga solo las cantidades y los conductores como bloqueados" —
            // el perfil público de la cooperativa en sí ya depende solo de
            // que esté aprobada (Cooperative::isApproved()); esto es aparte,
            // solo tapa la lista de conductores. Default true: preserva el
            // comportamiento de siempre, nadie pierde visibilidad de golpe.
            $table->boolean('show_fleet_publicly')->default(true)->after('automatic_assignment_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cooperatives', function (Blueprint $table) {
            $table->dropColumn('show_fleet_publicly');
        });
    }
};
