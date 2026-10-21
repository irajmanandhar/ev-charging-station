<?php

namespace App\Http\Controllers\API\Admin;

use App\Enums\StationStatus;
use App\Http\Controllers\API\BaseController;
use App\Http\Resources\StationResource;
use App\Models\AdminLog;
use App\Models\Charger;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StationController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::enum(StationStatus::class)],
        ]);

        $stations = Station::query()
            ->with(['operator:id,name', 'chargers' => fn ($q) => $q->with(Charger::liveStateRelations())])
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where('name', 'like', "%{$term}%"))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('name')
            ->get();

        return $this->sendResponse(StationResource::collection($stations));
    }

    public function updateStatus(Request $request, Station $station): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(StationStatus::class)]]);

        $from = $station->status->value;
        $station->update([
            'status' => $data['status'],
            'status_locked' => $data['status'] !== StationStatus::Active->value,
        ]);

        AdminLog::record($request->user(), 'station.status_changed', $station, ['from' => $from, 'to' => $data['status']]);

        return $this->sendResponse(new StationResource($station->load('operator:id,name')), 'Station status updated.');
    }
}
