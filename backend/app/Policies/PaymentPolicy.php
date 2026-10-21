<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    /** Only the driver who started a payment may confirm it. */
    public function confirm(User $user, Payment $payment): bool
    {
        return $payment->user_id === $user->id;
    }
}
