<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suma de `leg_price` de las paradas de la solicitud (0/null si no tiene
     * ninguna) — el precio total que ve el cliente es
     * `stops_price + current_offered_price` (este último sigue siendo,
     * exactamente como hoy, el precio del tramo final).
     */
    public function up(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->decimal('stops_price', 8, 2)->nullable()->after('current_offered_price');
        });
    }

    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropColumn('stops_price');
        });
    }
};
