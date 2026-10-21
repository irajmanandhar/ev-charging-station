<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\LiveStatus;
use App\Enums\SessionStatus;
use App\Models\Booking;
use App\Models\Charger;
use App\Models\ChargingSession;
use App\Models\Station;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MetricsService
{
    /**
     * Usage metrics for a set of stations over [from, to).
     *
     * Totals and per-station figures are aggregated in SQL; the per-day and per-hour
     * series are bucketed in PHP so they follow the stations' local time zone on any DB.
     *
     * @param  Collection<int, Station>  $stations
     */
    public function forStations(Collection $stations, Carbon $from, Carbon $to, ?string $tz = null): array
    {
        $tz ??= config('ev.default_timezone');
        $stationIds = $stations->pluck('id')->all();
        $chargerIds = Charger::query()->whereIn('station_id', $stationIds)->pluck('id')->all();

        $completed = ChargingSession::query()
            ->whereIn('charger_id', $chargerIds)
            ->where('status', SessionStatus::Completed->value)
            ->where('started_at', '>=', $from)
            ->where('started_at', '<', $to);

        $totals = (clone $completed)
            ->selectRaw('COUNT(*) as sessions, COALESCE(SUM(energy_kwh),0) as energy, COALESCE(SUM(cost),0) as revenue')
            ->first();

        $sessions = (clone $completed)
            ->with('charger:id,station_id,connector_type')
            ->get(['id', 'charger_id', 'started_at', 'ended_at', 'energy_kwh', 'cost']);

        $chargedMinutes = $sessions->sum(fn ($s) => $s->started_at->diffInSeconds($s->ended_at) / 60);
        $elapsedTo = $to->copy()->min(now());
        $availableMinutes = $this->availableMinutes($stations, $from, $elapsedTo);

        $bookingCounts = Booking::query()
            ->whereIn('charger_id', $chargerIds)
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $to)
            ->select('status', DB::raw('COUNT(*) as n'))
            ->groupBy('status')
            ->pluck('n', 'status');

        $bookingTotal = (int) $bookingCounts->sum();

        return [
            'range' => [
                'from' => $from->toIso8601ZuluString(),
                'to' => $to->toIso8601ZuluString(),
                'timezone' => $tz,
            ],
            'totals' => [
                'sessions' => (int) $totals->sessions,
                'energy_kwh' => round((float) $totals->energy, 1),
                'revenue' => round((float) $totals->revenue, 2),
                'avg_session_minutes' => $sessions->count() ? round($chargedMinutes / $sessions->count()) : 0,
                'utilization' => $availableMinutes > 0 ? round($chargedMinutes / $availableMinutes, 4) : 0,
                'bookings' => $bookingTotal,
                'no_show_rate' => $bookingTotal ? round(($bookingCounts[BookingStatus::NoShow->value] ?? 0) / $bookingTotal, 4) : 0,
                'cancellation_rate' => $bookingTotal ? round(($bookingCounts[BookingStatus::Cancelled->value] ?? 0) / $bookingTotal, 4) : 0,
            ],
            'daily' => $this->daily($sessions, $from, $to, $tz),
            'hourly' => $this->hourly($sessions, $tz),
            'by_connector' => $sessions
                ->groupBy(fn ($s) => $s->charger->connector_type->value)
                ->map(fn ($g, $type) => [
                    'connector_type' => $type,
                    'sessions' => $g->count(),
                    'energy_kwh' => round($g->sum('energy_kwh'), 1),
                ])
                ->sortByDesc('energy_kwh')
                ->values()
                ->all(),
            'by_station' => $this->byStation($stations, $sessions, $from, $to),
            'live' => $this->liveCounts($chargerIds),
            'upcoming_bookings' => Booking::query()
                ->whereIn('charger_id', $chargerIds)
                ->where('status', BookingStatus::Confirmed->value)
                ->where('starts_at', '>', now())
                ->count(),
        ];
    }

    private function daily(Collection $sessions, Carbon $from, Carbon $to, string $tz): array
    {
        $buckets = [];
        for ($d = $from->copy()->setTimezone($tz)->startOfDay(); $d->lt($to); $d->addDay()) {
            $buckets[$d->toDateString()] = ['date' => $d->toDateString(), 'sessions' => 0, 'energy_kwh' => 0.0, 'revenue' => 0.0];
        }

        foreach ($sessions as $s) {
            $key = $s->started_at->copy()->setTimezone($tz)->toDateString();
            if (! isset($buckets[$key])) {
                continue;
            }
            $buckets[$key]['sessions']++;
            $buckets[$key]['energy_kwh'] += $s->energy_kwh;
            $buckets[$key]['revenue'] += $s->cost;
        }

        return array_values(array_map(fn ($b) => [
            ...$b,
            'energy_kwh' => round($b['energy_kwh'], 1),
            'revenue' => round($b['revenue'], 2),
        ], $buckets));
    }

    private function hourly(Collection $sessions, string $tz): array
    {
        $hours = array_fill(0, 24, 0);
        foreach ($sessions as $s) {
            $hours[(int) $s->started_at->copy()->setTimezone($tz)->format('G')]++;
        }

        return array_map(fn ($count, $hour) => ['hour' => $hour, 'sessions' => $count], $hours, array_keys($hours));
    }

    private function byStation(Collection $stations, Collection $sessions, Carbon $from, Carbon $to): array
    {
        $chargerStation = Charger::query()
            ->whereIn('station_id', $stations->pluck('id'))
            ->pluck('station_id', 'id');

        $grouped = $sessions->groupBy(fn ($s) => $chargerStation[$s->charger_id] ?? 0);

        return $stations->map(function (Station $station) use ($grouped, $from, $to) {
            $g = $grouped->get($station->id, collect());
            $minutes = $g->sum(fn ($s) => $s->started_at->diffInSeconds($s->ended_at) / 60);
            $available = $this->availableMinutes(collect([$station]), $from, $to->copy()->min(now()));

            return [
                'station_id' => $station->id,
                'name' => $station->name,
                'sessions' => $g->count(),
                'energy_kwh' => round($g->sum('energy_kwh'), 1),
                'revenue' => round($g->sum('cost'), 2),
                'utilization' => $available > 0 ? round($minutes / $available, 4) : 0,
            ];
        })->sortByDesc('revenue')->values()->all();
    }

    /** Charger-minutes the stations were open over the range. */
    private function availableMinutes(Collection $stations, Carbon $from, Carbon $to): float
    {
        $days = max(0, $from->diffInMinutes($to) / 1440);
        $total = 0.0;

        foreach ($stations as $station) {
            $chargers = $station->chargers_count ?? $station->chargers()->count();
            if ($station->is_24_hours || ! $station->opens_at || ! $station->closes_at) {
                $openPerDay = 1440;
            } else {
                $openPerDay = Carbon::parse($station->opens_at)->diffInMinutes(Carbon::parse($station->closes_at));
            }
            $total += $chargers * $openPerDay * $days;
        }

        return $total;
    }

    private function liveCounts(array $chargerIds): array
    {
        $counts = array_fill_keys(array_map(fn ($c) => $c->value, LiveStatus::cases()), 0);

        Charger::query()
            ->whereIn('id', $chargerIds)
            ->with(['station', ...Charger::liveStateRelations()])
            ->get()
            ->each(function (Charger $c) use (&$counts) {
                $counts[$c->liveStatus()->value]++;
            });

        return $counts;
    }
}
