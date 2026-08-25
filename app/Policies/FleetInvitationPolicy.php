<?php

namespace App\Policies;

use App\Models\FleetInvitation;
use App\Models\User;

class FleetInvitationPolicy
{
    /**
     * Quien tiene que responder (aceptar o rechazar) es siempre la OTRA
     * parte, nunca quien mandó la invitación/solicitud (sección 3.2: nadie
     * queda en una flota sin su consentimiento) — pedido explícito del
     * usuario: ahora esto puede ir en cualquiera de las dos direcciones (un
     * cliente invita a un conductor, o un conductor le pide unirse a un
     * cliente), ver FleetInvitation::respondingPartyId().
     */
    public function respond(User $user, FleetInvitation $invitation): bool
    {
        return $user->id === $invitation->respondingPartyId();
    }

    /**
     * Solo quien mandó la invitación/solicitud puede cancelarla mientras
     * sigue pendiente — sin importar la dirección, `invited_by` es siempre
     * quien la inició. Excepción: una recomendación ("Recomendar mi flota",
     * pedido explícito del usuario) la manda un tercero a nombre de otro
     * cliente — quien de verdad la ve pendiente en su propia pantalla es el
     * DUEÑO de la flota destino (mi amigo), no quien la mandó, así que
     * también puede cancelarla.
     */
    public function cancel(User $user, FleetInvitation $invitation): bool
    {
        if ($invitation->initiated_by === 'referral') {
            return $user->id === $invitation->invited_by || $user->id === $invitation->fleet->owner_user_id;
        }

        return $user->id === $invitation->invited_by;
    }
}
