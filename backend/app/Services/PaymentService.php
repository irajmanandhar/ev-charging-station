<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Enums\BookingStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Payments\NullGateway;
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
        $payment = DB::transaction(function () use ($booking) {
            // Lock the booking so a cancel or a second Pay click can't interleave with these checks.
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($booking->status !== BookingStatus::Confirmed) {
                throw new DomainRuleException('Only upcoming bookings can be paid for.', 'not_payable');
            }

            if ($booking->estimated_cost <= 0) {
                throw new DomainRuleException('This booking has nothing to pay.', 'not_payable');
            }

            if ($booking->payments()->where('status', Payment::COMPLETED)->exists()) {
                throw new DomainRuleException('This booking is already paid.', 'already_paid', 409);
            }

            return Payment::create([
                'user_id' => $booking->user_id,
                'booking_id' => $booking->id,
                'provider' => $this->gateway->name(),
                'amount' => $booking->estimated_cost,
                'currency' => config('ev.currency'),
            ]);
        });

        // The provider call happens outside any transaction, so no row stays locked on a slow network.
        try {
            $init = $this->gateway->initiate($payment, (string) config('payments.return_url'));
        } catch (\Throwable $e) {
            $payment->delete(); // nothing was started, so leave no dangling "initiated" payment
            throw $e;
        }

        $payment->update(['reference' => $init['reference']]);

        return ['payment' => $payment, 'redirect_url' => $init['redirect_url']] + (isset($init['form']) ? ['form' => $init['form']] : []);
    }

    /**
     * Ask the provider what happened and record it. Idempotent, and safe to call again for a payment
     * that is still pending. A payment that completes for a booking that is no longer live (cancelled,
     * missed) or that is already paid is flagged `refund_pending` instead of being silently kept.
     */
    public function confirm(Payment $payment, array $params = []): Payment
    {
        $payment->refresh();
        if (! in_array($payment->status, [Payment::INITIATED, Payment::FAILED], true)) {
            return $payment;
        }

        $result = $this->gateway->verify($payment, $params); // network call, outside the transaction

        return DB::transaction(function () use ($payment, $result) {
            $booking = Booking::query()->lockForUpdate()->findOrFail($payment->booking_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if (! in_array($payment->status, [Payment::INITIATED, Payment::FAILED], true)) {
                return $payment; // settled by a concurrent confirm
            }

            if ($result !== Payment::COMPLETED) {
                $payment->update(['status' => $result]);

                return $payment;
            }

            $alreadyPaid = $booking->payments()->where('status', Payment::COMPLETED)->whereKeyNot($payment->id)->exists();
            $live = in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::Active, BookingStatus::Completed], true);

            if ($alreadyPaid || ! $live) {
                $owed = $alreadyPaid ? $payment->amount : round(max(0, $payment->amount - (float) $booking->cancellation_fee), 2);
                $this->flagForRefund($payment, $owed, $alreadyPaid ? 'duplicate_payment' : 'booking_not_active');

                return $payment;
            }

            $payment->update(['status' => Payment::COMPLETED]);

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

        if ($this->gateway->refund($payment, $amount)) {
            $payment->update(['status' => Payment::REFUNDED, 'refunded_amount' => $amount]);
        } else {
            $this->flagForRefund($payment, $amount, 'cancelled');
        }
    }

    /** A paid booking was missed: money is held, so record it for the operator to decide. */
    public function flagNoShow(Booking $booking): void
    {
        $payment = $booking->payments()->where('status', Payment::COMPLETED)->latest('id')->first();
        if ($payment) {
            $this->flagForRefund($payment, $payment->amount, 'no_show');
        }
    }

    /**
     * Re-check payments the driver may have completed after their browser stopped talking to us
     * (closed tab, provider still pending). Run by the scheduler.
     */
    public function reconcile(): int
    {
        if ($this->gateway instanceof NullGateway) {
            return 0;
        }

        $count = 0;
        Payment::query()
            ->whereIn('status', [Payment::INITIATED, Payment::FAILED])
            ->whereNotNull('reference')
            ->where('provider', $this->gateway->name())
            ->where('created_at', '>', now()->subDay())
            ->where('updated_at', '<', now()->subMinutes(2))
            ->lazyById()
            ->each(function (Payment $payment) use (&$count) {
                try {
                    $before = $payment->status;
                    $after = $this->confirm($payment)->status;
                    $payment->touch();
                    $count += $before !== $after ? 1 : 0;
                } catch (\Throwable $e) {
                    report($e); // one provider hiccup must not stop the rest
                }
            });

        return $count;
    }

    /** Record money owed back to the driver, with the amount and why, for a person to action. */
    private function flagForRefund(Payment $payment, float $owed, string $reason): void
    {
        $payment->update([
            'status' => Payment::REFUND_PENDING,
            'payload' => [...($payment->payload ?? []), 'refund_owed' => $owed, 'refund_reason' => $reason],
        ]);
    }
}
