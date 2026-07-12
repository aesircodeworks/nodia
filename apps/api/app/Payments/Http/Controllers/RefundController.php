<?php

namespace App\Payments\Http\Controllers;

use App\Payments\Actions\CreateRefund;
use App\Payments\Data\CreateRefundData;
use App\Payments\Data\RefundData;
use App\Payments\Exceptions\IdempotencyKeyMissingException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The staff refund surface (stage-08b plan, Endpoints): creation is
 * capability-gated on orders.refund, MFA-enforced by the admin group,
 * and activity-logged by RecordActivityAudit. Execution is
 * asynchronous; the 201 carries a pending refund.
 */
class RefundController
{
    public function store(
        Request $request,
        string $payment,
        CreateRefundData $data,
        CreateRefund $createRefund,
    ): JsonResponse {
        $idempotencyKey = (string) $request->headers->get('Idempotency-Key', '');

        if ($idempotencyKey === '') {
            throw IdempotencyKeyMissingException::make();
        }

        $result = $createRefund($payment, $data, $idempotencyKey);

        return response()->json(
            RefundData::fromModel($result->refund, $result->orderId),
            $result->replayed ? 200 : 201,
        );
    }
}
