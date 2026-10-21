<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\ChargerStatus;
use App\Enums\StationStatus;
use App\Models\Booking;
use App\Models\Charger;
use Illuminate\Support\Carbon;

class AvailabilityService
{
    /**
     * Slot grid for one charger on one local calendar day.
     *
     * @return array{date: string, timezone: string, opens_at: string, closes_at: string, slot_minutes: int, slots: array, busy: array}
     */
    public function forDay(Charger $charger, string $date): array
    {
        $station = $charger->station;
        $tz = $station->timezone;
        $slot = (int) config('ev.slot_minutes');

        $day = Carbon::parse($date, $tz)->startOfDay();
        if ($station->is_24_hours || ! $station->opens_at || ! $station->closes_at) {
            $open = $day->copy();
            $close = $day->copy()->addDay();
        } else {
            $open = $day->copy()->setTimeFromTimeString($station->opens_at);
            $close = $day->copy()->setTimeFromTimeString($station->closes_at);
        }

        $busy = Booking::query()
            ->overlapping($charger->id, $open->copy()->utc(), $close->copy()->utc())
            ->orderBy('starts_at')
            ->get(['starts_at', 'ends_at', 'status']);

        $bookable = $this->bookable($charger);
        $now = now();
        $slots = [];
        for ($cursor = $open->copy(); $cursor->lt($close); $cursor->addMinutes($slot)) {
            $slotStart = $cursor->copy()->utc();
            $slotEnd = $slotStart->copy()->addMinutes($slot);

            $taken = $busy->contains(fn (Booking $b) => $b->starts_at->lt($slotEnd) && $b->ends_at->gt($slotStart));

            $slots[] = [
                'start' => $slotStart->toIso8601ZuluString(),
                'end' => $slotEnd->toIso8601ZuluString(),
                'free' => $bookable && ! $taken && $slotStart->gte($now->copy()->subMinutes(1)),
                'past' => $slotEnd->lte($now),
            ];
        }

        return [
            'date' => $day->toDateString(),
            'timezone' => $tz,
            'opens_at' => $open->toIso8601String(),
            'closes_at' => $close->toIso8601String(),
            'slot_minutes' => $slot,
            'min_minutes' => (int) config('ev.min_booking_minutes'),
            'max_minutes' => (int) config('ev.max_booking_minutes'),
            'slots' => $slots,
            'busy' => $busy->map(fn (Booking $b) => [
                'start' => $b->starts_at->toIso8601ZuluString(),
                'end' => $b->ends_at->toIso8601ZuluString(),
                'status' => $b->status->value,
            ])->values()->all(),
        ];
    }

    /** Minutes the charger is free starting now (until the next blocking booking or max). */
    public function freeMinutesFromNow(Charger $charger): int
    {
        if (! $this->bookable($charger)) {
            return 0;
        }

        $now = now();
        $max = (int) config('ev.max_booking_minutes');

        // Can't run past closing time at a station with opening hours.
        $station = $charger->station;
        if (! $station->is_24_hours && $station->opens_at && $station->closes_at) {
            $local = $now->copy()->setTimezone($station->timezone);
            $opens = $local->copy()->setTimeFromTimeString($station->opens_at);
            $closes = $local->copy()->setTimeFromTimeString($station->closes_at);
            if ($local->lt($opens) || $local->gte($closes)) {
                return 0;
            }
            $max = (int) min($max, floor($local->diffInMinutes($closes)));
        }

        $next = Booking::query()
            ->where('charger_id', $charger->id)
            ->whereIn('status', BookingStatus::blocking())
            ->where('ends_at', '>', $now)
            ->orderBy('starts_at')
            ->first(['starts_at']);

        if (! $next) {
            return $max;
        }

        if ($next->starts_at->lte($now)) {
            return 0;
        }

        return (int) min($max, floor($now->diffInMinutes($next->starts_at)));
    }

    private function bookable(Charger $charger): bool
    {
        return $charger->status === ChargerStatus::Available
            && $charger->station->status === StationStatus::Active;
    }
}
