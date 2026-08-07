<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: el módulo de viajes tipo VAN/turismo
     * "puede manejarse como un plan premium exclusivo para conductores" —
     * mismo criterio que `verified_badge`/`public_visibility`, un feature
     * flag más del catálogo de planes (App\Services\PlanLimits), editable
     * desde /admin/planes sin tocar código.
     */
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->boolean('van_trips_enabled')->default(false)->after('verified_badge');
        });

        // Arranca habilitado en los dos planes de conductor más altos —
        // punto de partida editable después desde /admin/planes, no una
        // regla fija en código.
        DB::table('subscription_plans')
            ->where('owner_type', 'driver')
            ->whereIn('code', ['pro', 'institucional'])
            ->update(['van_trips_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('van_trips_enabled');
        });
    }
};
