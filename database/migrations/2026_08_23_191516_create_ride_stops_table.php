<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paradas intermedias de una carrera YA aceptada (copiadas desde
     * `ride_request_stops` en RideRequestController::accept()). Cada parada
     * se completa o cancela por separado — pedido explícito del usuario:
     * "si no llegan a una parada puedan pagarle cada parada y cancelar la
     * otra o iniciar la siguiente parada" (ver RideController::completeStop()).
     * Sin `arrived_at` propio a propósito: a diferencia del origen (que sí
     * tiene el vaivén arrived_at→picked_up_at con su cortesía de 5 minutos),
     * una parada intermedia es un solo toque del conductor.
     */
    public function up(): void
    {
        Schema::create('ride_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('address')->nullable();
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->nullOnDelete();
            $table->decimal('leg_distance_km', 8, 2);
            $table->decimal('leg_price', 8, 2);
            $table->string('status')->default('pending'); // pending|completed|cancelled
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['ride_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_stops');
    }
};
