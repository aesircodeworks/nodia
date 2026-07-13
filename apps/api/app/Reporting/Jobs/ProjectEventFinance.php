<?php

namespace App\Reporting\Jobs;

use App\Payments\Actions\GetPaymentEventFinanceFacts;
use App\Payments\Data\PaymentEventFinanceFactsData;
use App\Reporting\Support\ApplyEventFinanceIncrement;
use App\Reporting\Support\EventFinanceIncrement;
use App\Support\Money\Money;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;

/**
 * The finance projection consumer (stage-11 plan, Domain events
 * "Consumed" table; task 8): a plain, unordered OutboxSubscriber, like
 * ProjectDailySales, because every mutation it makes is a commutative
 * increment (stage-11 plan, TDD sequencing preamble: "No projector uses
 * the ordered-consumption helper"). Running unordered and without the
 * Stage 4 stability window also means this projector applies its effect
 * on the very same synchronous delivery that confirms a payment or
 * completes a refund, strictly before the ordered ledger projection
 * (App\Payments\Consumers\ProjectLedgerEntries) becomes eligible to run,
 * which is exactly what proves this projector depends on no
 * ledger_entries rows (system-design 9.2: no ordering guarantee across
 * consumers).
 *
 * Gross for a confirmed payment comes from the PaymentConfirmed
 * payload's own amount; fee and commission come from the payment row
 * facts through GetPaymentEventFinanceFacts, never from ledger_entries.
 * Refund deltas come straight from the RefundCompleted payload's amount
 * and commission_amount (already policy-resolved at refund creation,
 * Stage 8b), never recomputed. event_id is resolved the same way for
 * both event types, through the same Payments Action.
 */
final readonly class ProjectEventFinance implements OutboxSubscriber
{
    public const string NAME = 'project_event_finance';

    public function __construct(
        private GetPaymentEventFinanceFacts $paymentFacts,
        private ApplyEventFinanceIncrement $applyIncrement,
    ) {}

    public function handle(OutboxEvent $event): void
    {
        match ($event->type) {
            'PaymentConfirmed' => $this->projectPaymentConfirmed($event),
            'RefundCompleted' => $this->projectRefundCompleted($event),
            default => null,
        };
    }

    private function projectPaymentConfirmed(OutboxEvent $event): void
    {
        $facts = $this->resolveFacts((string) $event->payload['payment_id']);

        // A payment whose order cannot be resolved to an event is a
        // data-integrity gap this read model cannot recover from on its
        // own; skip rather than throw, mirroring ProjectDailySales'
        // defensive handling of the same class of gap. The row is
        // simply absent until reporting:rebuild (task 13) can retry it.
        if ($facts === null) {
            return;
        }

        $gross = Money::of((int) $event->payload['amount']['amount'], (string) $event->payload['amount']['currency']);

        $increment = EventFinanceIncrement::paymentConfirmed($facts->eventId, $gross, $facts->feeAmount, $facts->commissionAmount);

        ($this->applyIncrement)($event->tenant_id, $increment);
    }

    private function projectRefundCompleted(OutboxEvent $event): void
    {
        $facts = $this->resolveFacts((string) $event->payload['payment_id']);

        if ($facts === null) {
            return;
        }

        $amount = Money::of((int) $event->payload['amount']['amount'], (string) $event->payload['amount']['currency']);
        $returnedCommission = Money::of(
            (int) $event->payload['commission_amount']['amount'],
            (string) $event->payload['commission_amount']['currency'],
        );

        $increment = EventFinanceIncrement::refundCompleted($facts->eventId, $amount, $returnedCommission);

        ($this->applyIncrement)($event->tenant_id, $increment);
    }

    private function resolveFacts(string $paymentId): ?PaymentEventFinanceFactsData
    {
        return ($this->paymentFacts)([$paymentId])->get($paymentId);
    }
}
