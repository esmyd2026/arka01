<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un cliente reserva uno o más asientos de un viaje VAN publicado
     * (pedido explícito del usuario) — reserva directa (no hay negociación
     * de precio, ya viene fijado por asiento), validada contra el cupo
     * disponible en el momento de reservar.
     */
    public function up(): void
    {
        Schema::create('van_trip_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('van_trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('seats_reserved');
            $table->string('status')->default('confirmed'); // confirmed | cancelled
            $table->timestamp('reserved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('van_trip_reservations');
    }
};
