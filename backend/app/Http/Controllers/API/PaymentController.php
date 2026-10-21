<?php

namespace App\Http\Controllers\API;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends BaseController
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    /** Start paying for a booking. Returns where to send the driver (and form fields for eSewa). */
    public function start(Booking $booking): JsonResponse
    {
        $this->authorize('act', $booking);

        $started = $this->payments->start($booking);

        return $this->sendResponse([
            'payment_id' => $started['payment']->id,
            'provider' => $started['payment']->provider,
            'redirect_url' => $started['redirect_url'],
            'form' => $started['form'] ?? null,
        ], 'Payment started.', 201);
    }

    /** Called when the driver comes back from the provider. The provider is asked directly; the client's claim is not trusted. */
    public function confirm(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('confirm', $payment);

        $payment = $this->payments->confirm($payment, $request->only(['pidx', 'data', 'status']));

        return $this->sendResponse(
            ['id' => $payment->id, 'status' => $payment->status, 'booking_id' => $payment->booking_id],
            match ($payment->status) {
                Payment::COMPLETED => 'Payment received.',
                Payment::INITIATED => 'Your payment is still being processed. We will update your booking when the provider confirms it.',
                Payment::REFUND_PENDING => 'Payment received, but the booking is no longer active. A refund will be arranged.',
                default => 'Payment was not completed.',
            }
        );
    }
}
