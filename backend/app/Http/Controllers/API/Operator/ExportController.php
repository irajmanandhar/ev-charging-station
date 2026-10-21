<?php

namespace App\Http\Controllers\API\Operator;

use App\Http\Controllers\API\BaseController;
use App\Models\Booking;
use App\Models\Station;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends BaseController
{
    private const MAX_DAYS = 93;

    /** CSV of bookings (with session energy and cost) at the operator's stations. Query: from, to (YYYY-MM-DD, local), station_id */
    public function bookings(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
        ]);
        $user = $request->user();
        $tz = config('ev.default_timezone');

        $from = Carbon::parse($data['from'], $tz)->startOfDay();
        $to = Carbon::parse($data['to'], $tz)->addDay()->startOfDay();
        abort_if($from->diffInDays($to) > self::MAX_DAYS, 422, 'Pick a range of '.self::MAX_DAYS.' days or less.');

        $stationIds = Station::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('operator_id', $user->id))
            ->when($data['station_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->pluck('id');
        abort_if(isset($data['station_id']) && $stationIds->isEmpty(), 403, 'You are not allowed to do that.');

        $query = Booking::query()
            ->whereHas('charger', fn ($q) => $q->whereIn('station_id', $stationIds))
            ->where('starts_at', '>=', $from->copy()->utc())
            ->where('starts_at', '<', $to->copy()->utc())
            ->with(['charger.station', 'user:id,name,email', 'chargingSession'])
            ->orderBy('starts_at');

        $filename = "bookings-{$data['from']}-to-{$data['to']}.csv";

        return response()->streamDownload(function () use ($query, $tz) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'reference', 'station', 'charger', 'driver', 'driver_email', 'status',
                "starts_at ({$tz})", "ends_at ({$tz})", 'minutes', 'estimated_kwh', 'estimated_cost',
                'cancellation_fee', 'session_kwh', 'session_cost',
            ]);

            foreach ($query->lazyById() as $b) {
                $s = $b->chargingSession;
                fputcsv($out, array_map([self::class, 'cell'], [
                    $b->reference, $b->charger->station->name, $b->charger->label, $b->user->name, $b->user->email,
                    $b->status->value,
                    $b->starts_at->copy()->setTimezone($tz)->format('Y-m-d H:i'),
                    $b->ends_at->copy()->setTimezone($tz)->format('Y-m-d H:i'),
                    (int) $b->starts_at->diffInMinutes($b->ends_at),
                    $b->estimated_kwh, $b->estimated_cost, $b->cancellation_fee,
                    $s?->energy_kwh, $s?->cost,
                ]));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralise spreadsheet formulas in user-supplied text (CSV injection). */
    public static function cell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "'".$value;
        }

        return $value;
    }
}
