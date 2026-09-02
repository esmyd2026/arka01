<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario: por defecto un conductor solo puede estar
 * afiliado a UNA cooperativa activa a la vez (ver
 * CooperativeDriverResponder::respond()). Este flag, configurable por plan
 * desde /admin/planes (igual que public_visibility, van_trips_enabled,
 * etc.), le permite al admin habilitar para un plan puntual que sus
 * conductores puedan aceptar solicitudes de MÁS de una cooperativa al
 * mismo tiempo. Arranca en false para todos los planes existentes — nadie
 * gana esta capacidad sin que el admin la prenda a propósito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->boolean('multi_cooperative_enabled')->default(false)->after('express_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('multi_cooperative_enabled');
        });
    }
};
