<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\DomainRuleException;
use App\Models\Payment;

/** Default gateway: online payments are switched off. */
class NullGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'null';
    }

    public function initiate(Payment $payment, string $returnUrl): array
    {
        throw new DomainRuleException('Online payments are not enabled.', 'payments_disabled');
    }

    public function verify(Payment $payment, array $params): bool
    {
        return false;
    }

    public function refund(Payment $payment, float $amount): bool
    {
        return false;
    }
}
