<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: "puede limitar los recorridos osea que
     * las personas no puedan ver conductores a mas de 50 km... para evitar
     * solicitudes asi tan extensas. y menos de otros paises" — tope GLOBAL
     * (no por país, mismo criterio que driver_stale_after_minutes) de
     * distancia entre el origen de una carrera y el conductor. El conductor
     * sigue pudiendo declarar un radio propio más angosto
     * (driver_profiles.max_request_distance_km), pero nunca puede superar
     * este techo de la plataforma — ver DriverProfile::isWithinRangeOf().
     * 50 km de fábrica: valor que el usuario pidió como referencia ("más o
     * menos").
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->unsignedInteger('max_ride_request_distance_km')->default(50)->after('driver_stale_after_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('max_ride_request_distance_km');
        });
    }
};
