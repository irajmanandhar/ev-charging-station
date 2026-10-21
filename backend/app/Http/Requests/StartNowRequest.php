<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartNowRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'duration_minutes' => [
                'required', 'integer',
                'min:'.config('ev.min_booking_minutes'),
                'max:'.config('ev.max_booking_minutes'),
                'multiple_of:'.config('ev.slot_minutes'),
            ],
            'vehicle' => ['nullable', 'string', 'max:80'],
        ];
    }
}
