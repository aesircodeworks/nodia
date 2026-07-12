<?php

namespace App\Payments\Actions;

use App\Payments\Enums\RefundStatus;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Gateways\WebhookKind;
use App\Payments\Models\Payment;
use App\Payments\Models\Refund;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * The missed-refund-webhook backstop (stage-08b plan, Slice 6;
 * system-design 13: sweepers backstop every path). Processing refunds
 * past the grace window are queried at the adapter and resolved through
 * the same conditional transitions the webhook path uses, so a late
 * webhook after the sweeper is a zero-row no-op, mirroring
 * ReconcilePendingPayments.
 */
final readonly class ReconcileProcessingRefunds
{
    public function __construct(
        private TenantTransaction $transactions,
        private GatewayRegistry $gateways,
        private CompleteRefund $completeRefund,
        private FailRefund $failRefund,
    ) {}

    public function __invoke(): int
    {
        $resolved = 0;

        foreach ($this->findCandidates() as $candidate) {
            if ($this->reconcileOne($candidate)) {
                $resolved++;
            }
        }

        return $resolved;
    }

    private function reconcileOne(Refund $candidate): bool
    {
        if ($candidate->gateway_reference === null) {
            return false;
        }

        $gateway = $this->transactions->asTenant(
            $candidate->tenant_id,
            fn (): string => Payment::query()->findOrFail($candidate->payment_id)->gateway,
        );

        $adapter = $this->gateways->get($gateway);

        $outcome = $adapter?->queryRefund($candidate->gateway_reference);

        if ($outcome === null) {
            return false;
        }

        $applied = $this->transactions->asTenant($candidate->tenant_id, fn (): ?Refund => match ($outcome->kind) {
            WebhookKind::RefundCompleted => ($this->completeRefund)($candidate->id),
            WebhookKind::RefundFailed => ($this->failRefund)($candidate->id, (string) $outcome->failureCode),
            default => null,
        });

        return $applied !== null;
    }

    /**
     * @return Collection<int, Refund>
     */
    private function findCandidates(): Collection
    {
        $cutoff = Date::now()->subSeconds((int) config('payments.reconcile_grace_seconds'));

        return $this->transactions->asPlatform(
            fn () => Refund::query()
                ->select('id', 'tenant_id', 'payment_id', 'gateway_reference')
                ->where('status', RefundStatus::Processing->value)
                ->where('updated_at', '<=', $cutoff)
                ->get(),
        );
    }
}
