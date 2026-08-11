<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Hablar con soporte" (pedido explícito del usuario, roadmap de mejoras
     * sección 12): un hilo por usuario a la vez (mientras no esté cerrado,
     * "Hablar con soporte" retoma el mismo en vez de abrir uno nuevo cada
     * vez) — ver App\Models\SupportTicket::openOrCreateFor().
     */
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // nuevo | en_atencion | esperando_usuario | resuelto | cerrado
            $table->string('status')->default('nuevo');
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
