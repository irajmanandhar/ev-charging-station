<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role->value,
            'email_verified' => $this->email_verified_at !== null,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'stations_count' => $this->whenCounted('stations'),
            'bookings_count' => $this->whenCounted('bookings'),
        ];
    }
}
