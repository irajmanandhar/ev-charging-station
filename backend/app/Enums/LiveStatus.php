<?php

namespace App\Enums;

/** What a driver sees right now, derived from charger status, sessions and bookings. */
enum LiveStatus: string
{
    case Available = 'available';
    case Occupied = 'occupied';
    case Reserved = 'reserved';
    case Offline = 'offline';
}
