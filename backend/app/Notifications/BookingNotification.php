<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Shared shape for booking emails: subclasses supply the subject and body lines. */
abstract class BookingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Booking $booking)
    {
        $this->afterCommit();
    }

    abstract protected function subject(): string;

    /** @return string[] */
    abstract protected function lines(string $station, string $when): array;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking->loadMissing('charger.station');
        $station = $booking->charger->station;
        $when = $booking->starts_at->copy()->setTimezone($station->timezone)->format('D j M, H:i');

        $mail = (new MailMessage)->subject($this->subject());
        foreach ($this->lines($station->name, $when) as $line) {
            $mail->line($line);
        }

        return $mail->action('View my bookings', rtrim(config('ev.frontend_url'), '/').'/bookings');
    }

    protected function money(float $amount): string
    {
        return config('ev.currency').' '.number_format($amount, 2);
    }
}
