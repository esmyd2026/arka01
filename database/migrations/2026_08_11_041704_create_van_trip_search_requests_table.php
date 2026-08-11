<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: cuando un cliente busca una ruta en
     * "Rutas y Turismo" y no hay ningún viaje abierto que la cubra, se
     * guarda acá para poder mostrarles esa demanda a los conductores que
     * publican viajes — no existía ningún registro de búsquedas sin
     * resultado hasta ahora (ver VanTripController::browse()).
     */
    public function up(): void
    {
        Schema::create('van_trip_search_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('origin_city_id')->constrained('cities');
            $table->foreignId('destination_city_id')->constrained('cities');
            // Nullable: el cliente puede haber buscado solo la ruta sin fijar
            // fecha — igual es información útil para el conductor.
            $table->date('travel_date')->nullable();
            $table->timestamps();

            $table->index(['origin_city_id', 'destination_city_id'], 'van_trip_search_requests_route_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('van_trip_search_requests');
    }
};
