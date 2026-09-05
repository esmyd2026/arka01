<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario: tutoriales guiados con Driver.js, anclados a
 * elementos reales de la pantalla (a diferencia de OnboardingTour.vue, que
 * es solo texto informativo) — uno para pedir una carrera (destino, forma de
 * pago, paradas, invertir origen/destino) y otro para agregar conductores a
 * la flota. Mismo criterio que `onboarding_completed_at`: `null` = no lo vio
 * todavía, timestamp = cuándo lo cerró. Van separados de esa columna porque
 * son recorridos independientes, en pantallas distintas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('ride_request_tour_seen_at')->nullable()->after('onboarding_completed_at');
            $table->timestamp('fleet_tour_seen_at')->nullable()->after('ride_request_tour_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ride_request_tour_seen_at', 'fleet_tour_seen_at']);
        });
    }
};
