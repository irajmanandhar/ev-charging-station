<?php

namespace Tests\Feature;

use App\Models\Charger;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationFinderTest extends TestCase
{
    use RefreshDatabase;

    public function test_nearby_stations_are_sorted_by_distance_within_radius(): void
    {
        $near = Station::factory()->create(['name' => 'Near', 'latitude' => 27.7150, 'longitude' => 85.3120]);
        $mid = Station::factory()->create(['name' => 'Mid', 'latitude' => 27.6900, 'longitude' => 85.3400]);
        Station::factory()->create(['name' => 'Pokhara', 'latitude' => 28.2096, 'longitude' => 83.9585]);
        Station::factory()->create(['name' => 'Closed', 'latitude' => 27.7151, 'longitude' => 85.3121, 'status' => 'inactive']);

        $res = $this->getJson('/api/stations?lat=27.7172&lng=85.3240&radius_km=10')->assertOk();

        $this->assertSame(['Near', 'Mid'], collect($res->json('data'))->pluck('name')->all());
        $this->assertLessThan(2, $res->json('data.0.distance_km'));
    }

    public function test_filters_by_connector_and_power(): void
    {
        $fast = Station::factory()->create(['name' => 'Fast']);
        Charger::factory()->for($fast)->create(['connector_type' => 'CCS2', 'power_kw' => 120]);
        $slow = Station::factory()->create(['name' => 'Slow']);
        Charger::factory()->for($slow)->create(['connector_type' => 'Type2', 'power_kw' => 22]);

        $this->getJson('/api/stations?connector=Type2')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Slow');
        $this->getJson('/api/stations?min_power=100')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Fast');
    }

    public function test_live_status_reflects_sessions_reservations_and_maintenance(): void
    {
        $station = Station::factory()->create();
        $occupied = Charger::factory()->for($station)->create(['label' => 'A1']);
        $reserved = Charger::factory()->for($station)->create(['label' => 'A2']);
        $free = Charger::factory()->for($station)->create(['label' => 'B1']);
        Charger::factory()->for($station)->create(['label' => 'B2', 'status' => 'maintenance']);

        $this->actingAs($this->driver())->postJson("/api/chargers/{$occupied->id}/start", ['duration_minutes' => 60])->assertCreated();
        $this->actingAs($this->driver())->postJson('/api/bookings', [
            'charger_id' => $reserved->id,
            'starts_at' => $this->ktm('10:15'),
            'duration_minutes' => 30,
        ])->assertCreated();

        $res = $this->getJson("/api/stations/{$station->id}")->assertOk();
        $statuses = collect($res->json('data.chargers'))->pluck('live_status', 'label')->all();

        $this->assertSame(['A1' => 'occupied', 'A2' => 'reserved', 'B1' => 'available', 'B2' => 'offline'], $statuses);
        $this->assertSame(1, $res->json('data.summary.available'));

        // available_now keeps the station because B1 is free; drop B1 and it disappears.
        $this->getJson('/api/stations?available_now=1')->assertJsonCount(1, 'data');
        $free->update(['status' => 'offline']);
        $this->getJson('/api/stations?available_now=1')->assertJsonCount(0, 'data');
    }

    public function test_availability_grid_marks_booked_and_past_slots(): void
    {
        $charger = $this->charger();
        $this->actingAs($this->driver())->postJson('/api/bookings', [
            'charger_id' => $charger->id,
            'starts_at' => $this->ktm('14:00'),
            'duration_minutes' => 60,
        ])->assertCreated();

        $res = $this->getJson("/api/chargers/{$charger->id}/availability?date=2026-10-21")->assertOk();
        $slots = collect($res->json('data.slots'))->keyBy('start');

        $this->assertCount(60, $slots); // 07:00–22:00 in 15-minute slots
        $this->assertFalse($slots[$this->ktm('08:00')]['free']); // past
        $this->assertFalse($slots[$this->ktm('14:15')]['free']); // booked
        $this->assertTrue($slots[$this->ktm('15:00')]['free']);
        $this->assertSame(240, $res->json('data.free_minutes_now'));
    }
}
