<?php

namespace App\Payments\Actions;

use App\Payments\Enums\PaymentStatus;
use App\Payments\Events\PaymentExpired;
use App\Payments\Models\Payment;
use App\Support\Outbox\OutboxRecorder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * initiated to expired (stage-08a plan, Data model "payments"), applied
 * by the sweeper and by conversion-time validation. Zero rows affected
 * returns null: racing a confirming webhook resolves to exactly one
 * terminal status. The expiry window is evaluated inside the mutating
 * statement, mirroring ConfirmPayment, so a caller holding a still-open
 * initiated payment cannot terminalize it early.
 */
final class ExpirePayment
{
    public function __construct(
        private readonly OutboxRecorder $outbox,
    ) {}

    public function __invoke(string $paymentId): ?Payment
    {
        $affected = DB::table('payments')
            ->where('id', $paymentId)
            ->where('status', PaymentStatus::Initiated->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', Date::now())
            ->update([
                'status' => PaymentStatus::Expired->value,
                'failure_code' => 'payment_window_expired',
                'failed_at' => Date::now(),
                'updated_at' => Date::now(),
            ]);

        if ($affected !== 1) {
            return null;
        }

        $payment = Payment::query()->findOrFail($paymentId);

        $this->outbox->record(PaymentExpired::fromPayment($payment));

        return $payment;
    }
}
