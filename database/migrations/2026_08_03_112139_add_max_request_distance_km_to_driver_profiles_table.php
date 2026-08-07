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
        // Zona de cobertura (pedido explícito del usuario): el conductor
        // indica hasta qué distancia de su ubicación actual quiere recibir
        // solicitudes — evita que le lleguen carreras que no le convienen por
        // los km (ej. vive en Samborondón y le llega una desde el Guasmo),
        // aunque el cliente sea de su propia flota. Null = sin límite (todas
        // las cuentas existentes siguen recibiendo todo, como hasta ahora).
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_request_distance_km')->nullable()->after('rate_per_km');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn('max_request_distance_km');
        });
    }
};
