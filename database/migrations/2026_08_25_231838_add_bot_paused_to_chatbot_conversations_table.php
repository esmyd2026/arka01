<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario ("poder responder desde allí yo también
     * o activar el bot o no") — control manual, por conversación, para que
     * un admin tome el número a mano sin que el bot le siga contestando por
     * encima. Independiente del pausado automático por ticket de soporte
     * (SupportTicket en_atencion/esperando_usuario, ver
     * ChatbotEngine::humanIsHandling()) — ese es automático y ligado a un
     * ticket; este es manual y no necesita ninguno.
     */
    public function up(): void
    {
        Schema::table('chatbot_conversations', function (Blueprint $table) {
            $table->boolean('bot_paused')->default(false)->after('unresolved_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_conversations', function (Blueprint $table) {
            $table->dropColumn('bot_paused');
        });
    }
};
