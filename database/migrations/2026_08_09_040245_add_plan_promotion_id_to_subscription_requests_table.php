<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Con qué promoción (si hubo alguna) se pidió este plan — necesario para
     * que PlanPromotion::isEligibleFor() tenga algo que consultar y así
     * pueda ocultarse sola a quien ya la usó. `nullOnDelete()`, no cascada:
     * borrar una promoción vieja no debería borrar el historial de pedidos
     * ya resueltos, solo desvincularlos.
     */
    public function up(): void
    {
        Schema::table('subscription_requests', function (Blueprint $table) {
            $table->foreignId('plan_promotion_id')->nullable()->after('subscription_plan_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_promotion_id');
        });
    }
};
