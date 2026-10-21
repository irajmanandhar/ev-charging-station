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
            'closes_at' => ['nullable', $needsHours, 'date_format:H:i'],
            'amenities' => ['nullable', 'array', 'max:12'],
            'amenities.*' => ['string', 'max:40'],
            'status' => ['sometimes', Rule::enum(StationStatus::class)],
        ];
    }

    /** Checks that need the stored station too (a PATCH may send only one of the two times). */
    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $station = $this->route('station');
            $is24 = $this->has('is_24_hours') ? $this->boolean('is_24_hours') : (bool) $station?->is_24_hours;
            if ($is24) {
                return;
            }

            $opens = $this->has('opens_at') ? $this->input('opens_at') : $station?->opens_at;
            $closes = $this->has('closes_at') ? $this->input('closes_at') : $station?->closes_at;
            if (! $opens || ! $closes) {
                $validator->errors()->add($opens ? 'closes_at' : 'opens_at', 'Set opening hours or mark the station as open 24 hours.');

                return;
            }

            $opens = substr((string) $opens, 0, 5);
            $closes = substr((string) $closes, 0, 5);
            if ($closes <= $opens) {
                $validator->errors()->add('closes_at', 'Closing time must be after opening time.');
            }

            $slot = (int) config('ev.slot_minutes');
            foreach (['opens_at' => $opens, 'closes_at' => $closes] as $field => $value) {
                if ((int) substr($value, 3, 2) % $slot !== 0) {
                    $validator->errors()->add($field, "Times go in {$slot}-minute steps (e.g. 07:00, 07:15).");
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'opens_at.required' => 'Set opening hours or mark the station as open 24 hours.',
            'closes_at.required' => 'Set opening hours or mark the station as open 24 hours.',
        ];
    }
}
