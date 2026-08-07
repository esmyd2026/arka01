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
        // fleet_members: quién pertenece (o perteneció) a cada flota. Nunca se borra una
        // fila al salir un conductor: se cierra con "left_at" para conservar el historial
        // completo de la relación cliente-conductor (trazabilidad, sección 9.6).
        Schema::create('fleet_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_user_id')->constrained('users')->cascadeOnDelete();

            // Quién originó el alta (el cliente al aceptar el conductor su invitación).
            $table->foreignId('added_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('joined_at');

            // Si es null, la membresía sigue activa. Se completa cuando el conductor se
            // sale por su cuenta o el cliente lo da de baja (sección 3.2 y 3.3).
            $table->timestamp('left_at')->nullable();
            $table->string('left_reason')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Para calcular rápido "cuántos conductores activos tiene esta flota" y
            // "a cuántas flotas pertenece activamente este conductor" (sección 9.6: cupos).
            $table->index(['fleet_id', 'left_at']);
            $table->index(['driver_user_id', 'left_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fleet_members');
    }
};
