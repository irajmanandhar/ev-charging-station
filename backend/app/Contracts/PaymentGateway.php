<?php

namespace App\Contracts;

use App\Models\Payment;

interface PaymentGateway
{
    public function name(): string;

    /**
     * Start a payment. Returns what the client needs to send the driver to the provider:
     * ['reference' => string, 'redirect_url' => string, 'form' => ?array] (form = POST fields, if the provider wants a form post).
     *
     * @return array{reference: string, redirect_url: string, form?: array<string, string>}
     */
    public function initiate(Payment $payment, string $returnUrl): array;

    /**
     * Ask the provider what really happened. Never trust the client's word.
     * Returns Payment::COMPLETED, Payment::INITIATED (provider says still pending) or Payment::FAILED.
     */
    public function verify(Payment $payment, array $params): string;

    /** Refund part or all of a completed payment. False when the provider can't do it via API. */
    public function refund(Payment $payment, float $amount): bool;
}
