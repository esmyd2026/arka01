<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panel admin de conductores (pedido explícito del usuario: "bloquear o
 * deshabilitar o desconectar"). Las tres palabras apuntan al mismo problema
 * de fondo — frenar a un conductor hasta que un admin lo reactive — así que
 * se resuelven con UN solo estado en vez de tres mecanismos redundantes:
 * mientras `suspended_at` no sea null, el conductor queda desconectado ya
 * mismo (is_available se apaga al suspender) y no puede volver a conectarse,
 * ni aparecer como candidato de ninguna solicitud, hasta que un admin lo
 * reactive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('is_available');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn('suspended_at');
        });
    }
};
