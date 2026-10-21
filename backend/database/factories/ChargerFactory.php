<?php

namespace Database\Factories;

use App\Enums\ChargerStatus;
use App\Enums\ConnectorType;
use App\Models\Station;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Charger>
 */
class ChargerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'station_id' => Station::factory(),
            'label' => strtoupper(fake()->unique()->bothify('?#')),
            'connector_type' => ConnectorType::CCS2,
            'power_kw' => 60,
            'price_per_kwh' => 20,
            'status' => ChargerStatus::Available,
        ];
    }
}
