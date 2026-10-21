<?php

namespace Tests\Unit;

use App\Models\Charger;
use App\Services\PricingService;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    private function make(float $kw = 60, float $price = 20): Charger
    {
        return (new Charger)->forceFill(['power_kw' => $kw, 'price_per_kwh' => $price]);
    }

    public function test_energy_applies_efficiency(): void
    {
        $this->assertSame(51.0, (new PricingService)->energyFor($this->make(), 60));
    }

    public function test_energy_is_capped_per_session(): void
    {
        $this->assertSame(80.0, (new PricingService)->energyFor($this->make(400), 240));
    }

    public function test_cost_rounds_to_two_places(): void
    {
        $this->assertSame(1020.0, (new PricingService)->costFor($this->make(), 51));
        $this->assertSame(3.33, (new PricingService)->costFor($this->make(60, 0.3333), 10));
    }
}
