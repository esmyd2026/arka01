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
        // Falla real en producción ("Duplicate column name 'device_id'"):
        // esa base ya tenía la columna (creada a mano o por una corrida
        // anterior) pero la tabla `migrations` no tenía registro de esta
        // migración — Laravel la vuelve a correr entera y MySQL aborta el
        // deploy completo. Mismo criterio defensivo que ya usa la migración
        // siguiente (2026_08_28_120000_add_push_token...): cada columna se
        // agrega solo si todavía no existe, así es segura de correr sin
        // importar el estado real de esa base.
        if (! Schema::hasColumn('personal_access_tokens', 'device_id')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->string('device_id')->nullable()->after('name')->index();
            });
        }

        if (! Schema::hasColumn('personal_access_tokens', 'platform')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->string('platform')->nullable()->after('device_id');
            });
        }

        if (! Schema::hasColumn('personal_access_tokens', 'app_version')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->string('app_version')->nullable()->after('platform');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('personal_access_tokens', 'app_version')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropColumn('app_version');
            });
        }

        if (Schema::hasColumn('personal_access_tokens', 'platform')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropColumn('platform');
            });
        }

        if (Schema::hasColumn('personal_access_tokens', 'device_id')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropColumn('device_id');
            });
        }
    }
};
