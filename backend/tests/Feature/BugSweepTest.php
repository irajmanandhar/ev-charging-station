<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\PendingUser;
use App\Models\Station;
use App\Models\User;
use App\Notifications\BookingCancelled;
use App\Notifications\BookingNoShow;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Regression tests for the whole-project bug review. */
class BugSweepTest extends TestCase
{
    use RefreshDatabase;

    private function book(User $user, int $chargerId, string $startsAt, int $minutes = 60)
    {
        return $this->actingAs($user)->postJson('/api/bookings', [
            'charger_id' => $chargerId, 'starts_at' => $startsAt, 'duration_minutes' => $minutes,
        ]);
    }

    public function test_csv_export_includes_every_row_past_the_first_page(): void
    {
        $operator = User::factory()->operator()->create();
        $charger = $this->charger([], ['operator_id' => $operator->id]);
        $driver = $this->driver();
        // 1200 rows inserted newest-first, so id order differs from starts_at order
        $rows = [];
        for ($i = 1200; $i >= 1; $i--) {
            $start = now()->subDays(1)->startOfDay()->addMinutes($i);
            $rows[] = [
                'reference' => 'EV-T'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'user_id' => $driver->id, 'charger_id' => $charger->id,
                'starts_at' => $start, 'ends_at' => $start->copy()->addMinute(), 'status' => 'completed',
                'estimated_kwh' => 1, 'estimated_cost' => 1, 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            Booking::insert($chunk);
        }

        $csv = $this->actingAs($operator)->get('/api/operator/export/bookings?from=2026-10-19&to=2026-10-21')->streamedContent();

        $this->assertSame(1200, substr_count($csv, 'EV-T'));
    }

    public function test_missed_booking_on_a_charger_the_operator_took_offline_is_cancelled_free_not_a_no_show(): void
    {
        Notification::fake();
        $driver = $this->driver();
        $charger = $this->charger();
        $id = $this->book($driver, $charger->id, $this->ktm('11:00'))->assertCreated()->json('data.id');
        $charger->update(['status' => 'maintenance']);

        Carbon::setTestNow(Carbon::parse($this->ktm('11:20')));
        $result = app(BookingService::class)->sweep();

        $booking = Booking::find($id);
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertEquals(0, $booking->cancellation_fee);
        $this->assertSame(0, $result['no_shows']);
        Notification::assertSentTo($driver, BookingCancelled::class);
        Notification::assertNotSentTo($driver, BookingNoShow::class);
    }

    public function test_station_hours_are_validated_against_the_stored_value_and_the_slot_grid(): void
    {
        $operator = User::factory()->operator()->create();
        $station = Station::factory()->for($operator, 'operator')->hours('07:00', '22:00')->create();
        $url = "/api/operator/stations/{$station->id}";

        $this->actingAs($operator)->patchJson($url, ['closes_at' => '06:00'])->assertStatus(422)->assertJsonValidationErrors('closes_at');
        $this->actingAs($operator)->patchJson($url, ['opens_at' => '23:00'])->assertStatus(422)->assertJsonValidationErrors('closes_at');
        $this->actingAs($operator)->patchJson($url, ['opens_at' => '06:10'])->assertStatus(422)->assertJsonValidationErrors('opens_at');
        $this->actingAs($operator)->patchJson($url, ['opens_at' => null])->assertStatus(422)->assertJsonValidationErrors('opens_at');
        $this->actingAs($operator)->patchJson($url, ['opens_at' => '06:15'])->assertOk();
        $this->actingAs($operator)->patchJson($url, ['is_24_hours' => true, 'opens_at' => null, 'closes_at' => null])->assertOk();
    }

    public function test_no_slot_runs_past_closing_time(): void
    {
        $charger = $this->charger([], ['opens_at' => '07:00', 'closes_at' => '21:50']);

        $slots = app(AvailabilityService::class)->forDay($charger->load('station'), '2026-10-22')['slots'];

        $last = end($slots);
        $this->assertSame(Carbon::parse('2026-10-22 21:30', 'Asia/Kathmandu')->utc()->toIso8601ZuluString(), $last['start']);
    }

    public function test_booking_start_must_carry_a_time_zone_and_align_in_station_local_time(): void
    {
        $charger = $this->charger();
        $driver = $this->driver();

        // no offset: ambiguous, rejected
        $this->book($driver, $charger->id, '2026-10-21 14:00:00')->assertStatus(422)->assertJsonValidationErrors('starts_at');
        // 14:00 Kathmandu = 08:15Z: aligned locally, accepted even though the UTC minute is 15
        $this->book($driver, $charger->id, '2026-10-21T08:15:00Z')->assertCreated();
        // 14:07 Kathmandu: not on the grid
        $this->book($this->driver(), $charger->id, '2026-10-21T08:22:00Z')->assertStatus(422)->assertJsonValidationErrors('starts_at');
    }

    public function test_slot_alignment_follows_local_time_with_a_30_minute_grid(): void
    {
        config(['ev.slot_minutes' => 30]);
        $charger = $this->charger();

        // 14:30 Kathmandu = 08:45Z; with UTC alignment this was always rejected
        $this->book($this->driver(), $charger->id, '2026-10-21T08:45:00Z')->assertCreated();
    }

    public function test_early_check_in_cannot_start_before_the_station_opens(): void
    {
        $driver = $this->driver();
        $charger = $this->charger();
        $id = $this->book($driver, $charger->id, $this->ktm('07:00', 1))->assertCreated()->json('data.id');

        Carbon::setTestNow(Carbon::parse($this->ktm('06:55', 1)));
        $this->actingAs($driver)->postJson("/api/bookings/{$id}/check-in")->assertStatus(422)->assertJsonPath('code', 'too_early');

        Carbon::setTestNow(Carbon::parse($this->ktm('07:00', 1)));
        $this->actingAs($driver)->postJson("/api/bookings/{$id}/check-in")->assertCreated();
    }

    public function test_free_minutes_now_is_a_bookable_amount(): void
    {
        $charger = $this->charger();
        // next booking starts at 10:45; now is 10:00 + 8 min = 37 free minutes -> 30
        $this->book($this->driver(), $charger->id, $this->ktm('10:45'))->assertCreated();
        Carbon::setTestNow(now()->addMinutes(8));

        $this->assertSame(30, app(AvailabilityService::class)->freeMinutesFromNow($charger->load('station')));

        Carbon::setTestNow(now()->addMinutes(10)); // 19 free minutes: below the 30 minute minimum
        $this->assertSame(0, app(AvailabilityService::class)->freeMinutesFromNow($charger->fresh()->load('station')));
    }

    public function test_second_pending_sign_up_with_a_taken_phone_gets_a_422_not_a_500(): void
    {
        User::factory()->create(['phone' => '9800000001']);
        PendingUser::create(['name' => 'Late', 'email' => 'late@example.com', 'phone' => '9800000001', 'password' => Hash::make('password123')]);
        config(['ev.expose_otp' => true]);
        $otp = $this->postJson('/api/resend-otp', ['email' => 'late@example.com'])->json('data.dev_otp');

        $this->postJson('/api/verify-email-otp', ['email' => 'late@example.com', 'otp' => $otp])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_nearest_stations_survive_the_result_cap(): void
    {
        $operator = User::factory()->operator()->create();
        // 210 stations far away (but inside the box) and one right here
        foreach (range(1, 210) as $i) {
            Station::factory()->for($operator, 'operator')->create(['name' => "Far $i", 'latitude' => 27.9, 'longitude' => 85.5]);
        }
        Station::factory()->for($operator, 'operator')->create(['name' => 'Right here', 'latitude' => 27.7, 'longitude' => 85.32]);

        $this->getJson('/api/stations?lat=27.7&lng=85.32&radius_km=50')->assertOk()->assertJsonPath('data.0.name', 'Right here');
    }
}
