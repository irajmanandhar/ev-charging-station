<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function view(User $user, Booking $booking): bool
    {
        return $booking->user_id === $user->id
            || $user->isAdmin()
            || $booking->charger->station->operator_id === $user->id;
    }

    /** Cancel and check in are the driver's own actions. */
    public function act(User $user, Booking $booking): bool
    {
        return $booking->user_id === $user->id;
    }
}
