<?php

namespace Database\Factories;

use App\Enums\StationStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Station>
 */
class StationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'operator_id' => User::factory()->operator(),
            'name' => fake()->unique()->city().' Charging Hub',
            'description' => fake()->sentence(12),
            'address' => fake()->streetAddress(),
            'city' => 'Kathmandu',
            'latitude' => fake()->latitude(27.66, 27.75),
            'longitude' => fake()->longitude(85.28, 85.37),
            'timezone' => 'Asia/Kathmandu',
            'is_24_hours' => true,
            'opens_at' => null,
            'closes_at' => null,
            'amenities' => ['Parking', 'Restroom'],
            'status' => StationStatus::Active,
        ];
    }

    public function hours(string $opens, string $closes): static
    {
        return $this->state(fn () => ['is_24_hours' => false, 'opens_at' => $opens, 'closes_at' => $closes]);
    }
}
