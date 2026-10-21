<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Http\Controllers\API\Operator\ExportController;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\BookingCancelled;
use App\Notifications\BookingConfirmed;
use App\Notifications\BookingNoShow;
use App\Notifications\BookingReminder;
use App\Services\BookingService;
use App\Services\Payments\EsewaGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function book(User $user, int $chargerId, string $time, int $minutes = 60)
    {
        return $this->actingAs($user)->postJson('/api/bookings', [
            'charger_id' => $chargerId, 'starts_at' => $this->ktm($time), 'duration_minutes' => $minutes,
        ]);
    }

    // ---- notifications ------------------------------------------------------------------------

    public function test_confirmation_reminder_and_no_show_emails(): void
    {
        Notification::fake();
        $driver = $this->driver();
        $charger = $this->charger();
        $id = $this->book($driver, $charger->id, '11:00')->assertCreated()->json('data.id');
        Notification::assertSentTo($driver, BookingConfirmed::class);

        // 20 minutes before the start: inside the 30 minute reminder window, once only
        Carbon::setTestNow(Carbon::parse($this->ktm('10:40')));
        $this->assertSame(1, app(BookingService::class)->sendReminders());
        $this->assertSame(0, app(BookingService::class)->sendReminders());
        Notification::assertSentToTimes($driver, BookingReminder::class, 1);

        Carbon::setTestNow(Carbon::parse($this->ktm('11:20')));
        app(BookingService::class)->sweep();
        Notification::assertSentTo($driver, BookingNoShow::class);
        $this->assertSame(BookingStatus::NoShow, Booking::find($id)->status);
    }

    public function test_reminder_is_not_sent_for_far_away_or_cancelled_bookings(): void
    {
        Notification::fake();
        $driver = $this->driver();
        $charger = $this->charger();
        $this->book($driver, $charger->id, '16:00')->assertCreated();
        $cancelled = $this->book($driver, $charger->id, '12:00')->json('data.id');
        $this->actingAs($driver)->postJson("/api/bookings/{$cancelled}/cancel")->assertOk();

        Carbon::setTestNow(Carbon::parse($this->ktm('11:45')));
        $this->assertSame(0, app(BookingService::class)->sendReminders());
    }

    // ---- cancellation rules -------------------------------------------------------------------

    public function test_early_cancellation_is_free_and_late_cancellation_keeps_a_fee(): void
    {
        Notification::fake();
        $driver = $this->driver();
        $charger = $this->charger();

        $early = $this->book($driver, $charger->id, '15:00')->json('data.id'); // 5 h away
        $this->actingAs($driver)->postJson("/api/bookings/{$early}/cancel")
            ->assertOk()->assertJsonPath('data.cancellation_fee', 0);

        $late = $this->book($driver, $charger->id, '10:30')->json('data.id'); // 30 min away, inside 60 min window
        $res = $this->actingAs($driver)->postJson("/api/bookings/{$late}/cancel")->assertOk();
        // 51 kWh * NPR 20 = 1020; 20% = 204
        $this->assertEquals(204, $res->json('data.cancellation_fee'));
        Notification::assertSentToTimes($driver, BookingCancelled::class, 2);
    }

    // ---- payments -----------------------------------------------------------------------------

    public function test_payments_are_off_by_default(): void
    {
        $driver = $this->driver();
        $id = $this->book($driver, $this->charger()->id, '14:00')->json('data.id');

        $this->actingAs($driver)->postJson("/api/bookings/{$id}/pay")
            ->assertStatus(422)->assertJsonPath('errors.code', 'payments_disabled');
        $this->assertSame(0, Payment::count());
    }

    public function test_khalti_payment_is_verified_with_the_provider_and_refund_is_flagged_on_cancel(): void
    {
        config(['payments.driver' => 'khalti', 'payments.khalti.secret_key' => 'test_secret']);
        Http::fake([
            '*/epayment/initiate/' => Http::response(['pidx' => 'PIDX1', 'payment_url' => 'https://test-pay.khalti.com/?pidx=PIDX1']),
            '*/epayment/lookup/' => Http::response(['status' => 'Completed', 'total_amount' => 102000]),
        ]);
        $driver = $this->driver();
        $id = $this->book($driver, $this->charger()->id, '16:00')->json('data.id');

        $start = $this->actingAs($driver)->postJson("/api/bookings/{$id}/pay")->assertCreated()
            ->assertJsonPath('data.provider', 'khalti')
            ->assertJsonPath('data.redirect_url', 'https://test-pay.khalti.com/?pidx=PIDX1');
        $paymentId = $start->json('data.payment_id');
        Http::assertSent(fn ($r) => $r['amount'] === 102000 && $r->hasHeader('Authorization', 'Key test_secret'));

        $this->actingAs($driver)->postJson("/api/payments/{$paymentId}/confirm", ['pidx' => 'PIDX1'])
            ->assertOk()->assertJsonPath('data.status', 'completed');
        // idempotent
        $this->actingAs($driver)->postJson("/api/payments/{$paymentId}/confirm")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->actingAs($driver)->getJson('/api/bookings')->assertJsonPath('data.0.paid', true);

        // already paid
        $this->actingAs($driver)->postJson("/api/bookings/{$id}/pay")->assertStatus(409);

        // Khalti has no refund API, so the refund is queued for the operator rather than silently dropped.
        $this->actingAs($driver)->postJson("/api/bookings/{$id}/cancel")->assertOk();
        $this->assertSame(Payment::REFUND_PENDING, Payment::find($paymentId)->status);
    }

    public function test_unconfirmed_khalti_payment_fails_and_amount_mismatch_is_rejected(): void
    {
        config(['payments.driver' => 'khalti', 'payments.khalti.secret_key' => 'k']);
        Http::fake([
            '*/epayment/initiate/' => Http::response(['pidx' => 'P2', 'payment_url' => 'https://x']),
            '*/epayment/lookup/' => Http::response(['status' => 'Completed', 'total_amount' => 100]), // wrong amount
        ]);
        $driver = $this->driver();
        $id = $this->book($driver, $this->charger()->id, '16:00')->json('data.id');
        $paymentId = $this->actingAs($driver)->postJson("/api/bookings/{$id}/pay")->json('data.payment_id');

        $this->actingAs($driver)->postJson("/api/payments/{$paymentId}/confirm")->assertOk()->assertJsonPath('data.status', 'failed');
    }

    public function test_only_the_owner_can_pay_or_confirm(): void
    {
        config(['payments.driver' => 'khalti']);
        Http::fake(['*' => Http::response(['pidx' => 'P3', 'payment_url' => 'https://x'])]);
        $owner = $this->driver();
        $id = $this->book($owner, $this->charger()->id, '16:00')->json('data.id');
        $paymentId = $this->actingAs($owner)->postJson("/api/bookings/{$id}/pay")->json('data.payment_id');

        $stranger = $this->driver();
        $this->actingAs($stranger)->postJson("/api/bookings/{$id}/pay")->assertForbidden();
        $this->actingAs($stranger)->postJson("/api/payments/{$paymentId}/confirm")->assertForbidden();
    }

    public function test_esewa_returns_a_signed_form(): void
    {
        config(['payments.driver' => 'esewa', 'payments.esewa.secret_key' => 'sandbox-secret', 'payments.esewa.product_code' => 'EPAYTEST']);
        $driver = $this->driver();
        $id = $this->book($driver, $this->charger()->id, '16:00')->json('data.id');

        $res = $this->actingAs($driver)->postJson("/api/bookings/{$id}/pay")->assertCreated();
        $form = $res->json('data.form');

        $this->assertSame('1020.00', $form['total_amount']);
        $this->assertSame(
            (new EsewaGateway)->sign('1020.00', $form['transaction_uuid']),
            $form['signature']
        );
        $this->assertSame('total_amount,transaction_uuid,product_code', $form['signed_field_names']);
    }

    // ---- pagination and filters ---------------------------------------------------------------

    public function test_bookings_paginate_and_filter_by_status(): void
    {
        $driver = $this->driver();
        $charger = $this->charger();
        foreach (['11:00', '12:00', '13:00'] as $t) {
            $this->book($driver, $charger->id, $t)->assertCreated();
        }
        $cancel = Booking::orderBy('id')->first();
        $this->actingAs($driver)->postJson("/api/bookings/{$cancel->id}/cancel")->assertOk();

        $this->actingAs($driver)->getJson('/api/bookings?page=1&per_page=2')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);

        $this->actingAs($driver)->getJson('/api/bookings?status=cancelled')->assertJsonCount(1, 'data');
        $this->actingAs($driver)->getJson('/api/bookings?status=nonsense')->assertStatus(422);
        $this->actingAs($driver)->getJson('/api/bookings?page=1&per_page=500')->assertStatus(422);
        // no ?page keeps the plain list
        $this->actingAs($driver)->getJson('/api/bookings')->assertJsonMissingPath('meta');
    }

    // ---- operator CSV export ------------------------------------------------------------------

    public function test_operator_exports_only_their_own_bookings_as_csv(): void
    {
        $operator = User::factory()->operator()->create();
        $other = User::factory()->operator()->create();
        $mine = $this->charger(['label' => 'A1'], ['operator_id' => $operator->id, 'name' => 'My Station']);
        $theirs = $this->charger(['label' => 'B1'], ['operator_id' => $other->id, 'name' => 'Their Station']);
        $driver = User::factory()->create(['name' => '=cmd|calc']);
        $this->book($driver, $mine->id, '14:00')->assertCreated();
        $this->book($this->driver(), $theirs->id, '14:00')->assertCreated();

        $res = $this->actingAs($operator)->get('/api/operator/export/bookings?from=2026-10-21&to=2026-10-21');
        $res->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $res->streamedContent();

        $this->assertStringContainsString('My Station', $csv);
        $this->assertStringNotContainsString('Their Station', $csv);
        $this->assertStringContainsString("'=cmd|calc", $csv); // formula neutralised
    }

    public function test_export_needs_operator_role_and_a_sane_range(): void
    {
        $this->actingAs($this->driver())->getJson('/api/operator/export/bookings?from=2026-10-01&to=2026-10-02')->assertForbidden();

        $operator = User::factory()->operator()->create();
        $this->actingAs($operator)->getJson('/api/operator/export/bookings?from=2026-01-01&to=2026-10-02')->assertStatus(422);
        $this->actingAs($operator)->getJson('/api/operator/export/bookings?from=2026-10-05&to=2026-10-02')->assertStatus(422);
    }

    public function test_public_config_reflects_the_payment_driver(): void
    {
        $this->getJson('/api/config')->assertOk()->assertJsonPath('data.payments_enabled', false);

        // env('PAYMENTS_DRIVER=null') yields PHP null: still off, never "enabled".
        config(['payments.driver' => null]);
        $this->getJson('/api/config')->assertJsonPath('data.payments_enabled', false)->assertJsonPath('data.payments_provider', null);

        config(['payments.driver' => 'khalti']);
        $this->getJson('/api/config')->assertJsonPath('data.payments_enabled', true)->assertJsonPath('data.currency', 'NPR');
    }

    public function test_set_role_command_promotes_a_user_and_rejects_bad_input(): void
    {
        $user = User::factory()->create(['email' => 'first@example.com']);

        $this->artisan('user:set-role', ['email' => 'First@Example.com', 'role' => 'admin'])->assertSuccessful();
        $this->assertTrue($user->fresh()->isAdmin());

        $this->artisan('user:set-role', ['email' => 'first@example.com', 'role' => 'root'])->assertFailed();
        $this->artisan('user:set-role', ['email' => 'nobody@example.com', 'role' => 'admin'])->assertFailed();
    }

    public function test_csv_cell_neutralises_formula_prefixes(): void
    {
        foreach (['=1+1', '+1', '-1', '@x'] as $bad) {
            $this->assertSame("'".$bad, ExportController::cell($bad));
        }
        $this->assertSame('Normal', ExportController::cell('Normal'));
        $this->assertSame(5, ExportController::cell(5));
    }
}
