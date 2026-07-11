<?php

namespace App\Payments\Actions;

use App\Payments\Enums\PaymentStatus;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Gateways\WebhookKind;
use App\Payments\Models\Payment;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * The missed-webhook backstop (stage-08a plan, Slice 7; system-design
 * 7.4, 13: every 5 minutes). Initiated payments past a grace period are
 * queried at the adapter and resolved through the same conditional
 * transitions the webhook path uses, so double-processing against a
 * webhook is a zero-row no-op. Candidates are selected by payment age
 * rather than by joining the order's status: an initiated payment past
 * grace is worth reconciling regardless, and Payments never queries
 * another context's tables (system-design 3.1).
 */
final readonly class ReconcilePendingPayments
{
    public function __construct(
        private TenantTransaction $transactions,
        private GatewayRegistry $gateways,
        private ConfirmPayment $confirmPayment,
        private FailPayment $failPayment,
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

    private function reconcileOne(Payment $candidate): bool
    {
        $adapter = $this->gateways->get($candidate->gateway);

        if ($adapter === null || $candidate->gateway_reference === null) {
            return false;
        }

        $outcome = $adapter->queryPayment($candidate->gateway_reference);

        if ($outcome === null) {
            return false;
        }

        $applied = $this->transactions->asTenant($candidate->tenant_id, fn (): ?Payment => match ($outcome->kind) {
            WebhookKind::Confirmed => ($this->confirmPayment)($candidate->id, $outcome->fee),
            WebhookKind::Failed => ($this->failPayment)($candidate->id, (string) $outcome->failureCode),
        });

        return $applied !== null;
    }

    /**
     * @return Collection<int, Payment>
     */
    private function findCandidates(): Collection
    {
        $cutoff = Date::now()->subSeconds((int) config('payments.reconcile_grace_seconds'));

        return $this->transactions->asPlatform(
            fn () => Payment::query()
                ->select('id', 'tenant_id', 'gateway', 'gateway_reference')
                ->where('status', PaymentStatus::Initiated->value)
                ->where('created_at', '<=', $cutoff)
                ->get(),
        );
    }
}
