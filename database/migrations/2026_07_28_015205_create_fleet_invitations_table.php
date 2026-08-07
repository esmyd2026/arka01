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
        // fleet_invitations: registra cada invitación que un cliente le manda a un
        // conductor. Nadie queda en una flota sin invitación aceptada (sección 3.2).
        Schema::create('fleet_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();

            $table->enum('status', ['pending', 'accepted', 'rejected', 'cancelled'])->default('pending');
            $table->string('message')->nullable();
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            // Un mismo conductor no puede tener dos invitaciones pendientes a la misma
            // flota al mismo tiempo (evita spamear invitaciones repetidas).
            $table->index(['fleet_id', 'driver_user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fleet_invitations');
    }
};
