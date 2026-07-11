<?php

namespace App\Payments\Http\Controllers;

use App\Orders\Actions\ResolveOrderForPayment;
use App\Orders\Enums\OrderStatus;
use App\Payments\Actions\BuildPaymentMethodOffer;
use App\Payments\Exceptions\OrderNotPayableException;
use App\Payments\Exceptions\PaymentOrderNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /v1/storefront/orders/{order}/payment-methods (stage-08a plan,
 * Endpoints). Customer bearer token required; the order must belong to
 * the caller and be pending.
 */
class PaymentMethodOfferController
{
    public function __invoke(
        Request $request,
        string $order,
        ResolveOrderForPayment $resolveOrder,
        BuildPaymentMethodOffer $buildOffer,
    ): JsonResponse {
        $customerId = (string) $request->user('customer')->getAuthIdentifier();

        $context = $resolveOrder($order, $customerId) ?? throw PaymentOrderNotFoundException::forOrder($order);

        if ($context->status !== OrderStatus::Pending) {
            throw OrderNotPayableException::forOrder($order);
        }

        return response()->json(['data' => $buildOffer($context)]);
    }
}
