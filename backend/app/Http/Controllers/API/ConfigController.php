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
            'checkin_early_minutes' => config('ev.checkin_early_minutes'),
            'no_show_grace_minutes' => config('ev.no_show_grace_minutes'),
            'charge_efficiency' => (float) config('ev.charge_efficiency'),
            'max_session_kwh' => (float) config('ev.max_session_kwh'),
        ]);
    }
}
