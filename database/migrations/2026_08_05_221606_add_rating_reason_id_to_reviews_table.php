<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Motivo obligatorio (pedido explícito del usuario) cuando la
     * calificación baja de las 5 estrellas por defecto — null cuando se deja
     * en 5, que es el único caso en el que no hace falta explicar nada.
     */
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('rating_reason_id')->nullable()->after('rating')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rating_reason_id');
        });
    }
};
