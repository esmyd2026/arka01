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
        // Chat temporal cliente↔conductor (pedido explícito del usuario,
        // roadmap de mejoras): SOLO existe mientras hay una carrera
        // programada o en curso entre esas dos personas puntuales — nunca un
        // chat permanente ni entre desconocidos. Sin tabla de "conversación"
        // aparte: la carrera misma ES la conversación (un ride_id = un hilo),
        // se cierra sola cuando la carrera se completa o cancela (ver
        // RideMessageController::store()), no hace falta un estado propio acá.
        Schema::create('ride_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('body', 500);
            $table->timestamps();

            $table->index(['ride_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ride_messages');
    }
};
