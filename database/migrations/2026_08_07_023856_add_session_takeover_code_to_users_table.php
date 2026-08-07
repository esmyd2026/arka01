<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario (caso real: olvidó en qué navegador había
     * quedado logueado y la sesión única lo bloqueaba) — un código de un solo
     * uso que permite cerrar la otra sesión sin esperar a que expire sola.
     * Columnas separadas de `phone_verification_code` (que ya existe para
     * verificar el teléfono al registrarse) a propósito: son dos flujos
     * distintos, no conviene que uno pise el estado del otro.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('session_takeover_code')->nullable()->after('phone_verification_expires_at');
            $table->timestamp('session_takeover_expires_at')->nullable()->after('session_takeover_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['session_takeover_code', 'session_takeover_expires_at']);
        });
    }
};
