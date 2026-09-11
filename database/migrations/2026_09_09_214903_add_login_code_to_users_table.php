<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario: iniciar sesión con un código de WhatsApp en
 * vez de contraseña — columnas propias, NO se reutiliza
 * `phone_verification_code` (verificar el teléfono es otra cosa) ni
 * `session_takeover_code` (cerrar la otra sesión es otra cosa), mismo
 * criterio que ya separó esos dos entre sí: "no debería pisar ni depender
 * de ese estado". Ver User::issueLoginCode()/verifyLoginCode().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('login_code')->nullable()->after('session_takeover_expires_at');
            $table->timestamp('login_code_expires_at')->nullable()->after('login_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['login_code', 'login_code_expires_at']);
        });
    }
};
