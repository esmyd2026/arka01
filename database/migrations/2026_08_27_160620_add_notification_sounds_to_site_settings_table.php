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
        Schema::table('site_settings', function (Blueprint $table) {
            // Pedido explícito del usuario: "una lista de sonidos que pueda
            // seleccionar para las notificaciones... desde el panel
            // administrativo. y que tenga todo el volumen" — qué sonido
            // (App\Services\NotificationSoundRegistry) usa cada categoría de
            // aviso, más un volumen maestro (0-100) para todos.
            $table->json('notification_sounds')->nullable()->after('disabled_driver_requirements');
            $table->unsignedTinyInteger('notification_volume')->default(100)->after('notification_sounds');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['notification_sounds', 'notification_volume']);
        });
    }
};
