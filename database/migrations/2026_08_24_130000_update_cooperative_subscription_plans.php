<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Precios reales de los planes de cooperativa (pedido explícito del
 * usuario, tras evaluar costos): la cooperativa paga por el panel de
 * gestión, no por el transporte — el conductor afiliado sigue pagando su
 * propio plan aparte, con un descuento cruzado según el plan de su
 * cooperativa (ver `driver_discount_percent`, columna nueva de esta
 * migración).
 *
 * Se agrega un `gratis` real (5 unidades) — antes `basico` hacía de plan
 * base para `owner_type=cooperative` (ver PlanLimits::freePlan(), corregido
 * junto con esta migración). El tramo "sin límite" (`empresarial`) se
 * discontinúa: no se borra la fila (evita romper historial de
 * Subscription/SubscriptionChange si alguna ya la referencia), solo se
 * desactiva — mismo criterio que cualquier plan descontinuado en el resto
 * del código.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            // Solo tiene sentido para owner_type=cooperative; queda en 0
            // (sin efecto) para planes de conductor/cliente.
            $table->unsignedTinyInteger('driver_discount_percent')->default(0)->after('monthly_price');
        });

        foreach ([
            ['code' => 'gratis', 'name' => 'Gratis', 'monthly_price' => 0, 'max_units' => 5, 'driver_discount_percent' => 0, 'sort_order' => 1],
            ['code' => 'basico', 'name' => 'Básico', 'monthly_price' => 75, 'max_units' => 10, 'driver_discount_percent' => 10, 'sort_order' => 2],
            ['code' => 'profesional', 'name' => 'Profesional', 'monthly_price' => 250, 'max_units' => 50, 'driver_discount_percent' => 20, 'sort_order' => 3],
        ] as $plan) {
            DB::table('subscription_plans')->updateOrInsert(
                ['owner_type' => 'cooperative', 'code' => $plan['code']],
                array_merge($plan, ['owner_type' => 'cooperative', 'is_active' => true, 'updated_at' => now(), 'created_at' => now()])
            );
        }

        DB::table('subscription_plans')
            ->where('owner_type', 'cooperative')
            ->where('code', 'empresarial')
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('driver_discount_percent');
        });

        DB::table('subscription_plans')
            ->where('owner_type', 'cooperative')
            ->where('code', 'empresarial')
            ->update(['is_active' => true]);
    }
};
