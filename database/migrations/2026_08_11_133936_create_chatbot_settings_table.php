<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mensajes generales del chatbot editables desde /admin/chatbot
     * (bienvenida, ayuda, fallback, despedida — pedido explícito del
     * usuario). Tabla singleton, mismo patrón ya usado por
     * `whatsapp_settings`/`pricing_settings`: siempre existe una sola fila,
     * sembrada acá mismo. A propósito en una tabla separada de
     * `whatsapp_settings` — el usuario pidió explícitamente no mezclar la
     * configuración transaccional de WhatsApp con la del asistente virtual.
     */
    public function up(): void
    {
        Schema::create('chatbot_settings', function (Blueprint $table) {
            $table->id();
            $table->text('welcome_message');
            $table->text('help_message');
            $table->text('fallback_message');
            // Se manda cuando ya se agotaron los intentos de
            // `max_fallback_attempts` sin resolver (pedido explícito:
            // "parece que necesitas una atención más específica").
            $table->text('fallback_escalation_message');
            $table->text('farewell_message');
            $table->unsignedTinyInteger('max_fallback_attempts')->default(2);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('chatbot_settings')->insert([
            'welcome_message' => "¡Hola! 👋 Soy el asistente virtual de Arka01. Puedo ayudarte con tu cuenta, acceso, registro, códigos de verificación y responder preguntas sobre nuestros servicios.\n\n¿Qué necesitas?",
            'help_message' => 'Puedo ayudarte con: crear una cuenta, problemas para ingresar, códigos de verificación, información sobre ser conductor o cliente, suscripciones y preguntas frecuentes. Escribime lo que necesitás o elegí una opción.',
            'fallback_message' => 'Quiero ayudarte, pero no logré identificar exactamente lo que necesitás. Podés explicármelo de otra manera o elegir una opción.',
            'fallback_escalation_message' => 'Parece que necesitás una atención más específica. Puedo ayudarte a contactar con soporte.',
            'farewell_message' => '¡Gracias por escribirnos! Si necesitás algo más, escribime cuando quieras. 👋',
            'max_fallback_attempts' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_settings');
    }
};
