<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: avisar por WhatsApp al conductor antes de
     * que se le cierre la ventana de 24 horas, con un botón para que escriba
     * y así la reabra — este campo evita mandarle el mismo aviso una y otra
     * vez mientras el comando periódico sigue corriendo y la sesión sigue
     * "por vencer" (ver App\Console\Commands\NotifyExpiringWhatsAppSessions).
     */
    public function up(): void
    {
        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            $table->timestamp('expiring_soon_notified_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            $table->dropColumn('expiring_soon_notified_at');
        });
    }
};
