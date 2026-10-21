<?php

namespace App\Http\Resources;

use App\Enums\LiveStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Station */
class StationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $chargersLoaded = $this->relationLoaded('chargers');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'address' => $this->address,
            'city' => $this->city,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'timezone' => $this->timezone,
            'is_24_hours' => $this->is_24_hours,
            'opens_at' => $this->opens_at ? substr($this->opens_at, 0, 5) : null,
            'closes_at' => $this->closes_at ? substr($this->closes_at, 0, 5) : null,
            'amenities' => $this->amenities ?? [],
            'status' => $this->status->value,
            'distance_km' => $this->when(isset($this->distance_km), fn () => $this->distance_km),
            'operator' => $this->whenLoaded('operator', fn () => [
                'id' => $this->operator->id,
                'name' => $this->operator->name,
            ]),
            'chargers' => ChargerResource::collection($this->whenLoaded('chargers')),
            'summary' => $this->when($chargersLoaded, function () {
                $chargers = $this->chargers;
                $live = $chargers->first()?->relationLoaded('activeSession')
                    ? $chargers->map(fn ($c) => $c->liveStatus()->value)
                    : collect();

                return [
                    'chargers' => $chargers->count(),
                    'available' => $live->filter(fn ($s) => $s === LiveStatus::Available->value)->count(),
                    'max_power_kw' => $chargers->max('power_kw'),
                    'min_price_per_kwh' => $chargers->min('price_per_kwh'),
                    'connector_types' => $chargers->map(fn ($c) => $c->connector_type->value)->unique()->values(),
                ];
            }),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
