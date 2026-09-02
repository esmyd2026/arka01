<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario: la trazabilidad de la cooperativa necesita
 * mostrar "el cobro al cliente por km, según lo configurado para esa
 * carrera" — hasta ahora `rides.rate_per_km_snapshot` se llenaba con la
 * tarifa INDIVIDUAL del conductor al momento de aceptar
 * (RideRequestResponder::accept()), nunca con la tarifa real usada para
 * cotizar (la propia de la cooperativa cuando la solicitud es suya, ver
 * RideRequestCreator::create()) — quedaba mal para toda carrera despachada
 * por una cooperativa. Esta columna guarda la tarifa real en el momento de
 * la cotización, para que accept() ya no tenga que adivinarla de nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->decimal('rate_per_km', 8, 2)->nullable()->after('current_offered_price');
        });
    }

    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropColumn('rate_per_km');
        });
    }
};
