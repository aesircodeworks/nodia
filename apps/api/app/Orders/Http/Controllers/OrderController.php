<?php

namespace App\Orders\Http\Controllers;

use App\Orders\Actions\ConvertHoldToOrder;
use App\Orders\Data\CreateOrderData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The storefront order surface (stage-07 plan, Endpoints
 * "Buyer-facing"). Unlike holds, every order endpoint requires a
 * customer bearer token: every order has a customer from creation.
 */
class OrderController
{
    public function store(Request $request, CreateOrderData $data, ConvertHoldToOrder $convertHoldToOrder): JsonResponse
    {
        $customerId = (string) $request->user('customer')->getAuthIdentifier();

        return response()->json($convertHoldToOrder($data, $customerId), 201);
    }
}
