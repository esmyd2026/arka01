<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zona de cobertura del conductor por sector con nombre (pedido explícito
 * del usuario: "que los conductores puedan indicar la zona de trabajo" y
 * "el cliente... pueda ver a los conductores de su sector"). Reusa el mismo
 * catálogo de sectores/ciudades ya cargado para origen/destino de una
 * carrera — sin coordenadas nuevas ni cálculo geométrico: un conductor
 * simplemente marca en qué sectores trabaja, un cliente filtra el
 * directorio por el sector que le interesa. Ver App\Models\DriverProfile::
 * coverageSectors() y App\Services\Driver\DriverDirectoryFinder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_profile_sector', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sector_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['driver_profile_id', 'sector_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_profile_sector');
    }
};
