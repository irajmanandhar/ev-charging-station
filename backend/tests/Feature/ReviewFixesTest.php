<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\PendingUser;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserOtp;
use App\Notifications\BookingNoShow;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Support\SocialState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialUser;
use Tests\TestCase;

/** Regression tests for the issues found in the final security and code review. */
class ReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private function paidBooking(): array
    {
        config(['payments.driver' => 'khalti', 'payments.khalti.secret_key' => 'k']);
        Http::fake([
            '*/epayment/initiate/' => Http::sequence()
                ->push(['pidx' => 'P1', 'payment_url' => 'https://x'])
                ->push(['pidx' => 'P2', 'payment_url' => 'https://x']),
        ]);
        $driver = $this->driver();
        $id = $this->actingAs($driver)->postJson('/api/bookings', [
            'charger_id' => $this->charger()->id, 'starts_at' => $this->ktm('16:00'), 'duration_minutes' => 60,
        ])->json('data.id');

        return [$driver, $id];
    }

    private function pay(User $driver, int $bookingId): int
    {
        return $this->actingAs($driver)->postJson("/api/bookings/{$bookingId}/pay")->assertCreated()->json('data.payment_id');
    }

    private function providerSays(string $status, int $amountPaisa = 102000): void
    {
        Http::fake(['*/epayment/lookup/' => Http::response(['status' => $status, 'total_amount' => $amountPaisa])]);
    }

    // ---- payments ---------------------------------------------------------------------------

    public function test_payment_that_completes_after_cancel_is_flagged_for_refund(): void
    {
        [$driver, $id] = $this->paidBooking();
        $paymentId = $this->pay($driver, $id);
        $this->actingAs($driver)->postJson("/api/bookings/{$id}/cancel")->assertOk();

        $this->providerSays('Completed');
        $this->actingAs($driver)->postJson("/api/payments/{$paymentId}/confirm")
            ->assertOk()->assertJsonPath('data.status', Payment::REFUND_PENDING);

        $payload = Payment::find($paymentId)->payload;
        $this->assertSame('booking_not_active', $payload['refund_reason']);
        $this->assertEquals(1020, $payload['refund_owed']);
    }

    public function test_second_completed_payment_for_one_booking_is_flagged_not_kept(): void
    {
        [$driver, $id] = $this->paidBooking();
        $first = $this->pay($driver, $id);
        $second = $this->pay($driver, $id); // two Pay clicks before either completes

        $this->providerSays('Completed');
        $this->actingAs($driver)->postJson("/api/payments/{$first}/confirm")->assertJsonPath('data.status', 'completed');
        $this->actingAs($driver)->postJson("/api/payments/{$second}/confirm")->assertJsonPath('data.status', Payment::REFUND_PENDING);

        $this->assertSame('duplicate_payment', Payment::find($second)->payload['refund_reason']);
    }

    public function test_a_pending_provider_status_is_not_treated_as_failure_and_can_be_retried(): void
    {
        [$driver, $id] = $this->paidBooking();
        $paymentId = $this->pay($driver, $id);

        // the provider's answer changes between the two checks (first stub wins in Http::fake, so use a sequence)
        Http::fake(['*/epayment/lookup/' => Http::sequence()
            ->push(['status' => 'Pending', 'total_amount' => 102000])
            ->push(['status' => 'Completed', 'total_amount' => 102000])]);
        $this->actingAs($driver)->postJson("/api/payments/{$paymentId}/confirm")->assertJsonPath('data.status', Payment::INITIATED);

        $this->actingAs($driver)->postJson("/api/payments/{$paymentId}/confirm")->assertJsonPath('data.status', Payment::COMPLETED);
    }

    public function test_reconcile_settles_payments_the_browser_never_confirmed(): void
    {
        [$driver, $id] = $this->paidBooking();
        $paymentId = $this->pay($driver, $id);
        Carbon::setTestNow(now()->addMinutes(5));

        $this->providerSays('Completed');
        $this->assertSame(1, app(PaymentService::class)->reconcile());
        $this->assertSame(Payment::COMPLETED, Payment::find($paymentId)->status);

        // nothing left to do, and abandoned payments older than a day are left alone
        $this->assertSame(0, app(PaymentService::class)->reconcile());
    }

    public function test_reconcile_does_nothing_when_payments_are_off(): void
    {
        config(['payments.driver' => 'none']);
        $this->assertSame(0, app(PaymentService::class)->reconcile());
    }

    public function test_no_show_on_a_paid_booking_is_flagged_for_the_operator(): void
    {
        Notification::fake();
        [$driver, $id] = $this->paidBooking();
        $paymentId = $this->pay($driver, $id);
        $this->providerSays('Completed');
        $this->actingAs($driver)->postJson("/api/payments/{$paymentId}/confirm")->assertJsonPath('data.status', 'completed');

        Carbon::setTestNow(Carbon::parse($this->ktm('16:20')));
        app(BookingService::class)->sweep();

        $this->assertSame(Payment::REFUND_PENDING, Payment::find($paymentId)->status);
        $this->assertSame('no_show', Payment::find($paymentId)->payload['refund_reason']);
        Notification::assertSentTo($driver, BookingNoShow::class);
    }

    public function test_sweep_emails_only_real_no_shows(): void
    {
        Notification::fake();
        $driver = $this->driver();
        $charger = $this->charger();
        $b = Booking::create([
            'user_id' => $driver->id, 'charger_id' => $charger->id, 'starts_at' => now()->subMinutes(30),
            'ends_at' => now()->addMinutes(30), 'status' => BookingStatus::Active, 'estimated_kwh' => 1, 'estimated_cost' => 1,
        ]);

        app(BookingService::class)->sweep();

        $this->assertSame(BookingStatus::Active, $b->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_pay_goes_through_the_policy(): void
    {
        [$driver, $id] = $this->paidBooking();
        $paymentId = $this->pay($driver, $id);

        $this->actingAs($this->driver())->postJson("/api/payments/{$paymentId}/confirm")->assertForbidden();
    }

    // ---- auth -------------------------------------------------------------------------------

    public function test_social_callback_needs_a_valid_signed_state(): void
    {
        $this->getJson('/api/auth/google/callback')->assertStatus(422);
        $this->getJson('/api/auth/google/callback?state=1.2.forged')->assertStatus(422);

        $social = (new SocialUser)->map(['id' => 'g-1', 'name' => 'Gita', 'email' => 'gita@example.com']);
        Socialite::shouldReceive('driver->stateless->user')->andReturn($social);

        $this->getJson('/api/auth/google/callback?state='.SocialState::issue())
            ->assertOk()->assertJsonPath('data.user.email', 'gita@example.com');

        // expired state is rejected
        $old = SocialState::issue();
        Carbon::setTestNow(now()->addMinutes(11));
        $this->assertFalse(SocialState::valid($old));
    }

    public function test_registering_again_cannot_overwrite_a_pending_sign_up(): void
    {
        config(['ev.expose_otp' => true]);
        $this->postJson('/api/register', [
            'name' => 'Real Owner', 'email' => 'victim@example.com',
            'password' => 'owner-password', 'password_confirmation' => 'owner-password',
        ])->assertOk();
        $originalHash = PendingUser::first()->password;

        $this->postJson('/api/register', [
            'name' => 'Attacker', 'email' => 'victim@example.com',
            'password' => 'attacker-pass', 'password_confirmation' => 'attacker-pass',
        ])->assertOk();

        $this->assertSame('Real Owner', PendingUser::first()->name);
        $this->assertSame($originalHash, PendingUser::first()->password);

        // after the code has expired a fresh sign-up replaces it
        Carbon::setTestNow(now()->addMinutes(11));
        $this->postJson('/api/register', [
            'name' => 'Same Owner Again', 'email' => 'victim@example.com',
            'password' => 'new-password1', 'password_confirmation' => 'new-password1',
        ])->assertOk();
        $this->assertSame('Same Owner Again', PendingUser::first()->name);
    }

    public function test_requesting_a_new_code_does_not_reset_the_guess_counter(): void
    {
        $user = User::factory()->create(['email' => 'ram@example.com']);
        UserOtp::generateOtp($user->email, 'password_reset');
        $wrong = fn () => UserOtp::verifyOtp($user->email, '000000', 'password_reset');

        $wrong();
        $wrong();
        UserOtp::generateOtp($user->email, 'password_reset'); // "resend"

        $this->assertSame(2, UserOtp::where('email', $user->email)->first()->attempts);
    }

    public function test_otp_attempt_counter_stops_at_the_limit(): void
    {
        $user = User::factory()->create(['email' => 'sita@example.com']);
        UserOtp::generateOtp($user->email, 'password_reset');

        foreach (range(1, 12) as $i) {
            UserOtp::verifyOtp($user->email, '000000', 'password_reset');
        }

        $this->assertSame(config('ev.otp_max_attempts'), UserOtp::where('email', $user->email)->first()->attempts);
    }

    public function test_social_routes_are_rate_limited_publicly_not_per_email(): void
    {
        $this->getJson('/api/auth/nope/redirect')->assertStatus(400);
    }
}
