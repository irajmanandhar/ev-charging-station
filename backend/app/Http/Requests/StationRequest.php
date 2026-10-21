<?php

namespace App\Http\Requests;

use App\Enums\StationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StationRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $req = $creating ? 'required' : 'sometimes';

        // Hours are required unless the station is open 24h (on create, or when the flag is being turned off).
        $needsHours = Rule::requiredIf(fn () => $creating
            ? ! $this->boolean('is_24_hours')
            : ($this->has('is_24_hours') && ! $this->boolean('is_24_hours')));

        return [
            'name' => [$req, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'latitude' => [$req, 'numeric', 'between:-90,90'],
            'longitude' => [$req, 'numeric', 'between:-180,180'],
            'timezone' => ['sometimes', 'timezone:all'],
            'is_24_hours' => ['sometimes', 'boolean'],
            'opens_at' => ['nullable', $needsHours, 'date_format:H:i'],
            'closes_at' => ['nullable', $needsHours, 'date_format:H:i', Rule::when($this->filled('opens_at'), 'after:opens_at')],
            'amenities' => ['nullable', 'array', 'max:12'],
            'amenities.*' => ['string', 'max:40'],
            'status' => ['sometimes', Rule::enum(StationStatus::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'closes_at.after' => 'Closing time must be after opening time.',
            'opens_at.required' => 'Set opening hours or mark the station as open 24 hours.',
            'closes_at.required' => 'Set opening hours or mark the station as open 24 hours.',
        ];
    }
}
