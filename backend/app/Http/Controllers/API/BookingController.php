<?php

namespace App\Http\Controllers\API;

use App\Enums\BookingStatus;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\SessionResource;
use App\Models\Booking;
use App\Models\Charger;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends BaseController
{
    public function __construct(private readonly BookingService $bookings)
    {
    }

    /** Query: scope=upcoming|past|all (default all) */
    public function index(Request $request): JsonResponse
    {
        $scope = $request->input('scope', 'all');

        $bookings = $request->user()->bookings()
            ->with(['charger.station', 'chargingSession'])
            ->when($scope === 'upcoming', fn ($q) => $q
                ->whereIn('status', BookingStatus::blocking())
                ->orderBy('starts_at'))
            ->when($scope === 'past', fn ($q) => $q
                ->whereNotIn('status', BookingStatus::blocking())
                ->orderByDesc('starts_at'))
            ->when($scope === 'all', fn ($q) => $q->orderByDesc('starts_at'))
            ->limit(100)
            ->get();

        return $this->sendResponse(BookingResource::collection($bookings));
    }

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $booking = $this->bookings->create(
            $request->user(),
            Charger::findOrFail($request->integer('charger_id')),
            $request->start(),
            $request->end(),
            $request->input('vehicle'),
        );

        return $this->sendResponse(
            new BookingResource($booking->load('charger.station')),
            'Booking confirmed.',
            201
        );
    }

    public function show(Booking $booking): JsonResponse
    {
        $this->authorize('view', $booking);

        return $this->sendResponse(new BookingResource($booking->load(['charger.station', 'chargingSession', 'user'])));
    }

    public function cancel(Booking $booking): JsonResponse
    {
        $this->authorize('act', $booking);

        $booking = $this->bookings->cancel($booking);

        return $this->sendResponse(new BookingResource($booking->load('charger.station')), 'Booking cancelled.');
    }

    public function checkIn(Booking $booking): JsonResponse
    {
        $this->authorize('act', $booking);

        $session = $this->bookings->checkIn($booking);

        return $this->sendResponse(new SessionResource($session->load('charger.station')), 'Charging started.', 201);
    }
}
