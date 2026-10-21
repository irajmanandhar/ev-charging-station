<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Station;
use App\Models\User;

class StationPolicy
{
    public function create(User $user): bool
    {
        return in_array($user->role, [UserRole::Operator, UserRole::Admin], true);
    }

    public function manage(User $user, Station $station): bool
    {
        return $user->isAdmin() || $station->operator_id === $user->id;
    }
}
