<?php

namespace App\Orders\Http\Controllers;

use App\Orders\Actions\CancelOrder;
use App\Orders\Actions\ConvertHoldToOrder;
use App\Orders\Data\CreateOrderData;
use App\Orders\Data\OrderData;
use App\Orders\Data\TicketData;
use App\Orders\Exceptions\OrderNotFoundException;
use App\Orders\Models\Order;
use App\Orders\Models\Ticket;
use App\Orders\Support\TicketQrCodec;
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

    public function show(Request $request, string $order): OrderData
    {
        return OrderData::fromModel($this->ownOrderOrFail($request, $order)->load('items'));
    }

    /**
     * qr_payload is computed on render, never stored (system-design 8.3
     * notes); the list is empty until the paid transition issues the
     * tickets (stage-07 plan, Endpoints).
     */
    public function tickets(Request $request, string $order, TicketQrCodec $codec): JsonResponse
    {
        $model = $this->ownOrderOrFail($request, $order);

        $tickets = Ticket::query()
            ->where('order_id', $model->id)
            ->orderBy('id')
            ->get()
            ->map(fn (Ticket $ticket): TicketData => TicketData::fromModel($ticket, $codec->sign($ticket)))
            ->all();

        return response()->json(['data' => $tickets]);
    }

    /**
     * Explicit 200: laravel-data's Responsable defaults every POST to
     * 201, but nothing is created here (stage-07 plan, Endpoints).
     */
    public function cancel(Request $request, string $order, CancelOrder $cancelOrder): JsonResponse
    {
        return response()->json($cancelOrder($this->ownOrderOrFail($request, $order)->id));
    }

    /**
     * Customers see only their own orders; a missing, cross-tenant (via
     * RLS), or foreign-customer order id renders the same
     * order_not_found problem (stage-07 plan, Endpoints).
     */
    private function ownOrderOrFail(Request $request, string $orderId): Order
    {
        $customerId = (string) $request->user('customer')->getAuthIdentifier();

        return Order::query()
            ->whereKey($orderId)
            ->where('customer_id', $customerId)
            ->first() ?? throw OrderNotFoundException::forId($orderId);
    }
}
