<?php

namespace App\Payments\Actions;

use App\Orders\Actions\ResolveOrderRefundContext;
use App\Orders\Enums\OrderStatus;
use App\Payments\Data\CreateRefundData;
use App\Payments\Enums\PaymentStatus;
use App\Payments\Enums\RefundCommissionPolicy;
use App\Payments\Enums\RefundStatus;
use App\Payments\Events\RefundInitiated;
use App\Payments\Exceptions\IdempotencyKeyReuseMismatchException;
use App\Payments\Exceptions\PaymentNotRefundableException;
use App\Payments\Exceptions\RefundAmountExceedsRefundableException;
use App\Payments\Exceptions\RefundCurrencyMismatchException;
use App\Payments\Exceptions\RefundPaymentNotFoundException;
use App\Payments\Exceptions\RefundTicketsNotInOrderException;
use App\Payments\Models\Payment;
use App\Payments\Models\Refund;
use App\Payments\Support\RequestHash;
use App\Support\Money\Money;
use App\Support\Outbox\OutboxRecorder;
use App\Support\Tenancy\TenantContext;
use App\Tenancy\Actions\ResolveTenantCommissionConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * POST /v1/payments/{payment}/refunds (stage-08b plan, Endpoints;
 * system-design 7.5). The idempotency guarantee is the
 * (tenant_id, idempotency_key) unique constraint, mirroring
 * InitiatePayment: the replay lookup is an optimization and the
 * savepoint-isolated insert falls back to replay when a concurrent
 * creation wins. Reserving the refundable amount is one conditional
 * UPDATE incrementing refunded_amount and refunded_commission_amount
 * within their caps, checked by affected-row count; the commission
 * reservation is what keeps summed returned commission from exceeding
 * the commission actually charged regardless of later configuration
 * changes or rounding across partials. RefundInitiated rides the same
 * transaction as the insert and the reservation.
 */
