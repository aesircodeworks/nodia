<?php

namespace App\Payments\Consumers;

use App\Payments\Models\LedgerEntry;
use App\Payments\Models\Payment;
use App\Payments\Models\Payout;
use App\Payments\Models\Refund;
use App\Payments\Support\LedgerEntrySetBuilder;
use App\Payments\Support\LedgerLeg;
use App\Support\Money\Money;
use App\Support\Outbox\KeyedOrderedOutboxSubscriber;
use App\Support\Outbox\Models\OutboxEvent;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * The ledger projection (system-design 7.3, 9.1): consumes
 * PaymentConfirmed and RefundCompleted ordered per payment through the
 * payload-derived key, since the two events carry different envelope
 * aggregates. PayoutExecuted's payload carries no payment_id, so it
 * falls back to OrderedConsumption's envelope-aggregate ordering
 * (payout id), which is sufficient since a payout never shares an
 * aggregate with a payment or refund. Amounts come from the payment,
 * refund, and payout row facts this context owns, never from live
 * tenant configuration, so a replay rebuild is deterministic.
 * Idempotence is layered: outbox_deliveries progress plus the
 * (source_event_id, account) unique index turning the insert itself
 * into a no-op on duplicates and replays.
 */
final readonly class ProjectLedgerEntries implements KeyedOrderedOutboxSubscriber
{
    public const string NAME = 'project_ledger_entries';

    public function __construct(private LedgerEntrySetBuilder $builder) {}

    public function orderingKeyPayloadPath(): string
    {
        return 'payment_id';
    }

    public function handle(OutboxEvent $event): void
    {
        match ($event->type) {
            'PaymentConfirmed' => $this->applyPaymentConfirmed($event),
            'RefundCompleted' => $this->applyRefundCompleted($event),
            'PayoutExecuted' => $this->applyPayoutExecuted($event),
            default => null,
        };
    }

    private function applyPaymentConfirmed(OutboxEvent $event): void
    {
        $payment = Payment::query()->findOrFail((string) $event->payload['payment_id']);

        $legs = $this->builder->paymentLegs(
            $payment->money,
            Money::of($payment->fee_amount, $payment->currency),
            Money::of($payment->commission_amount, $payment->currency),
        );

        $this->insert($event, $legs, 'payment', $payment->id);
    }

    private function applyRefundCompleted(OutboxEvent $event): void
    {
        $refund = Refund::query()->findOrFail((string) $event->payload['refund_id']);

        $legs = $this->builder->refundLegs(
            $refund->money,
            Money::of($refund->commission_amount, $refund->currency),
            $refund->commission_policy,
        );

        $this->insert($event, $legs, 'refund', $refund->id);
    }

    private function applyPayoutExecuted(OutboxEvent $event): void
    {
        $payout = Payout::query()->findOrFail((string) $event->payload['payout_id']);

        $legs = $this->builder->payoutLegs($payout->money);

        $this->insert($event, $legs, 'payout', $payout->id);
    }

    /**
     * @param  list<LedgerLeg>  $legs
     */
    private function insert(OutboxEvent $event, array $legs, string $referenceType, string $referenceId): void
    {
        $now = Date::now();

        LedgerEntry::query()->insertOrIgnore(array_map(fn (LedgerLeg $leg): array => [
            'id' => Str::uuid7()->toString(),
            'tenant_id' => $event->tenant_id,
            'account' => $leg->account->value,
            'direction' => $leg->direction->value,
            'amount' => $leg->amount->amount,
            'currency' => $leg->amount->currency,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'source_event_id' => $event->id,
            'created_at' => $now,
            'updated_at' => $now,
        ], $legs));
    }
}
