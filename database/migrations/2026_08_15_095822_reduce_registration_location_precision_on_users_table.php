<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auditoría de seguridad: `registration_lat/lng` guardaban 7 decimales
     * (~1 metro de precisión — casi la puerta de calle exacta de dónde vive
     * alguien). Este dato solo se usa como respaldo de "conductores cerca"
     * cuando no hay geolocalización en vivo (ver
     * DashboardController::nearbyDriversFor()), un cálculo a nivel de
     * ciudad/barrio, nunca de navegación turn-by-turn — 4 decimales
     * (~11 metros) alcanza de sobra para eso y deja de poder ubicar la
     * vivienda exacta de nadie a partir de un volcado de la base.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('registration_lat', 7, 4)->nullable()->change();
            $table->decimal('registration_lng', 7, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('registration_lat', 10, 7)->nullable()->change();
            $table->decimal('registration_lng', 10, 7)->nullable()->change();
        });
    }
};
