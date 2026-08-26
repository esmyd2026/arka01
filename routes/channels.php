<?php

use App\Models\Fleet;
use App\Models\Ride;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Canal privado de una flota: ubicación en vivo de sus conductores y nuevas
// solicitudes de carrera (sección 9.3). Solo puede escuchar el dueño de la
// flota o un conductor que sea miembro activo de ella.
Broadcast::channel('fleet.{fleetId}', function ($user, $fleetId) {
    $fleet = Fleet::find($fleetId);

    if (! $fleet) {
        return false;
    }

    if ($user->id === $fleet->owner_user_id) {
        return true;
    }

    return $fleet->activeMembers()->where('driver_user_id', $user->id)->exists();
});

// Canal privado de UNA carrera puntual (sección 10 del roadmap de mejoras:
// chat cliente↔conductor) — a propósito separado del canal de flota, que
// llega a todos sus miembros: acá solo pueden escuchar las dos partes de
// ESTA carrera, nadie más.
Broadcast::channel('ride.{rideId}', function ($user, $rideId) {
    $ride = Ride::find($rideId);

    if (! $ride) {
        return false;
    }

    return (int) $user->id === (int) $ride->client_user_id || (int) $user->id === (int) $ride->driver_user_id;
});

// Canal privado de un ticket de soporte (sección 12 del roadmap de mejoras):
// solo el dueño del ticket o cualquier admin puede escuchar — nadie más
// puede ver la conversación de un tercero con soporte.
Broadcast::channel('support-ticket.{ticketId}', function ($user, $ticketId) {
    if ($user->is_admin) {
        return true;
    }

    $ticket = SupportTicket::find($ticketId);

    return $ticket && (int) $user->id === (int) $ticket->user_id;
});

// Pedido explícito del usuario ("ayudame a ver la trazabilidad en el panel
// administrativo... como tenemos en los bot que hemos desarrollado mejor"):
// primer canal "para todos los admins" de la app, no atado a un recurso
// puntual — sirve para avisar en vivo a cualquier admin conectado, esté
// donde esté, cuando un cliente pide hablar con soporte.
Broadcast::channel('admins', function ($user) {
    return (bool) $user->is_admin;
});
