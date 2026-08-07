<?php

namespace App\Policies;

use App\Models\Fleet;
use App\Models\User;

class FleetPolicy
{
    /**
     * Solo el dueño de la flota puede verla y administrarla (sección 3.2: la
     * flota es del cliente, nadie más puede tocarla).
     */
    public function view(User $user, Fleet $fleet): bool
    {
        return $user->id === $fleet->owner_user_id;
    }

    public function update(User $user, Fleet $fleet): bool
    {
        return $user->id === $fleet->owner_user_id;
    }

    public function delete(User $user, Fleet $fleet): bool
    {
        return $user->id === $fleet->owner_user_id;
    }
}
