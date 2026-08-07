<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // pricing_settings: los parámetros del cálculo de precio sugerido
        // (sección 5 del alcance — distancia × tarifa × factor horario).
        // Antes vivían como constantes en config/arka.php; ahora son datos
        // editables desde /admin/tarifas, sin volver a tocar código para
        // ajustar un porcentaje o un horario. Es una tabla singleton: siempre
        // hay exactamente una fila (la que crea este mismo up()).
        Schema::create('pricing_settings', function (Blueprint $table) {
            $table->id();

            // Recargo nocturno (sección 5): porcentaje que se suma al precio
            // base cuando la carrera se pide dentro del horario nocturno.
            $table->unsignedInteger('night_surcharge_percent')->default(20);

            // Horario nocturno en formato 24h. Puede cruzar la medianoche
            // (ej. empieza 20, termina 6) — ver App\Services\PriceCalculator.
            $table->unsignedTinyInteger('night_starts_at')->default(20);
            $table->unsignedTinyInteger('night_ends_at')->default(6);

            $table->timestamps();
        });

        DB::table('pricing_settings')->insert([
            'night_surcharge_percent' => 20,
            'night_starts_at' => 20,
            'night_ends_at' => 6,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_settings');
    }
};
