<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrección explícita del usuario sobre la migración anterior
 * (2026_09_10_120000_add_requires_fleet_invitation_approval_to_driver_profiles_table):
 * el pedido era que la aprobación manual viniera DESACTIVADA por defecto
 * para TODOS los conductores (auto-aceptar invitaciones, con solo un aviso),
 * no activada — "te dije que a todos les pongas por default desactivado".
 * Se había dejado en `true` asumiendo que había que preservar el
 * comportamiento de quienes ya existían, pero el usuario aclaró que ese no
 * era el pedido: alcanza a TODOS, incluidas las cuentas ya creadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->boolean('requires_fleet_invitation_approval')->default(false)->change();
        });

        DB::table('driver_profiles')->update(['requires_fleet_invitation_approval' => false]);
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->boolean('requires_fleet_invitation_approval')->default(true)->change();
        });

        DB::table('driver_profiles')->update(['requires_fleet_invitation_approval' => true]);
    }
};
