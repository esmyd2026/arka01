<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario: además de marca/modelo/color, que el
 * conductor declare el tipo de carrocería (sedán, SUV, etc.) — es el único
 * dato del vehículo que sí se muestra tal cual al cliente en las pantallas
 * públicas, ahora que la foto y la placa completa quedan restringidas
 * (ver App\Models\DriverProfile::vehicleTypes()/maskedPlate()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('vehicle_type')->nullable()->after('vehicle_color');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn('vehicle_type');
        });
    }
};
