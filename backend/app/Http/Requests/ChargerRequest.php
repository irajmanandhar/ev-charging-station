<?php

namespace App\Http\Requests;

use App\Enums\ChargerStatus;
use App\Enums\ConnectorType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChargerRequest extends FormRequest
{
    public function rules(): array
    {
        $req = $this->isMethod('post') ? 'required' : 'sometimes';
        $stationId = $this->route('station')?->id ?? $this->route('charger')?->station_id;

        return [
            'label' => [
                $req, 'string', 'max:20',
                Rule::unique('chargers', 'label')
                    ->where('station_id', $stationId)
                    ->ignore($this->route('charger')?->id),
            ],
            'connector_type' => [$req, Rule::enum(ConnectorType::class)],
            'power_kw' => [$req, 'numeric', 'min:3', 'max:400'],
            'price_per_kwh' => [$req, 'numeric', 'min:0', 'max:1000'],
            'status' => ['sometimes', Rule::enum(ChargerStatus::class)],
        ];
    }

    public function messages(): array
    {
        return ['label.unique' => 'This station already has a charger with that label.'];
    }
}
