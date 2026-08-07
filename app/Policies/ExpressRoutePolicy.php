<?php

namespace App\Policies;

use App\Models\ExpressRoute;
use App\Models\User;

class ExpressRoutePolicy
{
    /**
     * El dueño (cliente), el conductor asignado, cualquiera que ya se haya
     * postulado, o un cliente que pidió sumarse a compartirlo (aceptado o
     * pendiente de respuesta) pueden ver el detalle de la ruta (sección 4.2).
     */
    public function view(User $user, ExpressRoute $route): bool
    {
        return $user->id === $route->client_user_id
            || $user->id === $route->assigned_driver_user_id
            || $route->applications()->where('driver_user_id', $user->id)->exists()
            || $route->companions()->where('passenger_user_id', $user->id)->exists();
    }

    /**
     * Solo el cliente dueño puede editar/pausar/cancelar su propio Expreso.
     */
    public function update(User $user, ExpressRoute $route): bool
    {
        return $user->id === $route->client_user_id;
    }
}
