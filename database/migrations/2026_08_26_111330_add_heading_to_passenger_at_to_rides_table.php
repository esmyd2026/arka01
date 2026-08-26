<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bug real reportado por el usuario, con captura: "ya estaba en camino
     * por el pasajero y ya a ese botón ya le había dado, y cuando entro a
     * la aplicación decía nuevamente ir por el pasajero" — el toque de "Ir
     * por el pasajero" solo vivía en un ref local de Vue (a propósito, ver
     * el comentario de goToPassenger() en Ride/Show.vue: no podía tocar
     * `arrived_at`, eso dispara el conteo de cortesía de 5 minutos). Sin
     * nada guardado en el servidor, recargar la página o volver a entrar
     * perdía ese estado y el botón volvía a "Ir por el pasajero" aunque el
     * conductor ya hubiera salido. Esta columna guarda ESE momento aparte,
     * sin tocar `arrived_at` ni el conteo de cortesía.
     */
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->timestamp('heading_to_passenger_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('heading_to_passenger_at');
        });
    }
};
