<?php

namespace App\Reporting\Jobs;

use App\Payments\Actions\GetPaymentEventFinanceFacts;
use App\Payments\Data\PaymentEventFinanceFactsData;
use App\Reporting\Models\EventFinance;
use App\Reporting\Support\ApplyEventFinanceIncrement;
use App\Reporting\Support\EventFinanceIncrement;
use App\Reporting\Support\Rebuild\RebuildableProjection;
use App\Support\Money\Money;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;
use App\Support\Outbox\ProjectionLockedSubscriber;
use Illuminate\Database\Eloquent\Model;

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
 *
 * Also ProjectionLockedSubscriber and RebuildableProjection (stage-11
 * plan, task 13): computeIncrement() is the same increment-resolution
 * logic handle() applies, exposed separately so
 * App\Reporting\Support\Rebuild\ReportingProjectionRebuilder can drive
 * it directly (replay-and-write) or fold it in memory (--verify).
 */
final readonly class ProjectEventFinance implements OutboxSubscriber, ProjectionLockedSubscriber, RebuildableProjection
{
    public const string NAME = 'project_event_finance';

    public function __construct(
        private GetPaymentEventFinanceFacts $paymentFacts,
        private ApplyEventFinanceIncrement $applyIncrement,
    ) {}

    public function handle(OutboxEvent $event): void
    {
        $increment = $this->computeIncrement($event);

        if ($increment !== null) {
            ($this->applyIncrement)($event->tenant_id, $increment);
        }
    }

    public function projectionLockKey(): string
    {
        return self::NAME;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function modelClass(): string
    {
        return EventFinance::class;
    }

    public function keyColumns(): array
    {
        return ['event_id'];
    }

    public function computeIncrement(OutboxEvent $event): ?EventFinanceIncrement
    {
        return match ($event->type) {
            'PaymentConfirmed' => $this->resolvePaymentConfirmedIncrement($event),
            'RefundCompleted' => $this->resolveRefundCompletedIncrement($event),
            default => null,
        };
    }

    private function resolvePaymentConfirmedIncrement(OutboxEvent $event): ?EventFinanceIncrement
    {
        $facts = $this->resolveFacts((string) $event->payload['payment_id']);

        // A payment whose order cannot be resolved to an event is a
        // data-integrity gap this read model cannot recover from on its
        // own; skip rather than throw, mirroring ProjectDailySales'
        // defensive handling of the same class of gap. The row is
        // simply absent until reporting:rebuild (task 13) can retry it.
        if ($facts === null) {
            return null;
        }

        $gross = Money::of((int) $event->payload['amount']['amount'], (string) $event->payload['amount']['currency']);

        return EventFinanceIncrement::paymentConfirmed($facts->eventId, $gross, $facts->feeAmount, $facts->commissionAmount);
    }

    private function resolveRefundCompletedIncrement(OutboxEvent $event): ?EventFinanceIncrement
    {
        $facts = $this->resolveFacts((string) $event->payload['payment_id']);

        if ($facts === null) {
            return null;
        }

        $amount = Money::of((int) $event->payload['amount']['amount'], (string) $event->payload['amount']['currency']);
        $returnedCommission = Money::of(
            (int) $event->payload['commission_amount']['amount'],
            (string) $event->payload['commission_amount']['currency'],
        );

        return EventFinanceIncrement::refundCompleted($facts->eventId, $amount, $returnedCommission);
    }

    private function resolveFacts(string $paymentId): ?PaymentEventFinanceFactsData
    {
        return ($this->paymentFacts)([$paymentId])->get($paymentId);
    }

    public function keyFor(object $increment): array
    {
        return ['event_id' => $increment->eventId];
    }

    public function fold(?array $row, object $increment): array
    {
        $row ??= [
            'orders_paid_count' => 0,
            'refunds_count' => 0,
            'gross_amount' => 0,
            'gateway_fee_amount' => 0,
            'platform_commission_amount' => 0,
            'tenant_net_amount' => 0,
            'refunded_amount' => 0,
            'currency' => null,
        ];

        $row['orders_paid_count'] += $increment->ordersPaidCount;
        $row['refunds_count'] += $increment->refundsCount;
        $row['gross_amount'] += $increment->grossAmount;
        $row['gateway_fee_amount'] += $increment->gatewayFeeAmount;
        $row['platform_commission_amount'] += $increment->platformCommissionAmount;
        $row['tenant_net_amount'] += $increment->tenantNetAmount;
        $row['refunded_amount'] += $increment->refundedAmount;
        $row['currency'] ??= $increment->currency;

        return $row;
    }

    public function rowFromModel(Model $model): array
    {
        return [
            'orders_paid_count' => (int) $model->getAttribute('orders_paid_count'),
            'refunds_count' => (int) $model->getAttribute('refunds_count'),
            'gross_amount' => (int) $model->getAttribute('gross_amount'),
            'gateway_fee_amount' => (int) $model->getAttribute('gateway_fee_amount'),
            'platform_commission_amount' => (int) $model->getAttribute('platform_commission_amount'),
            'tenant_net_amount' => (int) $model->getAttribute('tenant_net_amount'),
            'refunded_amount' => (int) $model->getAttribute('refunded_amount'),
            'currency' => (string) $model->getAttribute('currency'),
        ];
    }
}
