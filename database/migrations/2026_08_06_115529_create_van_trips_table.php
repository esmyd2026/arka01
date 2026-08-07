<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nuevo servicio para conductores tipo VAN/buseta/microbús/turístico
     * (pedido explícito del usuario): viajes programados puntuales (no
     * recurrentes como un Expreso) que un cliente explora y reserva por
     * asiento — ver App\Models\VanTripReservation.
     */
    public function up(): void
    {
        Schema::create('van_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('origin_city_id')->constrained('cities');
            $table->foreignId('destination_city_id')->constrained('cities');
            $table->date('travel_date');
            $table->time('departure_time');
            $table->unsignedTinyInteger('total_seats');
            $table->decimal('price_per_seat', 8, 2);
            $table->text('description')->nullable();
            // Lista libre ("Aire acondicionado", "WiFi", "Agua"), sin
            // catálogo fijo — el conductor la escribe al publicar.
            $table->json('included_services')->nullable();
            $table->string('luggage_allowance')->nullable();
            $table->string('status')->default('open'); // open | cancelled
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('van_trips');
    }
};
