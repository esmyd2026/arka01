<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de rechazos por conductor (pedido explícito del usuario): un
 * contador simple es suficiente para "cuántas veces rechazó" — no hace falta
 * una tabla de auditoría aparte con una fila por rechazo, este número solo
 * se usa para ver, de un vistazo en el panel admin, qué conductor rechaza
 * demasiado seguido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->unsignedInteger('rides_rejected_count')->default(0)->after('rate_per_km');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn('rides_rejected_count');
        });
    }
};
