<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Consultas no reconocidas" (pedido explícito del usuario, sección 15):
     * cada mensaje que el motor no logró clasificar con suficiente
     * confianza queda acá para que un admin lo revise y, si hace falta, lo
     * convierta en una intención/vocablo/FAQ nueva — ver /admin/chatbot.
     */
    public function up(): void
    {
        Schema::create('chatbot_unrecognized_messages', function (Blueprint $table) {
            $table->id();
            $table->string('phone');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Snapshot del rol al momento del mensaje (cliente/conductor/
            // null si era un número sin cuenta) — no se recalcula después.
            $table->string('role')->nullable();
            $table->text('message');
            $table->string('best_guess_intent_code')->nullable();
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_unrecognized_messages');
    }
};
