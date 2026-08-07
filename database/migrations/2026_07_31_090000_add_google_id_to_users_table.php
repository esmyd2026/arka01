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
        // google_id: para iniciar sesión con Google (OAuth vía Socialite),
        // como alternativa al usuario/contraseña de siempre — no lo reemplaza.
        // Si ya existía una cuenta con ese correo, se linkea sola (mismo
        // usuario, ahora con las dos formas de entrar); si no existía, se
        // crea una cuenta nueva. Ver App\Http\Controllers\Auth\GoogleAuthController.
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique()->after('phone_verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('google_id');
        });
    }
};
