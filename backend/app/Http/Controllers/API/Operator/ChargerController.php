<?php

namespace App\Http\Controllers\API\Operator;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\ChargerRequest;
use App\Http\Resources\ChargerResource;
use App\Models\Charger;
use App\Models\Station;
use Illuminate\Http\JsonResponse;

class ChargerController extends BaseController
{
    public function store(ChargerRequest $request, Station $station): JsonResponse
    {
        $this->authorize('manage', $station);

        $charger = $station->chargers()->create($request->validated());

        return $this->sendResponse(new ChargerResource($charger->load(Charger::liveStateRelations())), 'Charger added.', 201);
    }

    public function update(ChargerRequest $request, Charger $charger): JsonResponse
    {
        $this->authorize('manage', $charger->station);

        $charger->update($request->validated());

        return $this->sendResponse(new ChargerResource($charger->load(Charger::liveStateRelations())), 'Charger updated.');
    }

    public function destroy(Charger $charger): JsonResponse
    {
        $this->authorize('manage', $charger->station);

        // Deleting cascades to bookings and sessions (billing history), so only unused chargers can go.
        if ($charger->bookings()->exists() || $charger->chargingSessions()->exists()) {
            return $this->sendError('This charger has booking history. Take it offline instead.', 409);
        }

        $charger->delete();

        return $this->sendResponse(null, 'Charger removed.');
    }
}
