<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\BookingStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(private readonly PaymentGateway $gateway)
    {
    }

    /**
     * Start paying for a booking's estimated cost.
     *
     * @return array{payment: Payment, redirect_url: string, form?: array<string, string>}
     */
    public function start(Booking $booking): array
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            throw new DomainRuleException('Only upcoming bookings can be paid for.', 'not_payable');
        }

        if ($booking->estimated_cost <= 0) {
            throw new DomainRuleException('This booking has nothing to pay.', 'not_payable');
        }

        if ($booking->payments()->where('status', Payment::COMPLETED)->exists()) {
            throw new DomainRuleException('This booking is already paid.', 'already_paid', 409);
        }

        // One transaction: if the provider refuses, no dangling "initiated" payment is left behind.
        [$payment, $init] = DB::transaction(function () use ($booking) {
            $payment = Payment::create([
                'user_id' => $booking->user_id,
                'booking_id' => $booking->id,
                'provider' => $this->gateway->name(),
                'amount' => $booking->estimated_cost,
                'currency' => config('ev.currency'),
            ]);

            $init = $this->gateway->initiate($payment, (string) config('payments.return_url'));
            $payment->update(['reference' => $init['reference']]);

            return [$payment, $init];
        });

        return ['payment' => $payment, 'redirect_url' => $init['redirect_url']] + (isset($init['form']) ? ['form' => $init['form']] : []);
    }

    /** Confirm with the provider. Idempotent: confirming a completed payment is a no-op. */
    public function confirm(Payment $payment, array $params = []): Payment
    {
        return DB::transaction(function () use ($payment, $params) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status === Payment::COMPLETED) {
                return $payment;
            }

            if ($payment->status !== Payment::INITIATED && $payment->status !== Payment::FAILED) {
                throw new DomainRuleException('This payment can no longer be confirmed.', 'not_confirmable');
            }

            $ok = $this->gateway->verify($payment, $params);
            $payment->update(['status' => $ok ? Payment::COMPLETED : Payment::FAILED]);

            return $payment;
        });
    }

    /** Give back what the driver paid minus any cancellation fee. */
    public function refundFor(Booking $booking): void
    {
        $payment = $booking->payments()->where('status', Payment::COMPLETED)->latest('id')->first();
        if (! $payment) {
            return;
        }

        $amount = round(max(0, $payment->amount - (float) $booking->cancellation_fee), 2);
        if ($amount <= 0) {
            return;
        }

        $done = $this->gateway->refund($payment, $amount);
        $payment->update([
            'status' => $done ? Payment::REFUNDED : Payment::REFUND_PENDING,
            'refunded_amount' => $done ? $amount : 0,
        ]);
    }
}
