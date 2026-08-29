<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nuevo tipo de aviso apagable (App\Services\WhatsAppRideAccess::NOTIFICATION_TYPES):
     * avisar al conductor cuando su ventana de WhatsApp está por cerrarse —
     * ver App\Console\Commands\NotifyExpiringWhatsAppSessions.
     */
    public function up(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->boolean('notify_session_expiring_soon')->default(true)->after('notify_driver_disconnected');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->dropColumn('notify_session_expiring_soon');
        });
    }
};
