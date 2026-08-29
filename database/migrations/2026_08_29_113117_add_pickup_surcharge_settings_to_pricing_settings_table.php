<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: cobrar aparte el trayecto que el
     * conductor recorre para ir a buscar al cliente, pero solo cuando es
     * "largo" — bajo este umbral se sigue usando el colchón fijo de 0.8 km
     * que ya existe (ver App\Services\PriceCalculator::DISTANCE_PADDING_KM),
     * sin cargo aparte. `pickup_surcharge_percent` es el % que se aplica
     * sobre (distancia_recogida × tarifa_del_conductor) cuando se supera el
     * umbral — ejemplo del usuario: 8 km a $0.30/km × 55% = $1.32.
     */
    public function up(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->decimal('pickup_surcharge_threshold_km', 5, 2)->default(3.00)->after('peak_evening_ends_at');
            $table->unsignedTinyInteger('pickup_surcharge_percent')->default(55)->after('pickup_surcharge_threshold_km');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->dropColumn(['pickup_surcharge_threshold_km', 'pickup_surcharge_percent']);
        });
    }
};
