<?php

namespace App\Http\Controllers\API\Operator;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\StationRequest;
use App\Http\Resources\StationResource;
use App\Models\Charger;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationController extends BaseController
{
    /** Operators see their own stations; admins see every station. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $stations = Station::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('operator_id', $user->id))
            ->with(['chargers' => fn ($q) => $q->with(Charger::liveStateRelations()), 'operator:id,name'])
            ->orderBy('name')
            ->get();

        return $this->sendResponse(StationResource::collection($stations));
    }

    public function store(StationRequest $request): JsonResponse
    {
        $this->authorize('create', Station::class);

        $data = $request->validated();
        $data['is_24_hours'] = $request->boolean('is_24_hours');
        if ($data['is_24_hours']) {
            $data['opens_at'] = null;
            $data['closes_at'] = null;
        }

        $station = $request->user()->stations()->create($data);

        return $this->sendResponse(new StationResource($station->load('chargers')), 'Station created.', 201);
    }

    public function show(Station $station): JsonResponse
    {
        $this->authorize('manage', $station);

        $station->load(['chargers' => fn ($q) => $q->with(Charger::liveStateRelations()), 'operator:id,name']);

        return $this->sendResponse(new StationResource($station));
    }

    public function update(StationRequest $request, Station $station): JsonResponse
    {
        $this->authorize('manage', $station);

        $data = $request->validated();
        if ($request->boolean('is_24_hours')) {
            $data['opens_at'] = null;
            $data['closes_at'] = null;
        }
        // A status an admin imposed stays until an admin lifts it.
        if ($station->status_locked && ! $request->user()->isAdmin()) {
            unset($data['status']);
        }
        $station->update($data);

        return $this->sendResponse(
            new StationResource($station->load(['chargers' => fn ($q) => $q->with(Charger::liveStateRelations())])),
            'Station updated.'
        );
    }

    public function destroy(Station $station): JsonResponse
    {
        $this->authorize('manage', $station);

        // Deleting cascades to bookings and sessions (billing history), so only empty stations can go.
        if ($station->bookings()->exists() || $station->chargingSessions()->exists()) {
            return $this->sendError('This station has booking history. Set it to maintenance instead.', 409);
        }

        $station->delete();

        return $this->sendResponse(null, 'Station deleted.');
    }
}
