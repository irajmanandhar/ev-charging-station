<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\ChargerStatus;
use App\Enums\SessionStatus;
use App\Enums\StationStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Booking;
use App\Models\Charger;
use App\Models\ChargingSession;
use App\Models\User;
use App\Notifications\BookingCancelled;
use App\Notifications\BookingConfirmed;
use App\Notifications\BookingNoShow;
use App\Notifications\BookingReminder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BookingService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly PaymentService $payments,
    ) {
    }

    /**
     * Reserve a charger for [start, end).
     *
     * The charger row is locked for the duration of the transaction, so two drivers
     * racing for the same slot are serialised and the second one sees the first
     * booking in its overlap check (MySQL/Postgres; SQLite serialises writers anyway).
     */
    public function create(User $user, Charger $charger, Carbon $start, Carbon $end, ?string $vehicle = null): Booking
    {
        $start = $start->copy()->utc()->startOfMinute();
        $end = $end->copy()->utc()->startOfMinute();

        $this->assertValidWindow($start, $end);

        return DB::transaction(function () use ($user, $charger, $start, $end, $vehicle) {
            // Lock order is always user, then charger. Serialises one driver's concurrent
            // requests so the per-user overlap and upcoming-bookings checks can't be raced.
            User::query()->lockForUpdate()->findOrFail($user->id);

            /** @var Charger $locked */
            $locked = Charger::query()->with('station')->lockForUpdate()->findOrFail($charger->id);

            $this->assertBookable($locked, $start, $end);

            if (Booking::query()->overlapping($locked->id, $start, $end)->exists()) {
                throw new DomainRuleException('That time slot was just taken. Please pick another one.', 'slot_taken', 409);
            }

            $userClash = Booking::query()
                ->where('user_id', $user->id)
                ->whereIn('status', BookingStatus::blocking())
                ->where('starts_at', '<', $end)
                ->where('ends_at', '>', $start)
                ->exists();

            if ($userClash) {
                throw new DomainRuleException('You already have a booking that overlaps this time.', 'user_overlap');
            }

            $upcoming = Booking::query()
                ->where('user_id', $user->id)
                ->where('status', BookingStatus::Confirmed->value)
                ->where('ends_at', '>', now())
                ->count();

            if ($upcoming >= config('ev.max_upcoming_bookings_per_user')) {
                throw new DomainRuleException(
                    'You can hold at most '.config('ev.max_upcoming_bookings_per_user').' upcoming bookings.',
                    'too_many_bookings'
                );
            }

            $quote = $this->pricing->quote($locked, $start->diffInMinutes($end));

            $booking = Booking::create([
                'user_id' => $user->id,
                'charger_id' => $locked->id,
                'starts_at' => $start,
                'ends_at' => $end,
                'status' => BookingStatus::Confirmed,
                'vehicle' => $vehicle,
                'estimated_kwh' => $quote['kwh'],
                'estimated_cost' => $quote['cost'],
            ]);

            $user->notify(new BookingConfirmed($booking));

            return $booking;
        });
    }

    public function cancel(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking) {
            // Re-read under lock so a concurrent check-in can't be overwritten.
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($booking->status !== BookingStatus::Confirmed) {
                throw new DomainRuleException('Only upcoming bookings can be cancelled.', 'not_cancellable');
            }

            if ($booking->starts_at->isPast()) {
                throw new DomainRuleException('This booking has already started. Check in or let it lapse.', 'already_started');
            }

            // Late cancellations keep a fee; earlier ones are free.
            $late = now()->gt($booking->starts_at->copy()->subMinutes(config('ev.cancellation_free_minutes')));
            $fee = $late ? round($booking->estimated_cost * config('ev.cancellation_fee_percent') / 100, 2) : 0;

            $booking->update([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_fee' => $fee,
            ]);

            $this->payments->refundFor($booking);
            $booking->user->notify(new BookingCancelled($booking));

            return $booking;
        });
    }

    /** Driver checks in at the charger and charging begins. */
    public function checkIn(Booking $booking): ChargingSession
    {
        return DB::transaction(function () use ($booking) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $charger = Charger::query()->with('station')->lockForUpdate()->findOrFail($booking->charger_id);
            $now = now();

            if ($booking->status !== BookingStatus::Confirmed) {
                throw new DomainRuleException('This booking cannot be started.', 'not_startable');
            }

            $opensAt = $booking->starts_at->copy()->subMinutes(config('ev.checkin_early_minutes'));
            if ($now->lt($opensAt)) {
                throw new DomainRuleException(
                    'Check-in opens '.config('ev.checkin_early_minutes').' minutes before your slot.',
                    'too_early'
                );
            }

            $closesAt = $booking->starts_at->copy()->addMinutes(config('ev.no_show_grace_minutes'));
            if ($now->gt($closesAt) || $now->gte($booking->ends_at)) {
                throw new DomainRuleException('The check-in window for this booking has passed.', 'too_late');
            }

            if ($charger->status !== ChargerStatus::Available || $charger->station->status !== StationStatus::Active) {
                throw new DomainRuleException('This charger is currently out of service.', 'charger_unavailable');
            }

            // Close out any session whose booking has already ended (e.g. driver forgot to stop).
            $this->sweepCharger($charger);

            if ($charger->chargingSessions()->where('status', SessionStatus::Active->value)->exists()) {
                throw new DomainRuleException('The charger is still in use. Please wait a moment.', 'charger_busy', 409);
            }

            // Early check-in must not eat into someone else's slot.
            if ($now->lt($booking->starts_at)) {
                $blocked = Booking::query()
                    ->overlapping($charger->id, $now, $booking->starts_at)
                    ->whereKeyNot($booking->id)
                    ->exists();

                if ($blocked) {
                    throw new DomainRuleException('The charger is reserved until your slot starts.', 'reserved_before');
                }
            }

            $booking->update(['status' => BookingStatus::Active]);

            return ChargingSession::create([
                'user_id' => $booking->user_id,
                'charger_id' => $charger->id,
                'booking_id' => $booking->id,
                'started_at' => $now,
                'status' => SessionStatus::Active,
            ]);
        });
    }

    /**
     * Walk-up charging: book from now for $minutes and start straight away.
     */
    public function startNow(User $user, Charger $charger, int $minutes, ?string $vehicle = null): ChargingSession
    {
        $start = now()->startOfMinute();
        $end = $start->copy()->addMinutes($minutes);

        // One transaction: if check-in fails the booking is rolled back, not left holding the slot.
        return DB::transaction(function () use ($user, $charger, $start, $end, $vehicle) {
            $booking = $this->create($user, $charger, $start, $end, $vehicle);

            return $this->checkIn($booking);
        });
    }

    /** Stop a session, meter it, and release the rest of the booking window. */
    public function stop(ChargingSession $session, ?Carbon $at = null): ChargingSession
    {
        return DB::transaction(function () use ($session, $at) {
            $session = ChargingSession::query()->with(['charger', 'booking'])->lockForUpdate()->findOrFail($session->id);

            if ($session->status !== SessionStatus::Active) {
                throw new DomainRuleException('This session has already ended.', 'not_active');
            }

            $endedAt = ($at ?? now())->copy();
            if ($session->booking && $endedAt->gt($session->booking->ends_at)) {
                $endedAt = $session->booking->ends_at->copy();
            }
            if ($endedAt->lt($session->started_at)) {
                $endedAt = $session->started_at->copy();
            }

            $minutes = $session->started_at->diffInSeconds($endedAt) / 60;
            $kwh = $this->pricing->energyFor($session->charger, $minutes);

            $session->update([
                'ended_at' => $endedAt,
                'energy_kwh' => $kwh,
                'cost' => $this->pricing->costFor($session->charger, $kwh),
                'status' => SessionStatus::Completed,
            ]);

            if ($session->booking) {
                $session->booking->update([
                    'status' => BookingStatus::Completed,
                    // Free the remainder of the slot for other drivers.
                    'ends_at' => $endedAt->gt($session->booking->starts_at) ? $endedAt : $session->booking->ends_at,
                ]);
            }

            return $session->refresh();
        });
    }

    /**
     * Housekeeping, run every minute by the scheduler:
     *  - confirmed bookings past their grace window become no-shows
     *  - sessions whose booking window ended are stopped at the window end
     *
     * @return array{no_shows: int, auto_stopped: int}
     */
    public function sweep(): array
    {
        $missed = Booking::query()
            ->with('user')
            ->where('status', BookingStatus::Confirmed->value)
            ->where('starts_at', '<', now()->subMinutes(config('ev.no_show_grace_minutes')))
            ->get();

        // Re-check status in the UPDATE so a booking checked in meanwhile is not clobbered.
        $noShows = Booking::query()
            ->whereIn('id', $missed->modelKeys())
            ->where('status', BookingStatus::Confirmed->value)
            ->update(['status' => BookingStatus::NoShow->value, 'updated_at' => now()]);

        $missed->each(fn (Booking $b) => $b->user->notify(new BookingNoShow($b)));

        $stopped = 0;
        ChargingSession::query()
            ->where('status', SessionStatus::Active->value)
            ->whereHas('booking', fn ($q) => $q->where('ends_at', '<=', now()))
            ->each(function (ChargingSession $session) use (&$stopped) {
                try {
                    $this->stop($session);
                    $stopped++;
                } catch (DomainRuleException) {
                    // Stopped by the driver between the query and the lock; nothing to do.
                }
            });

        return ['no_shows' => $noShows, 'auto_stopped' => $stopped];
    }

    /** Email a reminder for confirmed bookings starting soon. Safe to run every minute. */
    public function sendReminders(): int
    {
        $sent = 0;

        Booking::query()
            ->with('user')
            ->where('status', BookingStatus::Confirmed->value)
            ->whereNull('reminder_sent_at')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addMinutes(config('ev.reminder_minutes_before')))
            ->each(function (Booking $booking) use (&$sent) {
                // Claim the booking first so overlapping runs can not send it twice.
                $claimed = Booking::query()->whereKey($booking->id)->whereNull('reminder_sent_at')->update(['reminder_sent_at' => now()]);
                if ($claimed) {
                    $booking->user->notify(new BookingReminder($booking));
                    $sent++;
                }
            });

        return $sent;
    }

    private function sweepCharger(Charger $charger): void
    {
        $charger->chargingSessions()
            ->where('status', SessionStatus::Active->value)
            ->whereHas('booking', fn ($q) => $q->where('ends_at', '<=', now()))
            ->get()
            ->each(function (ChargingSession $s) {
                try {
                    $this->stop($s);
                } catch (DomainRuleException) {
                    // Already stopped concurrently.
                }
            });
    }

    private function assertValidWindow(Carbon $start, Carbon $end): void
    {
        $slot = (int) config('ev.slot_minutes');
        $minutes = $start->diffInMinutes($end, false);

        if ($minutes < config('ev.min_booking_minutes') || $minutes > config('ev.max_booking_minutes')) {
            throw new DomainRuleException(sprintf(
                'Bookings must be between %d minutes and %d hours.',
                config('ev.min_booking_minutes'),
                config('ev.max_booking_minutes') / 60
            ), 'bad_duration');
        }

        if ($minutes % $slot !== 0) {
            throw new DomainRuleException("Durations go in {$slot}-minute steps.", 'bad_duration');
        }

        if ($start->lt(now()->subMinutes(5))) {
            throw new DomainRuleException('That time is already in the past.', 'in_past');
        }

        if ($start->gt(now()->addDays(config('ev.booking_horizon_days')))) {
            throw new DomainRuleException(
                'You can book up to '.config('ev.booking_horizon_days').' days ahead.',
                'too_far_ahead'
            );
        }
    }

    private function assertBookable(Charger $charger, Carbon $start, Carbon $end): void
    {
        if ($charger->station->status !== StationStatus::Active) {
            throw new DomainRuleException('This station is not accepting bookings right now.', 'station_unavailable');
        }

        if ($charger->status !== ChargerStatus::Available) {
            throw new DomainRuleException('This charger is out of service.', 'charger_unavailable');
        }

        if (! $charger->station->isOpenBetween($start, $end)) {
            throw new DomainRuleException('The station is closed for part of that time.', 'station_closed');
        }
    }
}
