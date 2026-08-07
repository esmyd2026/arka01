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
        // El conductor puede "deshabilitar" a un cliente puntual de su
        // propia flota (pedido explícito del usuario): deja de recibir
        // solicitudes de esa cuenta sin tener que cortar la relación entera
        // (eso ya existe: salir de la flota). Es lo mismo en espíritu que la
        // zona de cobertura por distancia — un filtro más antes de que una
        // solicitud le llegue.
        Schema::table('fleet_members', function (Blueprint $table) {
            $table->boolean('requests_disabled')->default(false)->after('left_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fleet_members', function (Blueprint $table) {
            $table->dropColumn('requests_disabled');
        });
    }
};
