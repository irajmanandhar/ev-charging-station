<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /** Statuses that hold a charger's time window. */
    public static function blocking(): array
    {
        return [self::Confirmed->value, self::Active->value];
    }
}
