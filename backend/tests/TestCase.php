<?php

namespace Tests;

use App\Models\Charger;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Wednesday 10:00 Kathmandu time (04:15 UTC). Fixed so slot maths is predictable.
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00', 'Asia/Kathmandu')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A charger at a station open 07:00–22:00 Kathmandu time. */
    protected function charger(array $chargerAttrs = [], array $stationAttrs = []): Charger
    {
        $station = Station::factory()->hours('07:00', '22:00')->create($stationAttrs);

        return Charger::factory()->for($station)->create(array_merge([
            'label' => 'A1',
            'power_kw' => 60,
            'price_per_kwh' => 20,
        ], $chargerAttrs));
    }

    /** ISO timestamp for a Kathmandu wall-clock time on the frozen "today" (+ $days). */
    protected function ktm(string $time, int $days = 0): string
    {
        return Carbon::parse('2026-10-21 '.$time, 'Asia/Kathmandu')->addDays($days)->utc()->toIso8601ZuluString();
    }

    protected function driver(): User
    {
        return User::factory()->create();
    }
}
