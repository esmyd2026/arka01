<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: el conductor puede apagar el cargo por
     * distancia de recogida desde su propio perfil, igual que ya configura
     * su tarifa por km o su tarifa mínima — con esto en `false`, la función
     * no existe para él: no se le calcula ni se le muestra en ninguna
     * solicitud (ver App\Services\PriceCalculator::pickupSurchargeForDriver()).
     * Empieza en `true` para no cambiarle el comportamiento a nadie que ya
     * viniera usando el check por carrera introducido antes de este toggle.
     */
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->boolean('pickup_surcharge_enabled')->default(true)->after('max_request_distance_km');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn('pickup_surcharge_enabled');
        });
    }
};
