<?php

namespace App\Payments\Consumers;

use App\Payments\Actions\FailRefund;
use App\Payments\Enums\RefundStatus;
use App\Payments\Gateways\GatewayRefundRequest;
use App\Payments\Gateways\GatewayRegistry;
use App\Payments\Models\Payment;
use App\Payments\Models\Refund;
use App\Support\Outbox\Models\OutboxEvent;
use App\Support\Outbox\OutboxSubscriber;
use Illuminate\Support\Facades\DB;

/**
 * The RefundInitiated executor (stage-08b plan, Domain events
 * "Consumed"): pending to processing is the conditional UPDATE that
 * admits exactly one gateway call even under duplicate delivery or
 * parallel workers. A transport failure throws, rolling the transition
 * back with the delivery mark, so the retry (3 attempts at 1s, 5s, 15s
 * per system-design 13, declared in config/outbox.php) re-executes with
 * the same idempotency key and exhaustion dead-letters into
 * failed_jobs. A decline is a result: the refund fails and its
 * reservation is released through FailRefund's guarded transition.
 */
final readonly class ExecuteRefund implements OutboxSubscriber
{
    public const string NAME = 'execute_refund';

    public function __construct(
        private GatewayRegistry $gateways,
        private FailRefund $failRefund,
    ) {}

    public function handle(OutboxEvent $event): void
    {
        $refund = Refund::query()->findOrFail((string) $event->payload['refund_id']);

        $claimed = DB::table('refunds')
            ->where('id', $refund->id)
            ->where('status', RefundStatus::Pending->value)
            ->update(['status' => RefundStatus::Processing->value, 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $payment = Payment::query()->findOrFail($refund->payment_id);

        $adapter = $this->gateways->get($payment->gateway);

        if ($adapter === null || $payment->gateway_reference === null) {
            ($this->failRefund)($refund->id, 'gateway_unavailable_for_refund');

            return;
        }

        $result = $adapter->refund(new GatewayRefundRequest(
            refundId: $refund->id,
            paymentGatewayReference: $payment->gateway_reference,
            amount: $refund->money,
            idempotencyKey: $refund->idempotency_key,
        ));

        if ($result->accepted) {
            DB::table('refunds')
                ->where('id', $refund->id)
                ->update(['gateway_reference' => $result->gatewayReference, 'updated_at' => now()]);

            return;
        }

        ($this->failRefund)($refund->id, (string) $result->failureCode);
    }
}
