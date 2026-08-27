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
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            // Nuevo tipo de aviso apagable (App\Services\WhatsAppRideAccess::NOTIFICATION_TYPES):
            // avisar al conductor cuando una carrera programada ya venció y
            // no la inició — ver App\Console\Commands\SendOverdueScheduledRideAlerts.
            $table->boolean('notify_scheduled_overdue')->default(true)->after('notify_scheduled_reminder');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->dropColumn('notify_scheduled_overdue');
        });
    }
};
