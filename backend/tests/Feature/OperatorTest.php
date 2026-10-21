<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_drivers_cannot_reach_the_operator_portal(): void
    {
        $this->actingAs($this->driver())->getJson('/api/operator/stations')->assertForbidden();
    }

    public function test_operator_creates_a_station_and_chargers(): void
    {
        $operator = User::factory()->operator()->create();

        $stationId = $this->actingAs($operator)->postJson('/api/operator/stations', [
            'name' => 'New Road Charging',
            'latitude' => 27.7041,
            'longitude' => 85.3077,
            'is_24_hours' => false,
            'opens_at' => '08:00',
            'closes_at' => '20:00',
            'amenities' => ['Parking'],
        ])->assertCreated()->assertJsonPath('data.opens_at', '08:00')->json('data.id');

        $this->actingAs($operator)->postJson("/api/operator/stations/{$stationId}/chargers", [
            'label' => 'A1', 'connector_type' => 'CCS2', 'power_kw' => 60, 'price_per_kwh' => 22,
        ])->assertCreated()->assertJsonPath('data.live_status', 'available');

        $this->actingAs($operator)->postJson("/api/operator/stations/{$stationId}/chargers", [
            'label' => 'A1', 'connector_type' => 'Type2', 'power_kw' => 22, 'price_per_kwh' => 15,
        ])->assertStatus(422)->assertJsonValidationErrors('label');
    }

    public function test_station_hours_are_validated(): void
    {
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)->postJson('/api/operator/stations', [
            'name' => 'Bad hours', 'latitude' => 27.7, 'longitude' => 85.3,
            'is_24_hours' => false, 'opens_at' => '20:00', 'closes_at' => '08:00',
        ])->assertStatus(422)->assertJsonValidationErrors('closes_at');

        // Leaving out the 24h flag still requires hours.
        $this->actingAs($operator)->postJson('/api/operator/stations', [
            'name' => 'No hours', 'latitude' => 27.7, 'longitude' => 85.3,
        ])->assertStatus(422)->assertJsonValidationErrors(['opens_at', 'closes_at']);

        $this->actingAs($operator)->postJson('/api/operator/stations', [
            'name' => 'Always open', 'latitude' => 27.7, 'longitude' => 85.3, 'is_24_hours' => true,
        ])->assertCreated()->assertJsonPath('data.is_24_hours', true)->assertJsonPath('data.opens_at', null);
    }

    public function test_operator_cannot_manage_someone_elses_station(): void
    {
        $station = Station::factory()->create();
        $other = User::factory()->operator()->create();

        $this->actingAs($other)->patchJson("/api/operator/stations/{$station->id}", ['name' => 'Mine now'])->assertForbidden();
        $this->actingAs($other)->getJson("/api/operator/metrics?station_id={$station->id}")->assertForbidden();
        $this->actingAs($station->operator)->patchJson("/api/operator/stations/{$station->id}", ['name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed');
    }

    public function test_charger_with_upcoming_bookings_cannot_be_deleted(): void
    {
        $charger = $this->charger();
        $this->actingAs($this->driver())->postJson('/api/bookings', [
            'charger_id' => $charger->id, 'starts_at' => $this->ktm('14:00'), 'duration_minutes' => 60,
        ])->assertCreated();

        $this->actingAs($charger->station->operator)->deleteJson("/api/operator/chargers/{$charger->id}")->assertStatus(409);
    }

    public function test_metrics_cover_sessions_revenue_and_utilization(): void
    {
        $charger = $this->charger();
        $operator = $charger->station->operator;

        $sessionId = $this->actingAs($this->driver())
            ->postJson("/api/chargers/{$charger->id}/start", ['duration_minutes' => 60])->json('data.id');
        $this->travel(30)->minutes();
        $this->actingAs($operator)->postJson("/api/sessions/{$sessionId}/stop")->assertOk();

        $res = $this->actingAs($operator)->getJson('/api/operator/metrics?days=7')->assertOk();

        $this->assertSame(1, $res->json('data.totals.sessions'));
        $this->assertEquals(25.5, $res->json('data.totals.energy_kwh'));
        $this->assertEquals(510, $res->json('data.totals.revenue'));
        $this->assertCount(7, $res->json('data.daily'));
        $this->assertCount(24, $res->json('data.hourly'));
        $this->assertSame(1, $res->json('data.hourly.10.sessions'));
        $this->assertGreaterThan(0, $res->json('data.totals.utilization'));
        $this->assertSame('CCS2', $res->json('data.by_connector.0.connector_type'));
    }

    public function test_operator_booking_list_shows_todays_bookings(): void
    {
        $charger = $this->charger();
        $this->actingAs($this->driver())->postJson('/api/bookings', [
            'charger_id' => $charger->id, 'starts_at' => $this->ktm('14:00'), 'duration_minutes' => 60,
        ])->assertCreated();

        $this->actingAs($charger->station->operator)->getJson('/api/operator/bookings')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.charger.label', 'A1');
    }
}
