<?php

namespace App\Orders\Jobs;

use App\Inventory\Exceptions\HoldNotCommittableException;
use App\Orders\Actions\MarkOrderExpired;
use App\Orders\Actions\MarkOrderPaid;
use App\Orders\Exceptions\InvalidOrderTransitionException;
use App\Support\Audit\ActivityLogger;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The Orders subscriber for Payments' PaymentConfirmed (stage-08a plan,
 * Domain events "Consumed"): the idempotent MarkOrderPaid arc, one
 * conditional transition committing the hold and issuing tickets in one
 * transaction. A duplicate delivery finds the order already paid,
 * affects zero rows, and skips ticket issuance entirely.
 *
 * Confirm-after-hold-expiry is a defined outcome, not an error path
 * (stage-08a plan, Domain events): when CommitHold refuses a dead hold,
 * the paid transition rolls back with its savepoint, the order takes the
 * same awaiting_payment to expired arc HandlePaymentExpired uses, the
 * mismatch is activity-logged, and an ops alert flags the confirmed
 * payment for refund; refund execution arrives in Stage 8b, so until
 * then the alert is the compensating action.
 */
final readonly class HandlePaymentConfirmed implements OutboxSubscriber
{
    public const string NAME = 'handle_payment_confirmed';

    public const string MISMATCH_EVENT = 'payment_confirmed_after_hold_expired';

    public function __construct(
        private MarkOrderPaid $markOrderPaid,
        private MarkOrderExpired $markOrderExpired,
        private ActivityLogger $activity,
    ) {}

    public function handle(OutboxEvent $event): void
    {
        $orderId = (string) $event->payload['order_id'];

        try {
            DB::transaction(fn () => ($this->markOrderPaid)($orderId));
        } catch (InvalidOrderTransitionException) {
            // Already paid (synchronous approval or a duplicate) or on
            // another terminal arc: zero rows, nothing to do.
        } catch (HoldNotCommittableException) {
            $this->compensate($event, $orderId);
        }
    }

    private function compensate(OutboxEvent $event, string $orderId): void
    {
        try {
            DB::transaction(fn () => ($this->markOrderExpired)($orderId));
        } catch (InvalidOrderTransitionException) {
            // A racing expiry consumer already moved the order.
        }

        $this->activity->record(
            description: sprintf('Payment %s confirmed after its hold died; order %s expired, payment flagged for refund', $event->aggregate_id, $orderId),
            causer: null,
            event: self::MISMATCH_EVENT,
            properties: ['payment_id' => $event->aggregate_id, 'order_id' => $orderId],
        );

        Log::critical('payments.confirmed_after_hold_expired', [
            'payment_id' => $event->aggregate_id,
            'order_id' => $orderId,
            'tenant_id' => $event->tenant_id,
            'action_required' => 'refund the confirmed payment (Stage 8b executes refunds)',
        ]);
    }
}
