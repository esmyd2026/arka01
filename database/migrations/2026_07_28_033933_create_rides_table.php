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
        // rides: la carrera ya confirmada (alguien aceptó la ride_request). Guarda
        // su propia "foto" de origen/destino/distancia/tarifa (sección 9.6) para que
        // un cambio posterior en la tarifa del conductor no altere carreras pasadas.
        Schema::create('rides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fleet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('driver_user_id')->constrained('users')->cascadeOnDelete();

            $table->decimal('origin_lat', 10, 7);
            $table->decimal('origin_lng', 10, 7);
            $table->string('origin_address')->nullable();
            $table->decimal('destination_lat', 10, 7);
            $table->decimal('destination_lng', 10, 7);
            $table->string('destination_address')->nullable();
            $table->decimal('distance_km', 8, 2);

            // Precio sugerido = distancia × tarifa del conductor × factor horario
            // (sección 5), calculado una sola vez al aceptar y guardado acá para
            // que quede fijo aunque el conductor cambie su tarifa después.
            $table->decimal('rate_per_km_snapshot', 8, 2);
            $table->decimal('price', 8, 2);

            $table->enum('status', ['in_progress', 'completed', 'cancelled'])->default('in_progress');

            // Pago manual entre cliente y conductor (sección 5): ambos marcan
            // "pagada" para cerrar el ciclo, la plataforma no cobra comisión.
            $table->boolean('client_marked_paid')->default(false);
            $table->boolean('driver_marked_paid')->default(false);
            $table->timestamp('paid_at')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['client_user_id', 'status']);
            $table->index(['driver_user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rides');
    }
};