final class CreateRefund
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ResolveOrderRefundContext $resolveOrder,
        private readonly ResolveTenantCommissionConfig $commissionConfig,
        private readonly OutboxRecorder $outbox,
    ) {}

    public function __invoke(string $paymentId, CreateRefundData $data, string $idempotencyKey): RefundCreationResult
    {
        $requestHash = RequestHash::compute('refund', [
            'amount' => $data->amount?->amount,
            'currency' => $data->amount?->currency,
            'reason' => $data->reason,
            'ticket_ids' => $data->ticketIds,
        ]);

        $existing = Refund::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $this->replay($existing, $requestHash, $paymentId);
        }

        $payment = Payment::query()->find($paymentId)
            ?? throw RefundPaymentNotFoundException::forPayment($paymentId);

        if ($payment->status !== PaymentStatus::Confirmed) {
            throw PaymentNotRefundableException::forPayment($paymentId);
        }

        $order = ($this->resolveOrder)($payment->order_id);

        if ($order === null || ! in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded], true)) {
            throw PaymentNotRefundableException::forPayment($paymentId);
        }

        $config = ($this->commissionConfig)((string) $this->tenantContext->tenantId());
        $policy = $config['refund_commission_policy'];

        $amount = $this->resolveAmount($payment, $data);
        $isFullRefund = $payment->refunded_amount + $amount->amount === $payment->amount;
        $ticketIds = $this->resolveTicketSelection($order->issuedTicketIds, $data->ticketIds, $order->id, $isFullRefund);
        $commission = $this->returnedCommission($payment, $amount, $policy);

        try {
            $refund = DB::transaction(function () use ($payment, $order, $amount, $commission, $ticketIds, $policy, $data, $idempotencyKey, $requestHash): Refund {
                $this->reserve($payment, $amount, $commission);

                $refund = Refund::query()->create([
                    'tenant_id' => (string) $this->tenantContext->tenantId(),
                    'payment_id' => $payment->id,
                    'money' => $amount,
                    'status' => RefundStatus::Pending,
                    'reason' => $data->reason,
                    'ticket_ids' => $ticketIds,
                    'commission_amount' => $commission->amount,
                    'commission_policy' => $policy,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                ]);

                $this->outbox->record(RefundInitiated::fromRefund($refund, $order->id));

                return $refund;
            });
        } catch (UniqueConstraintViolationException) {
            $existing = Refund::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();

            return $this->replay($existing, $requestHash, $paymentId);
        }

        return new RefundCreationResult($refund, $order->id, replayed: false);
    }

    private function resolveAmount(Payment $payment, CreateRefundData $data): Money
    {
        if ($data->amount === null) {
            $remaining = $payment->amount - $payment->refunded_amount;

            if ($remaining <= 0) {
                throw RefundAmountExceedsRefundableException::forPayment($payment->id);
            }

            return Money::of($remaining, $payment->currency);
        }

        if ($data->amount->currency !== $payment->currency) {
            throw RefundCurrencyMismatchException::forPayment($payment->id);
        }

        return $data->amount;
    }

    /**
     * A full refund voids every issued ticket regardless of the request
     * (stage-08b plan, Endpoints). A partial refund voids only the
     * explicitly requested tickets, so an absent selection persists an
     * empty list (void none), never null (which the completion path reads
     * as the full-refund void-all marker).
     *
     * @param  list<string>  $issuedTicketIds
     * @param  list<string>|null  $requested
     * @return list<string>|null
     */
    private function resolveTicketSelection(array $issuedTicketIds, ?array $requested, string $orderId, bool $isFullRefund): ?array
    {
        if ($requested !== null && array_diff($requested, $issuedTicketIds) !== []) {
            throw RefundTicketsNotInOrderException::forOrder($orderId);
        }

        if ($isFullRefund) {
            return null;
        }

        return $requested ?? [];
    }

    /**
     * Proportional to the refunded share of gross, round half up, capped
     * by the un-returned remainder; zero under the retained policy.
     */
    private function returnedCommission(Payment $payment, Money $amount, RefundCommissionPolicy $policy): Money
    {
        if ($policy === RefundCommissionPolicy::Retained) {
            return Money::of(0, $payment->currency);
        }

        $scaled = $payment->commission_amount * $amount->amount;
        $proportional = intdiv($scaled, $payment->amount);

        if (($scaled % $payment->amount) * 2 >= $payment->amount) {
            $proportional++;
        }

        $remainder = $payment->commission_amount - $payment->refunded_commission_amount;

        return Money::of(min($proportional, max($remainder, 0)), $payment->currency);
    }

    private function reserve(Payment $payment, Money $amount, Money $commission): void
    {
        $affected = DB::table('payments')
            ->where('id', $payment->id)
            ->where('status', PaymentStatus::Confirmed->value)
            ->whereRaw('refunded_amount + ? <= amount', [$amount->amount])
            ->whereRaw('refunded_commission_amount + ? <= commission_amount', [$commission->amount])
            ->update([
                'refunded_amount' => DB::raw('refunded_amount + '.$amount->amount),
                'refunded_commission_amount' => DB::raw('refunded_commission_amount + '.$commission->amount),
                'updated_at' => now(),
            ]);

        if ($affected === 1) {
            return;
        }

        $fresh = Payment::query()->findOrFail($payment->id);

        if ($fresh->status !== PaymentStatus::Confirmed) {
            throw PaymentNotRefundableException::forPayment($payment->id);
        }

        throw RefundAmountExceedsRefundableException::forPayment($payment->id);
    }

    private function replay(Refund $existing, string $requestHash, string $paymentId): RefundCreationResult
    {
        // A key reused against a different payment is a mismatch even
        // with an identical payload; replaying would hand back another
        // payment's refund (the InitiatePayment round-3 precedent).
        if ($existing->payment_id !== $paymentId || ! hash_equals($existing->request_hash, $requestHash)) {
            throw IdempotencyKeyReuseMismatchException::make();
        }

        $orderId = Payment::query()->findOrFail($existing->payment_id)->order_id;

        return new RefundCreationResult($existing, $orderId, replayed: true);
    }
}
