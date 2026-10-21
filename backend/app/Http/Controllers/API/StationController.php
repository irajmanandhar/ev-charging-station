<?php

namespace App\Http\Controllers\API;

use App\Enums\ConnectorType;
use App\Enums\LiveStatus;
use App\Http\Resources\StationResource;
use App\Models\Charger;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StationController extends BaseController
{
    /**
     * Station finder.
     * Query: lat, lng, radius_km (default 25), connector, min_power, available_now, q
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:200'],
            'connector' => ['nullable', Rule::enum(ConnectorType::class)],
            'min_power' => ['nullable', 'numeric', 'min:0'],
            'available_now' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:80'],
        ]);

        $hasPoint = isset($filters['lat'], $filters['lng']);
        $radius = (float) ($filters['radius_km'] ?? 25);

        $query = Station::query()
            ->active()
            ->with(['chargers' => fn ($q) => $q->with(Charger::liveStateRelations())])
            ->when($hasPoint, fn ($q) => $q->withinBox((float) $filters['lat'], (float) $filters['lng'], $radius))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
                    ->orWhere('address', 'like', "%{$term}%")
                    ->orWhere('city', 'like', "%{$term}%"));
            })
            ->when($filters['connector'] ?? null, fn ($q, $c) => $q->whereHas('chargers', fn ($w) => $w->where('connector_type', $c)))
            ->when($filters['min_power'] ?? null, fn ($q, $p) => $q->whereHas('chargers', fn ($w) => $w->where('power_kw', '>=', $p)));

        // Order in SQL before the cap, so the 200 returned are the nearest (or first by name).
        if ($hasPoint) {
            $lat = (float) $filters['lat'];
            $lng = (float) $filters['lng'];
            $k = cos(deg2rad($lat)) ** 2; // shrink longitude degrees by latitude
            $query->orderByRaw('((latitude - ?) * (latitude - ?)) + ? * ((longitude - ?) * (longitude - ?))', [$lat, $lat, $k, $lng, $lng]);
        } else {
            $query->orderBy('name');
        }
        $stations = $query->limit(200)->get();

        if ($hasPoint) {
            $stations = $stations
                ->each(fn (Station $s) => $s->distance_km = $s->distanceKmTo((float) $filters['lat'], (float) $filters['lng']))
                ->filter(fn (Station $s) => $s->distance_km <= $radius)
                ->sortBy('distance_km');
        } else {
            $stations = $stations->sortBy('name');
        }

        if ($request->boolean('available_now')) {
            $stations = $stations->filter(fn (Station $s) => $s->chargers->contains(
                fn (Charger $c) => $c->liveStatus() === LiveStatus::Available
                    && (empty($filters['connector']) || $c->connector_type->value === $filters['connector'])
            ));
        }

        return $this->sendResponse(StationResource::collection($stations->values()));
    }

    public function show(Station $station): JsonResponse
    {
        abort_if($station->status->value === 'inactive', 404);

        $station->load([
            'operator:id,name',
            'chargers' => fn ($q) => $q->with(Charger::liveStateRelations()),
        ]);

        return $this->sendResponse(new StationResource($station));
    }
}
