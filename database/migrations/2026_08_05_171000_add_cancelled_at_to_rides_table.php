<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cancelar una carrera ya aceptada (pedido explícito del usuario): el estado
 * 'cancelled' ya existía en el enum de `rides` desde el principio pero nunca
 * se usaba — no había ningún botón ni ruta que lo pusiera. `cancelled_at`
 * (mismo criterio que `completed_at`/`paid_at`) sirve tanto para mostrarlo
 * como para medir cuántas se cancelan (pedido explícito del usuario: "que
 * eso también cuente para medir") sin necesitar una columna aparte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');
        });
    }
};
