<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Booking */
class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'starts_at' => $this->starts_at->toIso8601ZuluString(),
            'ends_at' => $this->ends_at->toIso8601ZuluString(),
            'duration_minutes' => (int) $this->starts_at->diffInMinutes($this->ends_at),
            'vehicle' => $this->vehicle,
            'estimated_kwh' => $this->estimated_kwh,
            'estimated_cost' => $this->estimated_cost,
            'cancelled_at' => $this->cancelled_at?->toIso8601ZuluString(),
            'cancellation_fee' => $this->cancellation_fee,
            'paid' => $this->whenLoaded('payments', fn () => $this->payments->contains('status', 'completed')),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'charger' => $this->whenLoaded('charger', fn () => [
                'id' => $this->charger->id,
                'label' => $this->charger->label,
                'connector_type' => $this->charger->connector_type->value,
                'power_kw' => $this->charger->power_kw,
                'price_per_kwh' => $this->charger->price_per_kwh,
                'station' => $this->charger->relationLoaded('station') ? [
                    'id' => $this->charger->station->id,
                    'name' => $this->charger->station->name,
                    'address' => $this->charger->station->address,
                    'latitude' => $this->charger->station->latitude,
                    'longitude' => $this->charger->station->longitude,
                    'timezone' => $this->charger->station->timezone,
                ] : null,
            ]),
            'session' => new SessionResource($this->whenLoaded('chargingSession')),
        ];
    }
}
