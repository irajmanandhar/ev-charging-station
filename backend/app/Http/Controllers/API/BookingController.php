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
use Illuminate\Validation\Rule;

class BookingController extends BaseController
{
    public function __construct(private readonly BookingService $bookings)
    {
    }

    /** Query: scope=upcoming|past|all (default all) */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['nullable', 'in:upcoming,past,all'],
            'status' => ['nullable', Rule::enum(BookingStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $scope = $data['scope'] ?? 'all';

        $query = $request->user()->bookings()
            ->with(['charger.station', 'chargingSession', 'payments'])
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($scope === 'upcoming', fn ($q) => $q
                ->whereIn('status', BookingStatus::blocking())
                ->orderBy('starts_at'))
            ->when($scope === 'past', fn ($q) => $q
                ->whereNotIn('status', BookingStatus::blocking())
                ->orderByDesc('starts_at'))
            ->when($scope === 'all', fn ($q) => $q->orderByDesc('starts_at'));

        // Without ?page the first 100 come back as before; with it, a paginated list plus meta.
        if (isset($data['page'])) {
            return $this->sendPage($query->paginate($data['per_page'] ?? 20), BookingResource::class);
        }

        return $this->sendResponse(BookingResource::collection($query->limit(100)->get()));
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
