<?php

use App\Models\Fleet;
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
