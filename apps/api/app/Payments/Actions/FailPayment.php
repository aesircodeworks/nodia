<?php

namespace App\Payments\Actions;

use App\Payments\Enums\PaymentStatus;
use App\Payments\Events\PaymentFailed;
use App\Payments\Models\Payment;
use App\Support\Outbox\OutboxRecorder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * initiated to failed (stage-08a plan, Data model "payments"). Zero rows
 * affected returns null: a duplicate or late failure webhook is inert.
 */
final class FailPayment
{
    public function __construct(
        private readonly OutboxRecorder $outbox,
    ) {}

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

        if ($affected !== 1) {
            return null;
        }

        $payment = Payment::query()->findOrFail($paymentId);

        $this->outbox->record(PaymentFailed::fromPayment($payment));

        return $payment;
    }
}
