<?php

namespace App\Http\Controllers\API;

use App\Http\Resources\SessionResource;
use App\Models\ChargingSession;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends BaseController
{
    public function __construct(private readonly BookingService $bookings)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $sessions = $request->user()->chargingSessions()
            ->with('charger.station')
            ->orderByDesc('started_at')
            ->limit(100)
            ->get();

        return $this->sendResponse(SessionResource::collection($sessions));
    }

    public function stop(ChargingSession $session): JsonResponse
    {
        $this->authorize('stop', $session);

        $session = $this->bookings->stop($session);

        return $this->sendResponse(new SessionResource($session->load('charger.station')), 'Charging stopped.');
    }
}
