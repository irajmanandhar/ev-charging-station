<?php

namespace App\Notifications;

/** Sent when a driver misses the check-in window. */
class BookingNoShow extends BookingNotification
{
    protected function subject(): string
    {
        return 'Missed booking';
    }

    protected function lines(string $station, string $when): array
    {
        return [
            "You did not check in for booking {$this->booking->reference} at {$station} ({$when}), so the slot was released.",
        ];
    }
}
