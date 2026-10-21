<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\JsonResponse;

class ConfigController extends BaseController
{
    /** Public, non-secret settings the web app needs to adapt its UI. */
    public function show(): JsonResponse
    {
        return $this->sendResponse([
            'payments_enabled' => in_array(config('payments.driver'), ['khalti', 'esewa'], true),
            'payments_provider' => in_array(config('payments.driver'), ['khalti', 'esewa'], true) ? config('payments.driver') : null,
            'currency' => config('ev.currency'),
            'cancellation_free_minutes' => config('ev.cancellation_free_minutes'),
            'cancellation_fee_percent' => config('ev.cancellation_fee_percent'),
        ]);
    }
}
