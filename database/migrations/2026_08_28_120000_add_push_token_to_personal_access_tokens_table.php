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
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('push_token')->nullable()->after('app_version');
            $table->string('push_provider')->nullable()->after('push_token');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['push_token', 'push_provider']);
        });
    }
};
