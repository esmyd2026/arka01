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
        // Tarifa base mínima de toda la plataforma (pedido explícito del
        // usuario: pidió una carrera de 2 km y salió $1 — muy poco para que
        // le convenga al conductor). Si distancia × tarifa del conductor da
        // menos que esto, se cobra este mínimo en su lugar — ver
        // App\Services\PriceCalculator::suggestedPrice().
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->decimal('minimum_fare', 8, 2)->default(2.00)->after('night_surcharge_percent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->dropColumn('minimum_fare');
        });
    }
};
