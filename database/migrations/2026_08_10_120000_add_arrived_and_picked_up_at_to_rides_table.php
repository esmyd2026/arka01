<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Pedido explícito del usuario: dos hitos nuevos dentro de una carrera
        // ya 'in_progress' — cuándo el conductor llegó al punto de encuentro
        // (arrived_at) y cuándo recogió al cliente de verdad (picked_up_at).
        // Nullable los dos: ninguna carrera anterior a este cambio los tiene,
        // y una carrera puede completarse sin que el conductor los haya
        // marcado (son hitos informativos, no obligatorios para completar).
        Schema::table('rides', function (Blueprint $table) {
            $table->timestamp('arrived_at')->nullable()->after('started_at');
            $table->timestamp('picked_up_at')->nullable()->after('arrived_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['arrived_at', 'picked_up_at']);
        });
    }
};
