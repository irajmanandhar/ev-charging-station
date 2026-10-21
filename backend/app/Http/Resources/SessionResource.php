<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ChargingSession */
class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'status' => $this->status->value,
            'started_at' => $this->started_at->toIso8601ZuluString(),
            'ended_at' => $this->ended_at?->toIso8601ZuluString(),
            'energy_kwh' => $this->energy_kwh,
            'cost' => $this->cost,
            'charger' => $this->whenLoaded('charger', fn () => [
                'id' => $this->charger->id,
                'label' => $this->charger->label,
                'connector_type' => $this->charger->connector_type->value,
                'power_kw' => $this->charger->power_kw,
                'price_per_kwh' => $this->charger->price_per_kwh,
                'station' => $this->charger->relationLoaded('station') ? [
                    'id' => $this->charger->station->id,
                    'name' => $this->charger->station->name,
                ] : null,
            ]),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
        ];
    }
}
