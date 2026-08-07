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
        // fleets: la flota es del CLIENTE, no del conductor (aclaración clave de la
        // sección 3.2 y 7.1 del alcance). Cualquier usuario puede ser dueño de una flota.
        Schema::create('fleets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');

            // No hay límite de cantidad de flotas a nivel de tabla: el plan Gratis permite
            // 1 sola (sección 7.3) pero eso se valida en la app (config/arka.php), no acá,
            // así el futuro plan "Multi-flota" no requiere ninguna migración nueva.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fleets');
    }
};
