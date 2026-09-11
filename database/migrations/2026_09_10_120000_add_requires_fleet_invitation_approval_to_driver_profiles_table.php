<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario: el conductor puede desactivar la
     * aprobación manual de invitaciones de flota desde su propio perfil —
     * con esto en `false`, todo cliente que lo agregue a su flota queda
     * vinculado de una, sin esperar su respuesta (solo se le avisa, ver
     * App\Notifications\FleetInvitationAutoAcceptedPushNotification). Empieza
     * en `true` para no cambiarle el comportamiento a nadie: hoy TODA
     * invitación necesita respuesta explícita del conductor (ver
     * App\Services\Fleet\FleetInvitationCreator::create()).
     */
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->boolean('requires_fleet_invitation_approval')->default(true)->after('pickup_surcharge_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn('requires_fleet_invitation_approval');
        });
    }
};
