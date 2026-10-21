<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\ChargingSession;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ChargingSessionTest extends TestCase
{
    use RefreshDatabase;

    private function bookAt(string $time, int $minutes = 60): array
    {
        $charger = $this->charger();
        $driver = $this->driver();
        $id = $this->actingAs($driver)->postJson('/api/bookings', [
            'charger_id' => $charger->id,
            'starts_at' => $this->ktm($time),
            'duration_minutes' => $minutes,
        ])->assertCreated()->json('data.id');

        return [$driver, $charger, $id];
    }

    public function test_check_in_window(): void
    {
        [$driver, , $id] = $this->bookAt('11:00');

        // 10:00 — too early (window opens 10:50)
        $this->actingAs($driver)->postJson("/api/bookings/{$id}/check-in")
            ->assertStatus(422)->assertJsonPath('errors.code', 'too_early');

        Carbon::setTestNow(Carbon::parse($this->ktm('10:52')));
        $this->actingAs($driver)->postJson("/api/bookings/{$id}/check-in")
            ->assertCreated()->assertJsonPath('data.status', 'active');

        $this->assertSame('active', Booking::find($id)->status->value);
    }

    public function test_missing_the_grace_window_blocks_check_in(): void
    {
        [$driver, , $id] = $this->bookAt('11:00');
        Carbon::setTestNow(Carbon::parse($this->ktm('11:20')));

        $this->actingAs($driver)->postJson("/api/bookings/{$id}/check-in")
            ->assertStatus(422)->assertJsonPath('errors.code', 'too_late');
    }

    public function test_stopping_meters_energy_and_frees_the_rest_of_the_slot(): void
    {
        [$driver, $charger, $id] = $this->bookAt('11:00', 120);

        Carbon::setTestNow(Carbon::parse($this->ktm('11:00')));
        $sessionId = $this->actingAs($driver)->postJson("/api/bookings/{$id}/check-in")->json('data.id');

        Carbon::setTestNow(Carbon::parse($this->ktm('11:30')));
        $res = $this->actingAs($driver)->postJson("/api/sessions/{$sessionId}/stop")->assertOk()
            ->assertJsonPath('data.status', 'completed');

        // 60 kW * 0.5 h * 0.85 = 25.5 kWh, NPR 20/kWh = 510
        $this->assertEquals(25.5, $res->json('data.energy_kwh'));
        $this->assertEquals(510, $res->json('data.cost'));

        $booking = Booking::find($id);
        $this->assertSame('completed', $booking->status->value);
        $this->assertTrue($booking->ends_at->eq(Carbon::parse($this->ktm('11:30'))));

        // 11:30–13:00 is bookable again
        $this->actingAs($this->driver())->postJson('/api/bookings', [
            'charger_id' => $charger->id,
            'starts_at' => $this->ktm('11:30'),
            'duration_minutes' => 60,
        ])->assertCreated();
    }

    public function test_walk_up_start_now(): void
    {
        $charger = $this->charger();
        $driver = $this->driver();

        $this->actingAs($driver)->postJson("/api/chargers/{$charger->id}/start", ['duration_minutes' => 45])
            ->assertCreated()->assertJsonPath('data.status', 'active');

        $this->getJson("/api/stations/{$charger->station_id}")
            ->assertJsonPath('data.chargers.0.live_status', 'occupied');

        // Someone else cannot start on the same charger now.
        $this->actingAs($this->driver())->postJson("/api/chargers/{$charger->id}/start", ['duration_minutes' => 30])
            ->assertStatus(409);
    }

    public function test_walk_up_is_blocked_when_a_booking_starts_soon(): void
    {
        [, $charger] = $this->bookAt('10:30');

        $this->actingAs($this->driver())->postJson("/api/chargers/{$charger->id}/start", ['duration_minutes' => 60])
            ->assertStatus(409)->assertJsonPath('errors.code', 'slot_taken');
    }

    public function test_sweep_marks_no_shows_and_auto_stops_overrunning_sessions(): void
    {
        [$driverA, , $missed] = $this->bookAt('11:00', 60);
        [$driverB, , $charging] = $this->bookAt('10:30', 30);

        Carbon::setTestNow(Carbon::parse($this->ktm('10:30')));
        $this->actingAs($driverB)->postJson("/api/bookings/{$charging}/check-in")->assertCreated();

        Carbon::setTestNow(Carbon::parse($this->ktm('11:20')));
        $result = app(BookingService::class)->sweep();

        $this->assertSame(['no_shows' => 1, 'auto_stopped' => 1], $result);
        $this->assertSame('no_show', Booking::find($missed)->status->value);

        $session = ChargingSession::first();
        $this->assertSame('completed', $session->status->value);
        // Stopped at the slot end (11:00), not at sweep time.
        $this->assertTrue($session->ended_at->eq(Carbon::parse($this->ktm('11:00'))));
        $this->assertEquals(25.5, $session->energy_kwh);
    }

    public function test_operator_can_stop_a_session_at_their_station_but_strangers_cannot(): void
    {
        $charger = $this->charger();
        $driver = $this->driver();
        $sessionId = $this->actingAs($driver)->postJson("/api/chargers/{$charger->id}/start", ['duration_minutes' => 60])
            ->json('data.id');

        $this->actingAs($this->driver())->postJson("/api/sessions/{$sessionId}/stop")->assertForbidden();
        $this->actingAs($charger->station->operator)->postJson("/api/sessions/{$sessionId}/stop")->assertOk();
    }
}
