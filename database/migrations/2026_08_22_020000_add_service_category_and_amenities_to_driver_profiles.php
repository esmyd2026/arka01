<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            // Las comodidades las declara el conductor; la categoría de
            // servicio la asigna exclusivamente administración después de
            // revisar el vehículo y sus documentos.
            $table->json('vehicle_amenities')->nullable()->after('has_trunk');
            $table->string('service_category', 30)->nullable()->after('vehicle_amenities');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn(['vehicle_amenities', 'service_category']);
        });
    }
};
