<?php

namespace App\Http\Controllers\API\Operator;

use App\Enums\BookingStatus;
use App\Http\Controllers\API\BaseController;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class BookingController extends BaseController
{
    /** Bookings across the operator's stations. Query: station_id, date (YYYY-MM-DD, local) */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::enum(BookingStatus::class)],
        ]);
        $user = $request->user();

        $stationIds = Station::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('operator_id', $user->id))
            ->when($data['station_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->pluck('id');

        if (isset($data['station_id']) && $stationIds->isEmpty()) {
            return $this->sendError('You are not allowed to do that.', 403);
        }

        $tz = config('ev.default_timezone');
        $day = Carbon::parse($data['date'] ?? now($tz)->toDateString(), $tz)->startOfDay();

        $bookings = Booking::query()
            ->whereHas('charger', fn ($q) => $q->whereIn('station_id', $stationIds))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->where('starts_at', '<', $day->copy()->addDay()->utc())
            ->where('ends_at', '>', $day->copy()->utc())
            ->with(['charger.station', 'user', 'chargingSession'])
            ->orderBy('starts_at')
            ->get();

        return $this->sendResponse(BookingResource::collection($bookings));
    }
}
