<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: un Expreso puede ser de ida y vuelta —
     * además de la hora de salida (`departure_time`, ya existente, hora en
     * que sale DEL origen), hace falta otra hora para la vuelta (hora en que
     * sale DEL destino). `GenerateExpressRides` genera una segunda carrera
     * ese horario, con origen y destino invertidos.
     */
    public function up(): void
    {
        Schema::table('express_routes', function (Blueprint $table) {
            $table->boolean('is_round_trip')->default(false)->after('departure_time');
            $table->time('return_time')->nullable()->after('is_round_trip');
        });
    }

    public function down(): void
    {
        Schema::table('express_routes', function (Blueprint $table) {
            $table->dropColumn(['is_round_trip', 'return_time']);
        });
    }
};
