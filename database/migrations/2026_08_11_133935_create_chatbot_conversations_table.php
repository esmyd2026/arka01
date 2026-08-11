<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estado de conversación del chatbot (pedido explícito del usuario: un
     * asistente virtual real sobre WhatsApp, no solo mensajes fijos por
     * evento) — a propósito UNA fila por número de teléfono, no por usuario:
     * el chatbot también debe atender a prospectos que todavía no tienen
     * cuenta ("quiero ser conductor" antes de registrarse). Separada por
     * completo de `whatsapp_sessions` (esa sigue siendo solo el bookkeeping
     * de la ventana de 24h para conductores registrados) — mezclarlas
     * hubiera sido justo lo que el usuario pidió NO hacer.
     */
    public function up(): void
    {
        Schema::create('chatbot_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Qué pregunta de seguimiento hizo el bot (ej. "a qué te referís
            // con el código") — le da prioridad al contexto sobre el
            // contenido aislado del próximo mensaje (pedido explícito).
            $table->string('pending_intent')->nullable();
            $table->json('context')->nullable();
            // Cuenta intentos seguidos sin resolver (pedido explícito):
            // pasado el máximo configurado, se ofrece hablar con soporte en
            // vez de seguir repitiendo el mismo fallback.
            $table->unsignedTinyInteger('unresolved_attempts')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_conversations');
    }
};
