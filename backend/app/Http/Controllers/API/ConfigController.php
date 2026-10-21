<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\JsonResponse;

class ConfigController extends BaseController
{
    /** Public, non-secret settings the web app needs to adapt its UI. */
    public function show(): JsonResponse
    {
        return $this->sendResponse([
            'payments_enabled' => config('payments.driver') !== 'null',
            'payments_provider' => config('payments.driver'),
            'currency' => config('ev.currency'),
            'cancellation_free_minutes' => config('ev.cancellation_free_minutes'),
            'cancellation_fee_percent' => config('ev.cancellation_fee_percent'),
        ]);
    }
}
