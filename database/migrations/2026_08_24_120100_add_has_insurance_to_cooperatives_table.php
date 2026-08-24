<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requisito de validación pedido explícito del usuario: la cooperativa
 * declara si cuenta con un seguro que proteja al representante/dueño, a los
 * conductores afiliados y a los vehículos — autodeclarado con un checkbox,
 * sin documento adjunto. Ver CooperativeProfileController::submitForReview()
 * y Admin\CooperativeController::approve().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cooperatives', function (Blueprint $table) {
            $table->boolean('has_insurance')->default(false)->after('declared_unit_count');
        });
    }

    public function down(): void
    {
        Schema::table('cooperatives', function (Blueprint $table) {
            $table->dropColumn('has_insurance');
        });
    }
};
