<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paradas intermedias de una solicitud de carrera (pedido explícito del
     * usuario: "agregar una parada adicional... hasta 4 paradas", cada una
     * cobrada por separado). Se copian a `ride_stops` cuando un conductor
     * acepta la solicitud (ver RideRequestController::accept()) — esta
     * tabla vive solo mientras la solicitud sigue pendiente/negociando.
     */
    public function up(): void
    {
        Schema::create('ride_request_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('address')->nullable();
            $table->foreignId('sector_id')->nullable()->constrained('sectors')->nullOnDelete();
            // Distancia/precio del tramo que TERMINA en esta parada (desde el
            // punto anterior: origen o la parada previa) — mismo criterio que
            // PriceCalculator::suggestedPrice() aplicado por tramo, no al
            // recorrido completo.
            $table->decimal('leg_distance_km', 8, 2);
            $table->decimal('leg_price', 8, 2);
            $table->timestamps();

            $table->unique(['ride_request_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_request_stops');
    }
};
