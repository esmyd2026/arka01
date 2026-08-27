<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: "eso de numero de licencia para
     * conductores eso no existe en el ecuador" — la licencia de conducir
     * ecuatoriana no tiene un número propio distinto de la cédula (que ya
     * queda cubierta por `identity_document_path`, la foto de la cédula).
     * No se borra la columna (dato histórico de quien ya la haya cargado,
     * y evita una migración destructiva sobre producción) — se deja de
     * pedir y de exigir, nada más. Ver DriverProfile::hasCompleteRegistrationInformation()
     * y DriverProfileController::update().
     */
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('license_number')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('license_number')->nullable(false)->change();
        });
    }
};
