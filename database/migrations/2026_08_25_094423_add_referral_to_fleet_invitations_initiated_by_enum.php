<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Recomendar mi flota" (pedido explícito del usuario): un tercer valor
     * de `initiated_by`, para cuando un cliente le recomienda uno de sus
     * conductores a OTRO cliente (`invited_by` = quien recomienda,
     * distinto del dueño de la flota destino) — ver
     * FleetInvitationController::storeReferral().
     */
    public function up(): void
    {
        Schema::table('fleet_invitations', function (Blueprint $table) {
            $table->enum('initiated_by', ['client', 'driver', 'referral'])->default('client')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fleet_invitations', function (Blueprint $table) {
            $table->enum('initiated_by', ['client', 'driver'])->default('client')->change();
        });
    }
};
