<?php

namespace App\Http\Controllers\API;

use App\Http\Requests\StartNowRequest;
use App\Http\Resources\SessionResource;
use App\Models\Charger;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChargerController extends BaseController
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly BookingService $bookings,
        private readonly PricingService $pricing,
    ) {
    }

    /** Slot grid for a day. Query: date=YYYY-MM-DD (station-local, defaults to today). */
    public function availability(Request $request, Charger $charger): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $charger->load('station');

        $date = $request->input('date') ?? now($charger->station->timezone)->toDateString();

        return $this->sendResponse([
            ...$this->availability->forDay($charger, $date),
            'free_minutes_now' => $this->availability->freeMinutesFromNow($charger),
        ]);
    }

    /** Price estimate. Query: minutes */
    public function quote(Request $request, Charger $charger): JsonResponse
    {
        $data = $request->validate(['minutes' => ['required', 'integer', 'min:1', 'max:600']]);

        return $this->sendResponse([
            ...$this->pricing->quote($charger, (int) $data['minutes']),
            'currency' => config('ev.currency'),
        ]);
    }

    /** Walk-up: reserve from now and start charging immediately. */
    public function startNow(StartNowRequest $request, Charger $charger): JsonResponse
    {
        $session = $this->bookings->startNow(
            $request->user(),
            $charger,
            (int) $request->input('duration_minutes'),
            $request->input('vehicle'),
        );

        return $this->sendResponse(
            new SessionResource($session->load('charger.station')),
            'Charging started.',
            201
        );
    }
}
