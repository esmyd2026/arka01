<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: la cooperativa cobra su PROPIA tarifa al
     * cliente (`rate_per_km`) y define cuánto le paga a sus conductores
     * (`driver_pay_rate_per_km`) — la diferencia es su margen. Antes, el
     * precio de una carrera de cooperativa era el promedio de las tarifas
     * individuales de los conductores miembros (ver
     * App\Services\Ride\RideRequestCreator::create()), sin ninguna
     * separación entre lo que gana el conductor y lo que gana la
     * cooperativa. Ambas nullable a propósito: mientras una cooperativa no
     * las configure, todo sigue funcionando exactamente como hoy (promedio
     * de conductores, sin billetera — ver App\Models\CooperativeWalletEntry).
     *
     * `max_request_distance_km`: mismo concepto que
     * DriverProfile.max_request_distance_km (migración
     * 2026_08_03_112139_add_max_request_distance_km_to_driver_profiles_table.php),
     * pero medido desde el "stand" de la cooperativa (`stand_lat/stand_lng`,
     * ya existente) en vez de la ubicación de un conductor puntual.
     */
    public function up(): void
    {
        Schema::table('cooperatives', function (Blueprint $table) {
            $table->decimal('rate_per_km', 8, 2)->nullable()->after('geographic_coverage');
            $table->decimal('driver_pay_rate_per_km', 8, 2)->nullable()->after('rate_per_km');
            $table->unsignedSmallInteger('max_request_distance_km')->nullable()->after('driver_pay_rate_per_km');
        });
    }

    public function down(): void
    {
        Schema::table('cooperatives', function (Blueprint $table) {
            $table->dropColumn(['rate_per_km', 'driver_pay_rate_per_km', 'max_request_distance_km']);
        });
    }
};
