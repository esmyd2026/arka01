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
        // Pedido explícito del usuario: "subir un poco las tarifas en las
        // horas pico" — mismo criterio que el recargo nocturno ya existente
        // (ver App\Services\PriceCalculator), pero con DOS franjas por día
        // (mañana y tarde, las horas pico reales de una ciudad) en vez de
        // una sola. Nunca se suma con el recargo nocturno — una carrera es
        // nocturna O pico, no las dos (ver PriceCalculator::suggestedPrice()).
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->unsignedInteger('peak_surcharge_percent')->default(15)->after('night_ends_at');
            $table->unsignedTinyInteger('peak_morning_starts_at')->default(7)->after('peak_surcharge_percent');
            $table->unsignedTinyInteger('peak_morning_ends_at')->default(9)->after('peak_morning_starts_at');
            $table->unsignedTinyInteger('peak_evening_starts_at')->default(17)->after('peak_morning_ends_at');
            $table->unsignedTinyInteger('peak_evening_ends_at')->default(19)->after('peak_evening_starts_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->dropColumn([
                'peak_surcharge_percent',
                'peak_morning_starts_at',
                'peak_morning_ends_at',
                'peak_evening_starts_at',
                'peak_evening_ends_at',
            ]);
        });
    }
};
