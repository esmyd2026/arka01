<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Base para la API móvil (routes/api.php v1): cada token de Sanctum queda
 * asociado a un dispositivo concreto (device_id generado y guardado por el
 * propio cliente Capacitor), su plataforma y la versión de app que lo pidió.
 * Esto es lo que permite que EnforceSingleActiveSession trate un token móvil
 * igual que una sesión web al aplicar la regla de sesión única: distinguir
 * "mismo dispositivo reingresando" de "otro dispositivo distinto".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('device_id')->nullable()->after('name')->index();
            $table->string('platform')->nullable()->after('device_id');
            $table->string('app_version')->nullable()->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['device_id', 'platform', 'app_version']);
        });
    }
};
