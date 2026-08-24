<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requisito de validación pedido explícito del usuario: además de cédula,
 * licencia y antecedentes, el conductor declara si cuenta con un seguro que
 * lo proteja a él, a los pasajeros y al vehículo — autodeclarado con un
 * checkbox, sin documento adjunto (no como los otros 3, que sí piden un
 * archivo). Ver DriverProfile::hasCompleteRegistrationInformation().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->boolean('has_insurance')->default(false)->after('police_record_path');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn('has_insurance');
        });
    }
};
