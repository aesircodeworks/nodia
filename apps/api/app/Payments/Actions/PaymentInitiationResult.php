<?php

namespace App\Payments\Actions;

use App\Payments\Models\Payment;

/**
 * InitiatePayment's outcome for the controller to render: a synchronous
 * decline renders the payment_declined problem while the committed
 * transaction keeps the failed payment row and its events, a replay
 * returns 200 with the original body, and everything else is 201.
 *
 * The two compose: replaying the key of a declined payment re-renders
 * that same 402 rather than reporting the failed payment as a 200
 * success, so declined is derived from the payment's own terminal status
 * on the replay path, not hardcoded false.
 */
final class PaymentInitiationResult
{
    public function __construct(
        public readonly Payment $payment,
        public readonly bool $replayed,
        public readonly bool $declined,
    ) {}
}
