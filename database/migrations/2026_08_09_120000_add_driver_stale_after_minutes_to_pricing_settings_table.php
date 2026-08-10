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
        // Pedido explícito del usuario ("ese tiempo de inactividad, ¿lo
        // puedo subir desde el panel de administrador?"): antes era la
        // constante DriverProfile::STALE_AFTER_MINUTES, fija en el código.
        // Mismo criterio que el resto de pricing_settings — editable desde
        // /admin/tarifas sin volver a desplegar. 2 minutos es el mismo valor
        // que tenía la constante, así que no cambia nada para quien no lo
        // toque.
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->unsignedInteger('driver_stale_after_minutes')->default(2)->after('average_ticket_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->dropColumn('driver_stale_after_minutes');
        });
    }
};
