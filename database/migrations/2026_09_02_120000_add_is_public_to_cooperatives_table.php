<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario: hoy un cliente solo ve en "Elige tu
 * conductor" a las cooperativas que YA agregó (ClientCooperative). Este
 * flag, activado a mano por un admin desde /admin/cooperativas, hace que
 * una cooperativa puntual aparezca para CUALQUIER cliente sin que la haya
 * agregado — con una insignia "Pública" para que se note que no fue el
 * cliente quien la sumó. Arranca en false: ninguna cooperativa existente
 * cambia de comportamiento sin que el admin la prenda a propósito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cooperatives', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->after('show_fleet_publicly');
        });
    }

    public function down(): void
    {
        Schema::table('cooperatives', function (Blueprint $table) {
            $table->dropColumn('is_public');
        });
    }
};
