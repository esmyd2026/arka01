<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Proyección de carreras mensuales por plan (pedido explícito del
        // usuario): referencia informativa para que el conductor vea, al
        // elegir un plan, cuánto podría llegar a generar en carreras y a
        // cuánto se traduce eso en dólares (ver
        // MyPlanController::attachEarningsProjection() y el ticket promedio
        // en pricing_settings). Solo tiene sentido para planes de
        // conductor — nullable porque los de cliente no lo usan.
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->unsignedInteger('estimated_monthly_rides')->nullable()->after('max_clients');
        });

        // Valores de referencia iniciales, coherentes con la comparación que
        // hizo el usuario contra Uber (400 carreras/mes ≈ techo de
        // Institucional) y con su propio ejemplo para Básico (150
        // carreras/mes). Quedan editables desde /admin/planes sin volver a
        // tocar código.
        $defaults = [
            'gratis' => 60,
            'basico' => 150,
            'plus' => 220,
            'pro' => 300,
            'institucional' => 400,
        ];

        foreach ($defaults as $code => $rides) {
            DB::table('subscription_plans')
                ->where('owner_type', 'driver')
                ->where('code', $code)
                ->update(['estimated_monthly_rides' => $rides]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('estimated_monthly_rides');
        });
    }
};
