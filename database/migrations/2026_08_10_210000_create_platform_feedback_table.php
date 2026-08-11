<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Ayúdanos a mejorar ARKA01" (pedido explícito del usuario, roadmap de
     * mejoras sección 14) — formulario en la página pública, sin necesidad
     * de cuenta (nombre y correo opcionales). Administrable desde
     * /admin/opiniones.
     */
    public function up(): void
    {
        Schema::create('platform_feedback', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            // sugerencia | problema | nueva_idea | otro
            $table->string('type');
            $table->text('comment');
            // nueva | revisando | considerada | implementada | descartada
            $table->string('status')->default('nueva');
            // Observaciones internas del admin — nunca se le muestran a
            // quien mandó la opinión (no hay cuenta a la que devolverle nada).
            $table->text('internal_notes')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_feedback');
    }
};
