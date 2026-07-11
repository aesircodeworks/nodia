<?php

namespace App\Payments\Actions;

use App\Payments\Enums\PaymentStatus;
use App\Payments\Models\Payment;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * initiated to failed (stage-08a plan, Data model "payments"). Zero rows
 * affected returns null: a duplicate or late failure webhook is inert.
 */
final class FailPayment
{
    public function __invoke(string $paymentId, string $failureCode): ?Payment
    {
        $affected = DB::table('payments')
            ->where('id', $paymentId)
            ->where('status', PaymentStatus::Initiated->value)
            ->update([
                'status' => PaymentStatus::Failed->value,
                'failure_code' => $failureCode,
                'failed_at' => Date::now(),
                'updated_at' => Date::now(),
            ]);

        return $affected === 1 ? Payment::query()->findOrFail($paymentId) : null;
    }
}
