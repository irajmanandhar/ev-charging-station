<?php

namespace App\Notifications;

/** Sent shortly before a confirmed booking starts. */
class BookingReminder extends BookingNotification
{
    protected function subject(): string
    {
        return 'Your charging slot starts soon';
    }

    protected function lines(string $station, string $when): array
    {
        $b = $this->booking;

        return [
            "Reminder: your booking {$b->reference} starts at {$when}.",
            "{$station}, charger {$b->charger->label}. Check-in opens ".config('ev.checkin_early_minutes').' minutes before your slot.',
        ];
    }
}
