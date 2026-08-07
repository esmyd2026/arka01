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
        // ride_requests: el pedido de carrera antes de que alguien lo acepte
        // (sección 3.5). driver_user_id nulo = se mandó a "toda la flota
        // disponible"; el primero que acepta se queda con la carrera.
        Schema::create('ride_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('driver_user_id')->nullable()->constrained('users')->cascadeOnDelete();

            $table->decimal('origin_lat', 10, 7);
            $table->decimal('origin_lng', 10, 7);
            $table->string('origin_address')->nullable();
            $table->decimal('destination_lat', 10, 7);
            $table->decimal('destination_lng', 10, 7);
            $table->string('destination_address')->nullable();

            // Calculada una sola vez con Haversine al crear la solicitud
            // (sección 9.3), no se recalcula después.
            $table->decimal('distance_km', 8, 2);

            $table->enum('status', ['pending', 'accepted', 'cancelled', 'expired'])->default('pending');
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('requested_at');
            $table->timestamp('responded_at')->nullable();

            $table->timestamps();

            $table->index(['fleet_id', 'status']);
            $table->index(['driver_user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ride_requests');
    }
};
