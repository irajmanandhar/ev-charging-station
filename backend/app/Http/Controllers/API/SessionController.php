<?php

namespace App\Http\Controllers\API;

use App\Enums\SessionStatus;
use App\Http\Resources\SessionResource;
use App\Models\ChargingSession;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SessionController extends BaseController
{
    public function __construct(private readonly BookingService $bookings)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(SessionStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = $request->user()->chargingSessions()
            ->with('charger.station')
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('started_at');

        if (isset($data['page'])) {
            return $this->sendPage($query->paginate($data['per_page'] ?? 20), SessionResource::class);
        }

        return $this->sendResponse(SessionResource::collection($query->limit(100)->get()));
    }

    public function stop(ChargingSession $session): JsonResponse
    {
        $this->authorize('stop', $session);

        $session = $this->bookings->stop($session);

        return $this->sendResponse(new SessionResource($session->load('charger.station')), 'Charging stopped.');
    }
}
