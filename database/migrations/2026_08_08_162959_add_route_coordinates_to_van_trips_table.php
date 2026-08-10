<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: además de la ciudad de origen/destino
     * (lo único que había hasta ahora, ver 2026_08_06_115529), poder marcar
     * el punto exacto de salida y llegada — mismo criterio que un Expreso
     * (ver express_routes) — para calcular la distancia real y sugerir un
     * costo aproximado antes de que el conductor ponga su precio. Todo
     * nullable: un viaje puede seguir publicándose solo con la ciudad, como
     * hasta ahora.
     */
    public function up(): void
    {
        Schema::table('van_trips', function (Blueprint $table) {
            $table->decimal('origin_lat', 10, 7)->nullable()->after('origin_city_id');
            $table->decimal('origin_lng', 10, 7)->nullable()->after('origin_lat');
            $table->string('origin_address')->nullable()->after('origin_lng');
            $table->decimal('destination_lat', 10, 7)->nullable()->after('destination_city_id');
            $table->decimal('destination_lng', 10, 7)->nullable()->after('destination_lat');
            $table->string('destination_address')->nullable()->after('destination_lng');
            $table->decimal('distance_km', 8, 2)->nullable()->after('destination_address');
        });
    }

    public function down(): void
    {
        Schema::table('van_trips', function (Blueprint $table) {
            $table->dropColumn([
                'origin_lat', 'origin_lng', 'origin_address',
                'destination_lat', 'destination_lng', 'destination_address',
                'distance_km',
            ]);
        });
    }
};
