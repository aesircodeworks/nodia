<?php

namespace App\Payments\Http\Controllers;

use App\Orders\Actions\ResolveOrderForPayment;
use App\Payments\Actions\InitiatePayment;
use App\Payments\Actions\PaymentInitiationResult;
use App\Payments\Data\InitiatePaymentData;
use App\Payments\Data\PaymentData;
use App\Payments\Exceptions\IdempotencyKeyMissingException;
use App\Payments\Exceptions\PaymentOrderNotFoundException;
use App\Payments\Models\Payment;
use App\Support\Audit\ActivityLogger;
use App\Support\Problems\ErrorCode;
use App\Support\Problems\ProblemData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The buyer payment surface (stage-08a plan, Endpoints). The 402
 * decline is rendered as a response, not an exception: the request
 * transaction must commit the failed payment row and its events while
 * the buyer keeps the pending order and live hold for a retry
 * (system-design 7.6).
 *
 * Initiating a payment is a financial mutation, so it carries an
 * append-only activity-log entry (system-design 14.2, "all financial
 * mutations"). It is recorded here rather than by App\Http\Middleware\
 * RecordActivityAudit on the route for the same reason the decline is a
 * response rather than an exception: that middleware only records
 * successful responses, so the committed-but-402 failed payment, the one
 * outcome an audit trail most needs, would be the one it omitted. A
 * replay records nothing new; the original initiation is already logged.
 */
class PaymentController
{
    public function store(
        Request $request,
        string $order,
        InitiatePaymentData $data,
        ResolveOrderForPayment $resolveOrder,
        InitiatePayment $initiatePayment,
        ActivityLogger $activityLogger,
    ): JsonResponse {
        $idempotencyKey = (string) $request->headers->get('Idempotency-Key', '');

        if ($idempotencyKey === '') {
            throw IdempotencyKeyMissingException::make();
        }

        $customerId = (string) $request->user('customer')->getAuthIdentifier();

        $context = $resolveOrder($order, $customerId) ?? throw PaymentOrderNotFoundException::forOrder($order);

        $result = $initiatePayment($context, $data, $idempotencyKey);

        $this->audit($result, $request, $activityLogger);

        if ($result->orderExpired) {
            return ProblemData::fromErrorCode(
                ErrorCode::PaymentConfirmedAfterHoldExpired,
                'The gateway confirmed this payment after the order hold had already expired; the order is expired and the payment is flagged for refund.',
                $request->headers->get('X-Correlation-Id'),
            )->toProblemResponse();
        }

        if ($result->declined) {
            return ProblemData::fromErrorCode(
                ErrorCode::PaymentDeclined,
                'The gateway declined this payment; the order and its hold remain intact for a retry.',
                $request->headers->get('X-Correlation-Id'),
            )->toProblemResponse();
        }

        return response()->json(PaymentData::fromModel($result->payment), $result->replayed ? 200 : 201);
    }

    private function audit(PaymentInitiationResult $result, Request $request, ActivityLogger $activityLogger): void
    {
        if ($result->replayed) {
            return;
        }

        $causer = $request->user('customer');

        $activityLogger->record(
            description: sprintf('%s /%s', $request->getMethod(), ltrim($request->path(), '/')),
            causer: $causer instanceof Model ? $causer : null,
            event: 'mutation',
            properties: [
                'payment_id' => $result->payment->id,
                'order_id' => $result->payment->order_id,
                'gateway' => $result->payment->gateway,
                'status' => $result->payment->status->value,
            ],
        );
    }

    /**
     * The buyer polls the payment leg of an async initiation; the order
     * status flip is polled on the Stage 7 order endpoint. Ownership is
     * asserted through the owning order: a missing, cross-tenant (via
     * RLS), or foreign-customer payment renders the same
     * request.not_found (stage-08a plan, Endpoints).
     */
    public function show(Request $request, string $payment, ResolveOrderForPayment $resolveOrder): PaymentData
    {
        $model = Payment::query()->find($payment) ?? throw PaymentOrderNotFoundException::forOrder($payment);

        $customerId = (string) $request->user('customer')->getAuthIdentifier();

        if ($resolveOrder($model->order_id, $customerId) === null) {
            throw PaymentOrderNotFoundException::forOrder($payment);
        }

        return PaymentData::fromModel($model);
    }
}
