<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: "colocarle un usuario para que cuando
     * se registren tenga a ese usuario que le dio el cupon como referido" —
     * un cupón puede tener dueño (quién lo reparte), y quien lo canjea
     * queda atribuido a ese usuario como referido (ver
     * SubscriptionRequestController::store()). Opcional: un cupón de
     * campaña general (ej. "BIENVENIDA50") no tiene por qué tener a
     * nadie detrás.
     */
    public function up(): void
    {
        Schema::table('plan_coupons', function (Blueprint $table) {
            $table->foreignId('referrer_user_id')->nullable()->after('created_by_admin_id')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plan_coupons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referrer_user_id');
        });
    }
};
