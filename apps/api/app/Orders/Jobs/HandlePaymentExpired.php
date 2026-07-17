<?php

namespace App\Orders\Jobs;

use App\Orders\Actions\MarkOrderExpired;
use App\Orders\Exceptions\InvalidOrderTransitionException;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;
use Illuminate\Support\Facades\DB;

/**
 * The Orders subscriber for Payments' PaymentExpired (stage-08a plan,
 * Domain events "Consumed"): conditional awaiting_payment to expired
 * releasing the hold in the same transaction; ReleaseHold is a no-op
 * when the hold sweeper already expired it. Zero rows (duplicate, or
 * the order already on another terminal arc) is success.
 */
final readonly class HandlePaymentExpired implements OutboxSubscriber
{
    public const string NAME = 'handle_payment_expired';

    public function __construct(
        private MarkOrderExpired $markOrderExpired,
    ) {}

    public function handle(OutboxEvent $event): void
    {
        try {
            DB::transaction(fn () => ($this->markOrderExpired)((string) $event->payload['order_id']));
        } catch (InvalidOrderTransitionException) {
            // Duplicate delivery or an order already resolved elsewhere.
        }
    }
}
