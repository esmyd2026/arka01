<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Con qué cupón (si hubo alguno) se pidió este plan — mismo criterio que
     * `plan_promotion_id`: necesario para que PlanCoupon::reasonNotUsableFor()
     * pueda saber si este usuario ya usó este cupón antes. `nullOnDelete()`,
     * no cascada: borrar un cupón viejo no debería borrar el historial de
     * pedidos ya resueltos, solo desvincularlos.
     */
    public function up(): void
    {
        Schema::table('subscription_requests', function (Blueprint $table) {
            $table->foreignId('plan_coupon_id')->nullable()->after('plan_promotion_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_coupon_id');
        });
    }
};
