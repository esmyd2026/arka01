<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario: la regla anticaptura (App\Services\Driver\
 * DriverAccessResolver::ensureDriverCanBePrivatelyLinked()) protege por
 * defecto a TODAS las cooperativas — un conductor no puede aceptar en su
 * flota privada a un cliente que llegó por una carrera de cooperativa o que
 * ya tiene agregada la cooperativa del conductor. Algunas cooperativas
 * quieren renunciar a esa protección para sus propios conductores (que sí
 * puedan quedarse con esos clientes de forma privada) — default `true`
 * (activa) para no cambiar el comportamiento de ninguna cooperativa
 * existente hasta que un admin decida apagarlo puntualmente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cooperatives', function (Blueprint $table) {
            $table->boolean('anti_capture_enabled')->default(true)->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('cooperatives', function (Blueprint $table) {
            $table->dropColumn('anti_capture_enabled');
        });
    }
};
