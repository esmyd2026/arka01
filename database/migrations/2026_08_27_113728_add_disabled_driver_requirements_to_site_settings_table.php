<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: "permiteme desde el admin poder activar
     * o no lo obligatorio para que el conductor se le haga mas facil
     * activarse" — mismo criterio que `disabled_quick_links` (array de keys
     * que un admin apagó, vacío = todo exigido, el comportamiento de
     * siempre). Ver App\Services\DriverVerificationRequirementRegistry.
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->json('disabled_driver_requirements')->nullable()->after('disabled_quick_links');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('disabled_driver_requirements');
        });
    }
};
