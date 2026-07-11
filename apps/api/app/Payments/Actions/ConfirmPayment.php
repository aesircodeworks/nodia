<?php

namespace App\Payments\Actions;

use App\Payments\Enums\PaymentStatus;
use App\Payments\Models\Payment;
use App\Payments\Support\CommissionResolver;
use App\Support\Money\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * initiated to confirmed (stage-08a plan, Data model "payments"): the
 * guard evaluates the window in the same statement that mutates the row,
 * so a payment past expires_at can never confirm, even before the
 * sweeper has run. Zero rows affected is a defined outcome, not an
 * error: a late or duplicate confirmation returns null and the caller
 * records it as ignored. The gateway fee and the resolved commission
 * are persisted by the winning statement itself, keeping the row
 * ledger-sufficient for Stage 8b.
 */
final class ConfirmPayment
{
    public function __construct(
        private readonly CommissionResolver $commission,
    ) {}

    public function __invoke(string $paymentId, Money $fee): ?Payment
    {
        $payment = Payment::query()->find($paymentId);

        if ($payment === null) {
            return null;
        }

        $commission = $this->commission->resolve($payment->money, $fee);

        $affected = DB::table('payments')
            ->where('id', $paymentId)
            ->where('status', PaymentStatus::Initiated->value)
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', Date::now()))
            ->update([
                'status' => PaymentStatus::Confirmed->value,
                'fee_amount' => $fee->amount,
                'commission_amount' => $commission->amount,
                'confirmed_at' => Date::now(),
                'updated_at' => Date::now(),
            ]);

        return $affected === 1 ? $payment->fresh() : null;
    }
}
