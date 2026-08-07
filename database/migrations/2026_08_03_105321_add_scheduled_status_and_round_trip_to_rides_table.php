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
        // Una carrera aceptada a partir de una solicitud PROGRAMADA (sección
        // agregada al alcance: "ahora mismo" vs "programación") no puede
        // arrancar como 'in_progress' de una: el conductor quedaría mostrado
        // "ocupado" en toda la app desde el momento en que acepta hasta la
        // hora programada, que puede ser horas o días después. Por eso se
        // suma el estado 'scheduled' — cuenta como "aceptada, todavía no
        // arrancó", y el conductor sigue disponible para otras carreras
        // mientras tanto (todos los cálculos de "ocupado" ya filtran
        // exactamente por status = 'in_progress', así que 'scheduled' queda
        // afuera automáticamente sin tocar esas consultas). `started_at`
        // pasa a nullable porque una carrera programada todavía no arrancó.
        Schema::table('rides', function (Blueprint $table) {
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'cancelled'])
                ->default('in_progress')
                ->change();

            $table->timestamp('started_at')->nullable()->change();

            // Copiado de la RideRequest de origen al aceptar (mismo criterio
            // que rate_per_km_snapshot/price: una "foto" en el momento, no se
            // recalcula después) — para que el conductor/cliente lo tengan a
            // mano en Ride/Show.vue sin volver a la solicitud ya resuelta.
            $table->boolean('round_trip')->default(false)->after('distance_km');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('round_trip');
            $table->timestamp('started_at')->nullable(false)->change();
            $table->enum('status', ['in_progress', 'completed', 'cancelled'])->default('in_progress')->change();
        });
    }
};
