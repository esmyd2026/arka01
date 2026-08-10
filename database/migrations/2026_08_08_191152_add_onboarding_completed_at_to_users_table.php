<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: un recorrido guiado (distinto para
     * cliente y para conductor) que se muestra una sola vez — `null` mientras
     * no lo vio, timestamp de cuándo lo cerró (mismo criterio que
     * `phone_verified_at`/`locked_at`, no un booleano suelto).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('onboarding_completed_at')->nullable()->after('locked_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_completed_at');
        });
    }
};
