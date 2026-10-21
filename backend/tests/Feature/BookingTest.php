<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    private function book(User $user, int $chargerId, string $startsAt, int $minutes = 60)
    {
        return $this->actingAs($user)->postJson('/api/bookings', [
            'charger_id' => $chargerId,
            'starts_at' => $startsAt,
            'duration_minutes' => $minutes,
        ]);
    }

    public function test_driver_can_book_a_free_slot_with_a_price_estimate(): void
    {
        $charger = $this->charger();

        $res = $this->book($this->driver(), $charger->id, $this->ktm('14:00'), 60)
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.duration_minutes', 60);

        // 60 kW * 1 h * 0.85 efficiency = 51 kWh at NPR 20 = 1020
        $this->assertEquals(51, $res->json('data.estimated_kwh'));
        $this->assertEquals(1020, $res->json('data.estimated_cost'));
    }

    public function test_overlapping_booking_on_same_charger_is_rejected(): void
    {
        $charger = $this->charger();
        $this->book($this->driver(), $charger->id, $this->ktm('14:00'), 60)->assertCreated();

        $this->book($this->driver(), $charger->id, $this->ktm('14:30'), 60)
            ->assertStatus(409)
            ->assertJsonPath('errors.code', 'slot_taken');

        $this->assertSame(1, Booking::count());
    }

    public function test_back_to_back_bookings_are_allowed(): void
    {
        $charger = $this->charger();
        $this->book($this->driver(), $charger->id, $this->ktm('14:00'), 60)->assertCreated();
        $this->book($this->driver(), $charger->id, $this->ktm('15:00'), 60)->assertCreated();
        $this->book($this->driver(), $charger->id, $this->ktm('13:00'), 60)->assertCreated();
    }

    public function test_cancelled_bookings_free_the_slot(): void
    {
        $charger = $this->charger();
        $driver = $this->driver();
        $id = $this->book($driver, $charger->id, $this->ktm('14:00'))->json('data.id');

        $this->actingAs($driver)->postJson("/api/bookings/{$id}/cancel")->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->book($this->driver(), $charger->id, $this->ktm('14:00'))->assertCreated();
    }

    public function test_booking_must_fit_opening_hours(): void
    {
        $charger = $this->charger(); // open 07:00–22:00

        $this->book($this->driver(), $charger->id, $this->ktm('21:30'), 60)
            ->assertStatus(422)->assertJsonPath('errors.code', 'station_closed');

        $this->book($this->driver(), $charger->id, $this->ktm('21:00'), 60)->assertCreated();
    }

    public function test_rejects_past_unaligned_too_long_and_too_far_ahead(): void
    {
        $charger = $this->charger();
        $driver = $this->driver();

        $this->book($driver, $charger->id, $this->ktm('08:00'))->assertStatus(422)->assertJsonPath('errors.code', 'in_past');
        $this->book($driver, $charger->id, $this->ktm('14:10'))->assertStatus(422)->assertJsonValidationErrors('starts_at');
        $this->book($driver, $charger->id, $this->ktm('14:00'), 300)->assertStatus(422)->assertJsonValidationErrors('duration_minutes');
        $this->book($driver, $charger->id, $this->ktm('14:00'), 50)->assertStatus(422)->assertJsonValidationErrors('duration_minutes');
        $this->book($driver, $charger->id, $this->ktm('14:00', 9))->assertStatus(422)->assertJsonPath('errors.code', 'too_far_ahead');
    }

    public function test_driver_cannot_double_book_themselves_across_chargers(): void
    {
        $driver = $this->driver();
        $a = $this->charger();
        $b = $this->charger(['label' => 'B1']);

        $this->book($driver, $a->id, $this->ktm('14:00'))->assertCreated();
        $this->book($driver, $b->id, $this->ktm('14:30'))->assertStatus(422)->assertJsonPath('errors.code', 'user_overlap');
    }

    public function test_upcoming_booking_limit(): void
    {
        $driver = $this->driver();
        $charger = $this->charger();

        foreach (['11:00', '13:00', '15:00'] as $t) {
            $this->book($driver, $charger->id, $this->ktm($t))->assertCreated();
        }
        $this->book($driver, $charger->id, $this->ktm('17:00'))->assertStatus(422)->assertJsonPath('errors.code', 'too_many_bookings');
    }

    public function test_offline_charger_cannot_be_booked(): void
    {
        $charger = $this->charger(['status' => 'maintenance']);

        $this->book($this->driver(), $charger->id, $this->ktm('14:00'))
            ->assertStatus(422)->assertJsonPath('errors.code', 'charger_unavailable');
    }

    public function test_only_the_owner_can_cancel_or_check_in(): void
    {
        $charger = $this->charger();
        $id = $this->book($this->driver(), $charger->id, $this->ktm('14:00'))->json('data.id');
        $other = $this->driver();

        $this->actingAs($other)->postJson("/api/bookings/{$id}/cancel")->assertForbidden();
        $this->actingAs($other)->postJson("/api/bookings/{$id}/check-in")->assertForbidden();
        $this->actingAs($other)->getJson("/api/bookings/{$id}")->assertForbidden();
    }

    public function test_guest_cannot_book(): void
    {
        $charger = $this->charger();
        $this->postJson('/api/bookings', ['charger_id' => $charger->id])->assertUnauthorized()
            ->assertJsonPath('success', false);
    }
}
