<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identidad de cada usuario, más allá de correo/teléfono (consideración
     * agregada al alcance): un "usuario" legible (ej. jperez, generado de
     * las iniciales del nombre) y un código numérico de socio desde el 500,
     * ambos únicos y buscables para agregar a alguien a una flota. También
     * el par de columnas del código de verificación de teléfono por WhatsApp.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
            $table->unsignedInteger('member_code')->nullable()->unique()->after('username');
            $table->string('phone_verification_code')->nullable()->after('phone_verified_at');
            $table->timestamp('phone_verification_expires_at')->nullable()->after('phone_verification_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'member_code', 'phone_verification_code', 'phone_verification_expires_at']);
        });
    }
};
