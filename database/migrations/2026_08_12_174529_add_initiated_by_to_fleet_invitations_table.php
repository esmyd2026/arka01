<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario: además de que un cliente invite a un
 * conductor (el único sentido que existía hasta ahora), un conductor
 * también pueda mandarle una solicitud a un cliente puntual para unirse a
 * su flota — buscándolo, no esperando a que lo inviten. `invited_by` sigue
 * siendo "quién mandó esto" (ahora puede ser el conductor), `initiated_by`
 * es la dirección — de quién depende responder (aceptar/rechazar) y quién
 * puede cancelarla. Ver App\Policies\FleetInvitationPolicy y
 * App\Http\Controllers\FleetInvitationController.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_invitations', function (Blueprint $table) {
            $table->enum('initiated_by', ['client', 'driver'])->default('client')->after('invited_by');
        });
    }

    public function down(): void
    {
        Schema::table('fleet_invitations', function (Blueprint $table) {
            $table->dropColumn('initiated_by');
        });
    }
};
