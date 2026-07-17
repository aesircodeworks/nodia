<?php

namespace App\Payments\Actions;

use App\Payments\Enums\RefundStatus;
use App\Payments\Models\Refund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * processing to failed (stage-08b plan, Data model "refunds"): the
 * conditional transition is what releases the refunded_amount and
 * refunded_commission_amount reservations exactly once, because the
 * compensating decrement only runs when this statement affected the
 * row. Duplicate failure webhooks and sweeper races affect zero rows
 * and release nothing. Returns null on zero rows, mirroring
 * FailPayment. The release itself floors both counters in the WHERE so
 * corrupted data surfaces as a critical log instead of a CHECK
 * violation stranding the refund in processing.
 */
final class FailRefund
{
    public function __invoke(string $refundId, string $failureCode): ?Refund
    {
        return DB::transaction(function () use ($refundId, $failureCode): ?Refund {
            $refund = Refund::query()->find($refundId);

            if ($refund === null) {
                return null;
            }

            $affected = DB::table('refunds')
                ->where('id', $refundId)
                ->where('status', RefundStatus::Processing->value)
                ->update([
                    'status' => RefundStatus::Failed->value,
                    'failure_code' => $failureCode,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                return null;
            }

            $released = DB::table('payments')
                ->where('id', $refund->payment_id)
                ->where('refunded_amount', '>=', $refund->amount)
                ->where('refunded_commission_amount', '>=', $refund->commission_amount)
                ->update([
                    'refunded_amount' => DB::raw('refunded_amount - '.$refund->amount),
                    'refunded_commission_amount' => DB::raw('refunded_commission_amount - '.$refund->commission_amount),
                    'updated_at' => now(),
                ]);

            if ($released !== 1) {
                Log::critical('payments.refund_reservation_release_failed', [
                    'refund_id' => $refund->id,
                    'payment_id' => $refund->payment_id,
                    'amount' => $refund->amount,
                    'commission_amount' => $refund->commission_amount,
                    'action_required' => 'reconcile refunded_amount reservations for this payment',
                ]);
            }

            return $refund->fresh();
        });
    }
}
