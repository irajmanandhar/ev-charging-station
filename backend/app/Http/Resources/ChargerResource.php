<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Charger */
class ChargerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $liveLoaded = $this->relationLoaded('activeSession') && $this->relationLoaded('currentBooking');

        return [
            'id' => $this->id,
            'station_id' => $this->station_id,
            'label' => $this->label,
            'connector_type' => $this->connector_type->value,
            'power_kw' => $this->power_kw,
            'price_per_kwh' => $this->price_per_kwh,
            'status' => $this->status->value,
            'live_status' => $this->when($liveLoaded, fn () => $this->liveStatus()->value),
            'busy_until' => $this->when($liveLoaded, function () {
                return $this->activeSession?->booking?->ends_at?->toIso8601ZuluString()
                    ?? $this->currentBooking?->ends_at?->toIso8601ZuluString();
            }),
        ];
    }
}
