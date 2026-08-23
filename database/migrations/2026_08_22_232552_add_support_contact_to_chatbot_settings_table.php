<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contacto humano de soporte que el chatbot manda como tarjeta de
     * contacto de WhatsApp de verdad (pedido explícito del usuario: "cuando
     * mande a soporte que mande un contacto, ese contacto que se actualice
     * desde el panel admin") — antes EscalateToSupportHandler solo abría un
     * ticket interno (App\Models\SupportTicket), sin darle a la persona
     * ningún número al que poder escribirle directo mientras espera
     * respuesta. Nullable: si no se completa, el chatbot sigue mandando solo
     * el aviso de texto de siempre, no se rompe nada.
     */
    public function up(): void
    {
        Schema::table('chatbot_settings', function (Blueprint $table) {
            $table->string('support_contact_name')->nullable()->after('fallback_escalation_message');
            $table->string('support_contact_phone')->nullable()->after('support_contact_name');
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_settings', function (Blueprint $table) {
            $table->dropColumn(['support_contact_name', 'support_contact_phone']);
        });
    }
};
