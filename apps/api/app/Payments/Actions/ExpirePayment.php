<?php

namespace App\Payments\Actions;

use App\Payments\Enums\PaymentStatus;
use App\Payments\Models\Payment;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * initiated to expired (stage-08a plan, Data model "payments"), applied
 * by the sweeper and by conversion-time validation. Zero rows affected
 * returns null: racing a confirming webhook resolves to exactly one
 * terminal status.
 */
final class ExpirePayment
{
    public function __invoke(string $paymentId): ?Payment
    {
        $affected = DB::table('payments')
            ->where('id', $paymentId)
            ->where('status', PaymentStatus::Initiated->value)
            ->update([
                'status' => PaymentStatus::Expired->value,
                'failure_code' => 'payment_window_expired',
                'failed_at' => Date::now(),
                'updated_at' => Date::now(),
            ]);

        return $affected === 1 ? Payment::query()->findOrFail($paymentId) : null;
    }
}
