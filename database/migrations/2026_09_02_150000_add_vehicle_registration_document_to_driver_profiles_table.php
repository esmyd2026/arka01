<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario: "esto quitemos ocultalo y que no sea
 * obligatorio... coloquemos mejor la matricula del vehiculo" — reemplaza al
 * certificado de antecedentes penales (police_record) en el formulario del
 * conductor y en el registro de requisitos exigibles
 * (DriverVerificationRequirementRegistry) por la matrícula del vehículo. La
 * columna police_record_path (y los documentos ya subidos con ella) se
 * conservan sin tocar — solo deja de pedirse a partir de ahora, mismo
 * criterio no-destructivo que el resto de migraciones de esta app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->string('vehicle_registration_path')->nullable()->after('police_record_path');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn('vehicle_registration_path');
        });
    }
};
