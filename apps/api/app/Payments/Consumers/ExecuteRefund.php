<?php

namespace App\Payments\Consumers;

use App\Payments\Actions\FailRefund;
use App\Payments\Enums\RefundStatus;
use App\Payments\Gateways\GatewayRefundRequest;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Models\Payment;
use App\Payments\Models\Refund;
use App\Support\Outbox\DetachedOutboxSubscriber;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Tenancy\TenantTransaction;
use Illuminate\Support\Facades\DB;

/**
 * The RefundInitiated executor (stage-08b plan, Domain events
 * "Consumed"): pending to processing is the conditional UPDATE that
 * admits exactly one gateway call even under duplicate delivery or
 * parallel workers. Detached from the delivery transaction so the
 * gateway round trip never holds a connection or row lock: the claim
 * commits first, the adapter call runs outside any transaction, and the
 * outcome persists in a second transaction. A transport failure reverts
 * the claim to pending and rethrows, so the retry (3 attempts at 1s,
 * 5s, 15s per system-design 13, declared in config/outbox.php)
 * re-executes with the same idempotency key and exhaustion dead-letters
 * into failed_jobs; a crash between the claim and the outcome leaves
 * the refund processing with no gateway_reference, which
 * ReconcileProcessingRefunds re-executes under the same idempotency
 * key. A decline is a result: the refund fails and its reservation is
 * released through FailRefund's guarded transition.
 */
final readonly class ExecuteRefund implements DetachedOutboxSubscriber
{
    public const string NAME = 'execute_refund';

    public function __construct(
        private TenantTransaction $transactions,
        private GatewayRegistry $gateways,
        private FailRefund $failRefund,
    ) {}

    public function handle(OutboxEvent $event): void
    {
        $refundId = (string) $event->payload['refund_id'];
        $tenantId = $event->tenant_id;

        [$claimed, $refund, $payment] = $this->transactions->asTenant($tenantId, function () use ($refundId): array {
            $refund = Refund::query()->findOrFail($refundId);

            $claimed = DB::table('refunds')
                ->where('id', $refund->id)
                ->where('status', RefundStatus::Pending->value)
                ->update(['status' => RefundStatus::Processing->value, 'updated_at' => now()]);

            return [$claimed === 1, $refund, Payment::query()->findOrFail($refund->payment_id)];
        });

        if (! $claimed) {
            return;
        }

        $adapter = $this->gateways->get($payment->gateway);

        if ($adapter === null || $payment->gateway_reference === null) {
            $this->transactions->asTenant($tenantId, fn () => ($this->failRefund)($refund->id, 'gateway_unavailable_for_refund'));

            return;
        }

        try {
            $result = $adapter->refund(new GatewayRefundRequest(
                refundId: $refund->id,
                paymentGatewayReference: $payment->gateway_reference,
                amount: $refund->money,
                idempotencyKey: $refund->idempotency_key,
            ));
        } catch (\Throwable $transportFailure) {
            // The claim already committed, so revert it before rethrowing
            // or the retry would find processing and no-op while the
            // gateway was never (successfully) asked.
            $this->transactions->asTenant($tenantId, fn (): int => DB::table('refunds')
                ->where('id', $refund->id)
                ->where('status', RefundStatus::Processing->value)
                ->update(['status' => RefundStatus::Pending->value, 'updated_at' => now()]));

            throw $transportFailure;
        }

        $this->transactions->asTenant($tenantId, function () use ($refund, $result): void {
            if ($result->accepted) {
                DB::table('refunds')
                    ->where('id', $refund->id)
                    ->update(['gateway_reference' => $result->gatewayReference, 'updated_at' => now()]);

                return;
            }

            ($this->failRefund)($refund->id, (string) $result->failureCode);
        });
    }
}
