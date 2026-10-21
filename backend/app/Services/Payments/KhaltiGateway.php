<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\DomainRuleException;
use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Khalti ePayment v2 (https://docs.khalti.com). Amounts are in paisa. */
class KhaltiGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'khalti';
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('payments.khalti.base_url'), '/'))
            ->withHeaders(['Authorization' => 'Key '.config('payments.khalti.secret_key')])
            ->acceptJson()
            ->timeout(15);
    }

    public function initiate(Payment $payment, string $returnUrl): array
    {
        $payment->loadMissing('booking', 'user');

        $res = $this->http()->post('/epayment/initiate/', [
            'return_url' => $returnUrl,
            'website_url' => config('ev.frontend_url'),
            'amount' => (int) round($payment->amount * 100),
            'purchase_order_id' => $payment->booking->reference,
            'purchase_order_name' => 'EV charging '.$payment->booking->reference,
            'customer_info' => ['name' => $payment->user->name, 'email' => $payment->user->email],
        ]);

        if (! $res->successful() || ! $res->json('pidx')) {
            report(new \RuntimeException('Khalti initiate failed: '.$res->status()));
            throw new DomainRuleException('The payment provider could not start the payment. Try again.', 'payment_provider_error', 502);
        }

        return ['reference' => $res->json('pidx'), 'redirect_url' => $res->json('payment_url')];
    }

    public function verify(Payment $payment, array $params): bool
    {
        $res = $this->http()->post('/epayment/lookup/', ['pidx' => $payment->reference]);

        return $res->successful()
            && $res->json('status') === 'Completed'
            && (int) $res->json('total_amount') === (int) round($payment->amount * 100);
    }

    public function refund(Payment $payment, float $amount): bool
    {
        // Khalti refunds are issued from the merchant dashboard, not the public API.
        return false;
    }
}
