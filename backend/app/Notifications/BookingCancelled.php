<?php

namespace App\Notifications;

/** Sent when a booking is cancelled, with any cancellation fee. */
class BookingCancelled extends BookingNotification
{
    protected function subject(): string
    {
        return 'Booking cancelled';
    }

    protected function lines(string $station, string $when): array
    {
        $b = $this->booking;

        return [
            "Your booking {$b->reference} at {$station} ({$when}) was cancelled.",
            $b->cancellation_fee > 0
                ? 'It was inside the free-cancellation window, so a fee of '.$this->money($b->cancellation_fee).' applies.'
                : 'No cancellation fee applies.',
        ];
    }
}
