<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: poder habilitar o no el módulo de
     * Expresos por plan de conductor, mismo criterio que `van_trips_enabled`
     * (App\Services\PlanLimits) — editable desde /admin/planes sin tocar código.
     *
     * A diferencia de VAN (una función nueva que nunca estuvo disponible
     * para nadie), Expresos ya es una función abierta desde la Fase 6 —
     * arranca en `true` para TODOS los planes existentes, para no sacarle de
     * golpe algo que un conductor ya podía usar. El admin decide después,
     * desde /admin/planes, a qué planes se lo saca.
     */
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->boolean('express_enabled')->default(true)->after('van_trips_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('express_enabled');
        });
    }
};
