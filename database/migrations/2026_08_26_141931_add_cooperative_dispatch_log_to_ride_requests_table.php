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
        Schema::table('ride_requests', function (Blueprint $table) {
            // Pedido explícito del usuario ("le dice a la de la cooperativa
            // a quien se la asignaron y la cancelo?"): antes, cuando el
            // conductor asignado no respondía a tiempo y el motor pasaba al
            // siguiente candidato (App\Services\RideDispatchAdvancer), el
            // panel de la cooperativa no dejaba ningún rastro de a quién se
            // le había ofrecido antes ni por qué se reintentó. Se guarda el
            // nombre en el momento (no solo el id) para que el historial siga
            // siendo legible aunque ese conductor deje la cooperativa después.
            $table->json('cooperative_dispatch_log')->nullable()->after('cooperative_offer_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropColumn('cooperative_dispatch_log');
        });
    }
};
