<?php

namespace App\Orders\Jobs;

use App\Orders\Actions\MarkOrderFailed;
use App\Orders\Exceptions\InvalidOrderTransitionException;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;
use Illuminate\Support\Facades\DB;

/**
 * The Orders subscriber for Payments' PaymentFailed (stage-08a plan,
 * Domain events "Consumed"): conditional awaiting_payment to failed
 * releasing the hold in the same transaction. Synchronous declines
 * leave the order pending (system-design 7.6), so their PaymentFailed
 * delivery lands here as a zero-row no-op, which is also what makes
 * duplicate delivery harmless.
 */
final readonly class HandlePaymentFailed implements OutboxSubscriber
{
    public const string NAME = 'handle_payment_failed';

    public function __construct(
        private MarkOrderFailed $markOrderFailed,
    ) {}

    public function handle(OutboxEvent $event): void
    {
        try {
            DB::transaction(fn () => ($this->markOrderFailed)((string) $event->payload['order_id']));
        } catch (InvalidOrderTransitionException) {
            // Pending (sync decline), already terminal, or a duplicate.
        }
    }
}
