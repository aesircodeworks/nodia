<?php

namespace App\Payments\Http\Controllers;

use App\Orders\Actions\ResolveOrderForPayment;
use App\Payments\Actions\InitiatePayment;
use App\Payments\Data\InitiatePaymentData;
use App\Payments\Data\PaymentData;
use App\Payments\Exceptions\IdempotencyKeyMissingException;
use App\Payments\Exceptions\PaymentOrderNotFoundException;
use App\Support\Problems\ErrorCode;
use App\Support\Problems\ProblemData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The buyer payment surface (stage-08a plan, Endpoints). The 402
 * decline is rendered as a response, not an exception: the request
 * transaction must commit the failed payment row and its events while
 * the buyer keeps the pending order and live hold for a retry
 * (system-design 7.6).
 */
class PaymentController
{
    public function store(
        Request $request,
        string $order,
        InitiatePaymentData $data,
        ResolveOrderForPayment $resolveOrder,
        InitiatePayment $initiatePayment,
    ): JsonResponse {
        $idempotencyKey = (string) $request->headers->get('Idempotency-Key', '');

        if ($idempotencyKey === '') {
            throw IdempotencyKeyMissingException::make();
        }

        $customerId = (string) $request->user('customer')->getAuthIdentifier();

        $context = $resolveOrder($order, $customerId) ?? throw PaymentOrderNotFoundException::forOrder($order);

        $result = $initiatePayment($context, $data, $idempotencyKey);

        if ($result->declined) {
            return ProblemData::fromErrorCode(
                ErrorCode::PaymentDeclined,
                'The gateway declined this payment; the order and its hold remain intact for a retry.',
                $request->headers->get('X-Correlation-Id'),
            )->toProblemResponse();
        }

        return response()->json(PaymentData::fromModel($result->payment), $result->replayed ? 200 : 201);
    }
}
