<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groundwork del Hito 6 (notificaciones push nativas): el token de FCM
 * (Android) o APNs (iOS) que el propio dispositivo genera al registrarse
 * para notificaciones — vive en el mismo token de Sanctum que ya identifica
 * "este dispositivo" (device_id/platform/app_version, ver la migración
 * anterior), no en una tabla aparte: acá un dispositivo YA es una fila de
 * personal_access_tokens. `push_subscriptions` (paquete WebPush) es un
 * mecanismo totalmente distinto, para navegadores/PWA, no para la app nativa.
 */
return new class extends Migration
{
    public function up(): void
    {
        // En producción puede existir una base que registró la migración de
        // información del dispositivo, pero cuyo esquema no conserva todas
        // sus columnas. No debemos depender de `after('app_version')` hasta
        // reparar esa diferencia: MySQL aborta toda la migración si la columna
        // de referencia no existe.
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

        if (! Schema::hasColumn('personal_access_tokens', 'push_token')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->string('push_token')->nullable()->after('app_version');
            });
        }

        if (! Schema::hasColumn('personal_access_tokens', 'push_provider')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->string('push_provider')->nullable()->after('push_token');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('personal_access_tokens', 'push_provider')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropColumn('push_provider');
            });
        }

        if (Schema::hasColumn('personal_access_tokens', 'push_token')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropColumn('push_token');
            });
        }
    }
};
