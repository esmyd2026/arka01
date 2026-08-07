<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: el aviso de "alguien pidió cerrar tu
     * sesión" (WhatsApp/correo) necesita una salida real para "si no fue
     * usted", no solo "ignore este mensaje" — acá se guarda cuándo se
     * bloqueó la cuenta desde ese aviso. Un admin la reactiva desde
     * /admin/usuarios (App\Http\Controllers\Admin\UserProfileController).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('locked_at')->nullable()->after('session_takeover_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locked_at');
        });
    }
};
