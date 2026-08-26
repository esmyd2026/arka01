<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transcripción completa de WhatsApp (pedido explícito del usuario: "ver
     * cada conversación con el bot"). Hasta ahora no existía ninguna tabla
     * así — `chatbot_conversations` solo guarda el ESTADO actual (en qué
     * paso va), no el historial; `chatbot_unrecognized_messages` solo guarda
     * lo que el bot no entendió. Acá se registra TODO lo que entra y sale
     * por WhatsApp: entrante desde WhatsAppWebhookController::receive(),
     * saliente desde los métodos primitivos de WhatsAppFreeformSender (los
     * más específicos, como sendNewRideAlert(), ya llaman a esos por
     * dentro, así que un solo enganche cubre todo).
     */
    public function up(): void
    {
        Schema::create('chatbot_messages', function (Blueprint $table) {
            $table->id();
            $table->string('phone');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('direction', ['in', 'out']);
            $table->text('body');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['phone', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_messages');
    }
};
