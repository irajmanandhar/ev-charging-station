<?php

namespace App\Policies;

use App\Models\ChargingSession;
use App\Models\User;

class ChargingSessionPolicy
{
    public function stop(User $user, ChargingSession $session): bool
    {
        return $session->user_id === $user->id
            || $user->isAdmin()
            || $session->charger->station->operator_id === $user->id;
    }
}
