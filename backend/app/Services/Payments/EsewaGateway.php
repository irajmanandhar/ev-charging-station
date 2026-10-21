<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;

/** eSewa ePay v2: a signed form POST, then a status check (https://developer.esewa.com.np). */
class EsewaGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'esewa';
    }

    public function sign(string $total, string $uuid): string
    {
        $code = config('payments.esewa.product_code');

        return base64_encode(hash_hmac(
            'sha256',
            "total_amount={$total},transaction_uuid={$uuid},product_code={$code}",
            (string) config('payments.esewa.secret_key'),
            true
        ));
    }

    public function initiate(Payment $payment, string $returnUrl): array
    {
        $total = number_format((float) $payment->amount, 2, '.', '');
        // Unique per attempt so a retry after a failed payment is accepted.
        $uuid = $payment->booking_id.'-'.$payment->id.'-'.now()->format('His');

        return [
            'reference' => $uuid,
            'redirect_url' => config('payments.esewa.form_url'),
            'form' => [
                'amount' => $total,
                'tax_amount' => '0',
                'total_amount' => $total,
                'transaction_uuid' => $uuid,
                'product_code' => config('payments.esewa.product_code'),
                'product_service_charge' => '0',
                'product_delivery_charge' => '0',
                'success_url' => $returnUrl,
                'failure_url' => $returnUrl,
                'signed_field_names' => 'total_amount,transaction_uuid,product_code',
                'signature' => $this->sign($total, $uuid),
            ],
        ];
    }

    public function verify(Payment $payment, array $params): string
    {
        $total = number_format((float) $payment->amount, 2, '.', '');

        $res = Http::acceptJson()->timeout(15)->get(config('payments.esewa.status_url'), [
            'product_code' => config('payments.esewa.product_code'),
            'total_amount' => $total,
            'transaction_uuid' => $payment->reference,
        ]);

        if (! $res->successful()) {
            return Payment::INITIATED; // provider unreachable: unknown, not "failed"; try again later
        }

        return match ($res->json('status')) {
            'COMPLETE' => abs((float) $res->json('total_amount') - (float) $payment->amount) < 0.005 ? Payment::COMPLETED : Payment::FAILED,
            'PENDING', 'AMBIGUOUS', 'NOT_FOUND' => Payment::INITIATED, // NOT_FOUND: the driver has not paid yet
            default => Payment::FAILED, // CANCELED, FULL_REFUND, ...
        };
    }

    public function refund(Payment $payment, float $amount): bool
    {
        // eSewa refunds are handled with the merchant support team, not an open API.
        return false;
    }
}
