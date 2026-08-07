<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fotografías del vehículo para un viaje VAN puntual (pedido explícito
     * del usuario) — separado de `driver_profiles.vehicle_photo_path`
     * porque un conductor de turismo puede publicar viajes con vehículos
     * distintos según la ocasión.
     */
    public function up(): void
    {
        Schema::create('van_trip_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('van_trip_id')->constrained()->cascadeOnDelete();
            $table->string('photo_path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('van_trip_photos');
    }
};
