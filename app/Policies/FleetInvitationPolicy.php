<?php

namespace App\Policies;

use App\Models\FleetInvitation;
use App\Models\User;

class FleetInvitationPolicy
{
    /**
     * Solo el conductor invitado puede aceptar o rechazar su propia invitación
     * (sección 3.2: nadie queda en una flota sin su consentimiento).
     */
    public function respond(User $user, FleetInvitation $invitation): bool
    {
        return $user->id === $invitation->driver_user_id;
    }

    /**
     * Solo el cliente dueño de la flota puede cancelar una invitación que
     * todavía está pendiente.
     */
    public function cancel(User $user, FleetInvitation $invitation): bool
    {
        return $user->id === $invitation->invited_by;
    }
}
