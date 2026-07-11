<?php

namespace App\Payments\Actions;

use App\Payments\Models\Payment;

/**
 * InitiatePayment's outcome for the controller to render: a replay
 * returns 200 with the original body, a fresh synchronous decline
 * renders the payment_declined problem while the committed transaction
 * keeps the failed payment row and its events, everything else is 201.
 */
final class PaymentInitiationResult
{
    public function __construct(
        public readonly Payment $payment,
        public readonly bool $replayed,
        public readonly bool $declined,
    ) {}
}
