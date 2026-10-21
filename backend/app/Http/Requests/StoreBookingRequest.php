<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class StoreBookingRequest extends FormRequest
{
    public function rules(): array
    {
        $slot = (int) config('ev.slot_minutes');

        return [
            'charger_id' => ['required', 'integer', 'exists:chargers,id'],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => [
                'required', 'integer',
                'min:'.config('ev.min_booking_minutes'),
                'max:'.config('ev.max_booking_minutes'),
                'multiple_of:'.$slot,
            ],
            'vehicle' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $slot = (int) config('ev.slot_minutes');
            if ($this->start()->minute % $slot !== 0 || $this->start()->second !== 0) {
                $validator->errors()->add('starts_at', "Start time must be on a {$slot}-minute boundary.");
            }
        }];
    }

    public function start(): Carbon
    {
        return Carbon::parse($this->input('starts_at'))->utc();
    }

    public function end(): Carbon
    {
        return $this->start()->addMinutes((int) $this->input('duration_minutes'));
    }
}
