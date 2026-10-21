<?php

namespace App\Notifications;

/** Sent when a booking is created. */
class BookingConfirmed extends BookingNotification
{
    protected function subject(): string
    {
        return 'Booking confirmed';
    }

    protected function lines(string $station, string $when): array
    {
        $b = $this->booking;

        return [
            "Your booking {$b->reference} is confirmed.",
            "{$station}, charger {$b->charger->label}, {$when} for {$b->starts_at->diffInMinutes($b->ends_at)} minutes.",
            'Estimated cost: '.$this->money($b->estimated_cost).'.',
        ];
    }
}
