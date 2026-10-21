<?php

namespace App\Http\Requests;

use App\Models\Charger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class StoreBookingRequest extends FormRequest
{
    public function messages(): array
    {
        return ['starts_at.regex' => 'Start time must include a time zone, e.g. 2026-10-22T04:15:00Z.'];
    }

    public function rules(): array
    {
        $slot = (int) config('ev.slot_minutes');

        return [
            'charger_id' => ['required', 'integer', 'exists:chargers,id'],
            // An explicit offset (Z or +05:45) so the instant is unambiguous.
            'starts_at' => ['required', 'date', 'regex:/(Z|[+-]\d{2}:?\d{2})$/'],
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
            // The grid is drawn in the station's local time (Kathmandu is UTC+5:45), so align there.
            $tz = Charger::with('station')->find($this->integer('charger_id'))?->station?->timezone ?? 'UTC';
            $local = $this->start()->setTimezone($tz);
            if ($local->minute % $slot !== 0 || $local->second !== 0) {
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
