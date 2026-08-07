<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ubicación en vivo del conductor (sección 9.3): se llena mientras está
        // disponible, vía la geolocalización del navegador. Quedó pendiente desde
        // la Fase 1 a propósito, hasta que hubiera tiempo real para aprovecharla.
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->decimal('current_lat', 10, 7)->nullable()->after('is_available');
            $table->decimal('current_lng', 10, 7)->nullable()->after('current_lat');
            $table->timestamp('location_updated_at')->nullable()->after('current_lng');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn(['current_lat', 'current_lng', 'location_updated_at']);
        });
    }
};
