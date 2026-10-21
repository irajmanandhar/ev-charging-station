<?php

namespace App\Services;

use App\Models\Charger;

class PricingService
{
    /** Estimated energy delivered (kWh) for a charger over a number of minutes. */
    public function energyFor(Charger $charger, float $minutes): float
    {
        $kwh = $charger->power_kw * ($minutes / 60) * (float) config('ev.charge_efficiency');

        return round(min($kwh, (float) config('ev.max_session_kwh')), 2);
    }

    public function costFor(Charger $charger, float $kwh): float
    {
        return round($kwh * $charger->price_per_kwh, 2);
    }

    /** @return array{kwh: float, cost: float} */
    public function quote(Charger $charger, float $minutes): array
    {
        $kwh = $this->energyFor($charger, $minutes);

        return ['kwh' => $kwh, 'cost' => $this->costFor($charger, $kwh)];
    }
}
