<?php

namespace App\Payments\Actions;

use App\Orders\Actions\MarkOrderPartiallyRefunded;
use App\Orders\Actions\MarkOrderRefunded;
use App\Orders\Actions\MarkTicketsRefunded;
use App\Orders\Exceptions\InvalidOrderTransitionException;
use App\Payments\Enums\RefundStatus;
use App\Payments\Events\RefundCompleted;
use App\Payments\Models\Payment;
use App\Payments\Models\Refund;
use App\Support\Outbox\OutboxRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * processing to completed (stage-08b plan, Slice 7): one transaction
 * transitions the refund, moves the order to partially_refunded or
 * refunded by whether completed refunds now cover the payment amount
 * with no sibling refund still open,
 * voids the tickets read from the refund row's persisted selection
 * (null voids every issued ticket, the full-refund rule), and records
 * RefundCompleted. The conditional transition makes duplicate webhooks,
 * sweeper races, and duplicate deliveries zero-row no-ops. Returns null
 * on zero rows.
 */
final class CompleteRefund
{
    public function __construct(
        private readonly MarkOrderPartiallyRefunded $markPartiallyRefunded,
        private readonly MarkOrderRefunded $markRefunded,
        private readonly MarkTicketsRefunded $markTicketsRefunded,
        private readonly OutboxRecorder $outbox,
    ) {}

    public function __invoke(string $refundId): ?Refund
    {
        return DB::transaction(function () use ($refundId): ?Refund {
            $refund = Refund::query()->find($refundId);

            if ($refund === null) {
                return null;
            }

            $affected = DB::table('refunds')
                ->where('id', $refundId)
                ->where('status', RefundStatus::Processing->value)
                ->update(['status' => RefundStatus::Completed->value, 'updated_at' => now()]);

            if ($affected !== 1) {
                return null;
            }

            $payment = Payment::query()->findOrFail($refund->payment_id);

            // refunded_amount is reserved at creation, not completion, so
            // exhaustion additionally requires that no sibling refund is
            // still pending or processing: a reservation that later fails
            // releases its share, and promoting the order to refunded on
            // reserved-but-unsettled money would leave a terminal order
            // with less returned than the status claims.
            $hasOpenSibling = Refund::query()
                ->where('payment_id', $refund->payment_id)
                ->whereIn('status', [RefundStatus::Pending, RefundStatus::Processing])
                ->exists();

            $exhausted = ! $hasOpenSibling && $payment->refunded_amount === $payment->amount;

            try {
                $exhausted
                    ? ($this->markRefunded)($payment->order_id)
                    : ($this->markPartiallyRefunded)($payment->order_id);
            } catch (InvalidOrderTransitionException) {
                // The order left the refund arc through another path (the
                // confirmed-after-hold-expiry compensation, an operator
                // action); the refund completion itself must still land.
                Log::warning('payments.refund_completed_without_order_transition', [
                    'refund_id' => $refundId,
                    'order_id' => $payment->order_id,
                ]);
            }

            ($this->markTicketsRefunded)($payment->order_id, $refund->ticket_ids, $refund->id);

            $this->outbox->record(RefundCompleted::fromRefund($refund->fresh(), $payment->order_id));

            return $refund->fresh();
        });
    }
}
