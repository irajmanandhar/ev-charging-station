<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\SessionStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Booking;
use App\Models\ChargingSession;
use App\Models\Station;
use App\Models\User;
use App\Models\UserOtp;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialUser;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    private function bookingFor(User $user, string $time = '11:00', int $minutes = 60, array $chargerAttrs = []): Booking
    {
        $charger = $this->charger($chargerAttrs);
        $id = $this->actingAs($user)->postJson('/api/bookings', [
            'charger_id' => $charger->id,
            'starts_at' => $this->ktm($time),
            'duration_minutes' => $minutes,
        ])->assertCreated()->json('data.id');

        return Booking::findOrFail($id);
    }

    // ---- booking flow -------------------------------------------------------------------------

    public function test_failed_walk_up_start_does_not_leave_a_booking_behind(): void
    {
        $charger = $this->charger();
        // A session with no booking is occupying the charger, so check-in must fail.
        ChargingSession::create([
            'user_id' => $this->driver()->id, 'charger_id' => $charger->id,
            'started_at' => now(), 'status' => SessionStatus::Active,
        ]);
        $driver = $this->driver();

        $this->actingAs($driver)->postJson("/api/chargers/{$charger->id}/start", ['duration_minutes' => 30])
            ->assertStatus(409)->assertJsonPath('errors.code', 'charger_busy');

        $this->assertSame(0, Booking::where('user_id', $driver->id)->count());
    }

    public function test_cancel_rereads_status_so_a_concurrent_check_in_wins(): void
    {
        $booking = $this->bookingFor($this->driver());
        $stale = Booking::find($booking->id); // still "confirmed" in memory
        Booking::whereKey($booking->id)->update(['status' => BookingStatus::Active->value]);

        $this->expectException(DomainRuleException::class);
        app(BookingService::class)->cancel($stale);
    }

    public function test_sweep_marks_no_shows_and_is_idempotent(): void
    {
        $booking = $this->bookingFor($this->driver(), '11:00');
        Carbon::setTestNow(Carbon::parse($this->ktm('11:20')));

        $first = app(BookingService::class)->sweep();
        $second = app(BookingService::class)->sweep();

        $this->assertSame(1, $first['no_shows']);
        $this->assertSame(['no_shows' => 0, 'auto_stopped' => 0], $second);
        $this->assertSame(BookingStatus::NoShow, $booking->fresh()->status);
    }

    public function test_sweep_stops_overrunning_sessions_once(): void
    {
        $driver = $this->driver();
        $booking = $this->bookingFor($driver, '11:00', 60);
        Carbon::setTestNow(Carbon::parse($this->ktm('11:00')));
        $this->actingAs($driver)->postJson("/api/bookings/{$booking->id}/check-in")->assertCreated();

        Carbon::setTestNow(Carbon::parse($this->ktm('12:05')));
        $first = app(BookingService::class)->sweep();
        $second = app(BookingService::class)->sweep();

        $this->assertSame(1, $first['auto_stopped']);
        $this->assertSame(0, $second['auto_stopped']);
        $session = ChargingSession::firstOrFail();
        $this->assertSame(SessionStatus::Completed, $session->status);
        $this->assertEquals(51, $session->energy_kwh); // 60 kW * 1 h * 0.85
    }

    public function test_back_to_back_bookings_do_not_conflict(): void
    {
        $charger = $this->charger();
        foreach (['11:00', '12:00'] as $time) {
            $this->actingAs($this->driver())->postJson('/api/bookings', [
                'charger_id' => $charger->id, 'starts_at' => $this->ktm($time), 'duration_minutes' => 60,
            ])->assertCreated();
        }
    }

    public function test_same_user_cannot_hold_overlapping_bookings_on_two_chargers(): void
    {
        $driver = $this->driver();
        $first = $this->bookingFor($driver, '11:00');
        $other = $this->charger(['label' => 'B1']);

        $this->actingAs($driver)->postJson('/api/bookings', [
            'charger_id' => $other->id, 'starts_at' => $this->ktm('11:30'), 'duration_minutes' => 60,
        ])->assertStatus(422)->assertJsonPath('errors.code', 'user_overlap');

        $this->assertSame(1, Booking::where('user_id', $driver->id)->count());
        $this->assertNotNull($first->id);
    }

    // ---- operator / admin rules ---------------------------------------------------------------

    public function test_operator_cannot_lift_a_status_an_admin_imposed(): void
    {
        $admin = User::factory()->admin()->create();
        $operator = User::factory()->operator()->create();
        $station = Station::factory()->for($operator, 'operator')->create();

        $this->actingAs($admin)->patchJson("/api/admin/stations/{$station->id}/status", ['status' => 'inactive'])->assertOk();
        $this->actingAs($operator)->patchJson("/api/operator/stations/{$station->id}", ['status' => 'active', 'name' => 'Renamed'])->assertOk();

        $station->refresh();
        $this->assertSame('inactive', $station->status->value);
        $this->assertSame('Renamed', $station->name);

        // once the admin restores it, the operator controls status again
        $this->actingAs($admin)->patchJson("/api/admin/stations/{$station->id}/status", ['status' => 'active'])->assertOk();
        $this->actingAs($operator)->patchJson("/api/operator/stations/{$station->id}", ['status' => 'maintenance'])->assertOk();
        $this->assertSame('maintenance', $station->fresh()->status->value);
    }

    public function test_stations_and_chargers_with_history_cannot_be_deleted(): void
    {
        $operator = User::factory()->operator()->create();
        $charger = $this->charger([], ['operator_id' => $operator->id]);
        Booking::create([
            'user_id' => $this->driver()->id, 'charger_id' => $charger->id,
            'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour(),
            'status' => BookingStatus::Completed, 'estimated_kwh' => 1, 'estimated_cost' => 1,
        ]);

        $this->actingAs($operator)->deleteJson("/api/operator/chargers/{$charger->id}")->assertStatus(409);
        $this->actingAs($operator)->deleteJson("/api/operator/stations/{$charger->station_id}")->assertStatus(409);
    }

    // ---- policies -----------------------------------------------------------------------------

    public function test_booking_and_session_policy_matrix(): void
    {
        $owner = $this->driver();
        $stranger = $this->driver();
        $admin = User::factory()->admin()->create();
        $operator = User::factory()->operator()->create();
        $otherOperator = User::factory()->operator()->create();
        $charger = $this->charger([], ['operator_id' => $operator->id]);

        $id = $this->actingAs($owner)->postJson('/api/bookings', [
            'charger_id' => $charger->id, 'starts_at' => $this->ktm('11:00'), 'duration_minutes' => 60,
        ])->json('data.id');

        $this->actingAs($stranger)->getJson("/api/bookings/{$id}")->assertForbidden();
        $this->actingAs($otherOperator)->getJson("/api/bookings/{$id}")->assertForbidden();
        $this->actingAs($operator)->getJson("/api/bookings/{$id}")->assertOk();
        $this->actingAs($admin)->getJson("/api/bookings/{$id}")->assertOk();

        // cancel and check-in are the driver's own actions, even for admins and operators
        $this->actingAs($stranger)->postJson("/api/bookings/{$id}/cancel")->assertForbidden();
        $this->actingAs($admin)->postJson("/api/bookings/{$id}/cancel")->assertForbidden();
        $this->actingAs($operator)->postJson("/api/bookings/{$id}/check-in")->assertForbidden();

        Carbon::setTestNow(Carbon::parse($this->ktm('11:00')));
        $sessionId = $this->actingAs($owner)->postJson("/api/bookings/{$id}/check-in")->json('data.id');

        $this->actingAs($stranger)->postJson("/api/sessions/{$sessionId}/stop")->assertForbidden();
        $this->actingAs($otherOperator)->postJson("/api/sessions/{$sessionId}/stop")->assertForbidden();
        $this->actingAs($operator)->postJson("/api/sessions/{$sessionId}/stop")->assertOk();
    }

    public function test_guests_and_drivers_are_kept_out_of_role_routes(): void
    {
        $this->getJson('/api/bookings')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->actingAs($this->driver())->getJson('/api/operator/stations')->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->actingAs($this->driver())->getJson('/api/admin/users')->assertForbidden();
    }

    // ---- auth hardening -----------------------------------------------------------------------

    public function test_otp_is_stored_hashed_and_burns_after_too_many_wrong_guesses(): void
    {
        config(['ev.expose_otp' => true]);
        $plain = $this->postJson('/api/register', [
            'name' => 'Otp Test', 'email' => 'otp@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->json('data.dev_otp');

        $this->assertNotSame($plain, UserOtp::first()->otp);

        $wrong = $plain === '000000' ? '111111' : '000000';
        for ($i = 0; $i < UserOtp::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/verify-email-otp', ['email' => 'otp@example.com', 'otp' => $wrong])->assertStatus(422);
            $this->app['cache']->flush(); // keep the HTTP throttle out of this test
        }

        // The correct code no longer works once the guess budget is spent.
        $this->postJson('/api/verify-email-otp', ['email' => 'otp@example.com', 'otp' => $plain])->assertStatus(422);
        $this->assertNull(User::where('email', 'otp@example.com')->first());
    }

    public function test_login_is_throttled_per_email(): void
    {
        $user = $this->driver();

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
        }

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
    }

    public function test_tokens_expire(): void
    {
        config(['sanctum.expiration' => 60]);
        $user = $this->driver();
        $token = $user->createToken('web')->plainTextToken;

        $this->withToken($token)->getJson('/api/me')->assertOk();

        Carbon::setTestNow(now()->addMinutes(61));
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_resend_otp_does_not_reveal_pending_sign_ups(): void
    {
        $this->postJson('/api/resend-otp', ['email' => 'ghost@example.com'])->assertOk();
    }

    public function test_security_headers_are_sent(): void
    {
        $this->getJson('/api/stations')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_social_login_never_links_an_existing_account_without_a_verified_provider_email(): void
    {
        $victim = $this->driver();
        $social = (new SocialUser)->map(['id' => 'fb-1', 'name' => 'Mallory', 'email' => $victim->email]);
        $social->user = ['email_verified' => false];
        Socialite::shouldReceive('driver->stateless->user')->andReturn($social);

        $this->getJson('/api/auth/facebook/callback')->assertStatus(409);
        $this->assertDatabaseCount('auth_providers', 0);
    }
}
